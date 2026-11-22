<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsumerUnit;
use App\Models\RateioPayment;
use App\Services\Rateio\PaymentReceiptReader;
use App\Services\Rateio\RateioLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Pagamentos do rateio das unidades beneficiárias: saldo por mês, lançamentos e comprovantes. */
class RateioPaymentController extends Controller
{
    private const MONTH_RULE = ['regex:/^\d{4}-\d{2}(-\d{2})?$/'];

    /** Rateio devido × pago por unidade e mês (somente das unidades que o usuário vê). */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('pagamentos.ver'), 403);

        $filters = $request->validate([
            'unit_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:'.implode(',', [RateioLedger::PENDING, RateioLedger::PARTIAL, RateioLedger::PAID, RateioLedger::OVERPAID, RateioLedger::WITHOUT_CHARGE])],
        ]);

        $rows = app(RateioLedger::class)->rows($user->viewableUnitIds())
            ->when($filters['unit_id'] ?? null, fn ($rows, $id) => $rows->where('unit_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($rows, $status) => $rows->where('status', $status))
            ->take(120)
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('pagamentos.ver'), 403);

        $filters = $request->validate([
            'unit_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $payments = RateioPayment::with('consumerUnit')
            ->when($filters['unit_id'] ?? null, fn ($q, $id) => $q->where('consumer_unit_id', $id))
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->where('reference_month', $month.'-01'))
            ->orderByDesc('reference_month')->orderByDesc('paid_at')->orderByDesc('id')
            ->limit(120)
            ->get()
            ->map(fn (RateioPayment $p) => $this->present($p))
            ->values();

        return response()->json(['data' => $payments]);
    }

    /**
     * Lança um pagamento. Com o comprovante (PDF ou imagem), o que faltar — valor, data, pagador e
     * código da transação — é preenchido pela leitura do arquivo.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('create', RateioPayment::class), 403);

        $data = $request->validate([
            'consumer_unit_id' => ['required', 'integer', 'exists:consumer_units,id'],
            'reference_month' => ['required', 'string', ...self::MONTH_RULE],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'payer_name' => ['nullable', 'string', 'max:255'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'max:5120', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp'],
        ]);

        $unitId = (int) $data['consumer_unit_id'];
        abort_unless($user->canEditUnit($unitId), 403);
        if (ConsumerUnit::findOrFail($unitId)->role !== ConsumerUnit::ROLE_RECEIVER) {
            throw ValidationException::withMessages(['consumer_unit_id' => ['A UC informada não é beneficiária.']]);
        }

        $receipt = $request->file('receipt');
        if ($receipt) {
            $read = app(PaymentReceiptReader::class)->read($receipt->getRealPath()) ?? [];
            $data['amount'] ??= $read['amount'] ?? null;
            $data['paid_at'] ??= $read['paid_at'] ?? null;
            $data['payer_name'] ??= $read['payer_name'] ?? null;
            $data['transaction_id'] ??= $read['transaction_id'] ?? null;
        }

        if (empty($data['amount'])) {
            throw ValidationException::withMessages(['amount' => [$receipt ? 'Não foi possível ler o valor no comprovante. Informe o valor.' : 'Informe o valor pago.']]);
        }
        if (! empty($data['transaction_id']) && RateioPayment::where('transaction_id', $data['transaction_id'])->exists()) {
            throw ValidationException::withMessages(['transaction_id' => ['Este comprovante já foi lançado.']]);
        }

        $payment = RateioPayment::create([
            'consumer_unit_id' => $unitId,
            'reference_month' => substr($data['reference_month'], 0, 7).'-01',
            'amount' => $data['amount'],
            'paid_at' => $data['paid_at'] ?? now(),
            'payer_name' => $data['payer_name'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'file_path' => $receipt?->store('rateio-comprovantes', 'local'),
            'file_name' => $receipt?->getClientOriginalName(),
            'source' => $receipt ? RateioPayment::SOURCE_RECEIPT : RateioPayment::SOURCE_MANUAL,
            'created_by' => $user->id,
        ]);

        return response()->json(['data' => $this->present($payment->load('consumerUnit'))], 201);
    }

    public function destroy(Request $request, RateioPayment $payment): JsonResponse
    {
        abort_unless($request->user()->can('delete', $payment), 403);

        $payment->delete();

        return response()->json(null, 204);
    }

    public function receipt(Request $request, RateioPayment $payment): StreamedResponse
    {
        abort_unless($request->user()->can('view', $payment), 403);
        abort_unless($payment->hasFile() && Storage::disk('local')->exists($payment->file_path), 404, 'Comprovante não disponível.');

        $name = $payment->file_name ?: basename($payment->file_path);

        return Storage::disk('local')->response($payment->file_path, $name, [
            'Content-Type' => Storage::disk('local')->mimeType($payment->file_path) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $name).'"',
        ]);
    }

    /** Lê um comprovante sem lançar o pagamento, para o app mostrar os dados e o usuário confirmar. */
    public function readReceipt(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('create', RateioPayment::class), 403);

        $request->validate(['receipt' => ['required', 'file', 'max:5120', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp']]);

        $reader = app(PaymentReceiptReader::class);
        $path = $request->file('receipt')->getRealPath();
        $read = $reader->read($path);

        $suggestedUnit = $read && $read['payer_name']
            ? RateioPayment::where('payer_name', $read['payer_name'])->latest('id')->value('consumer_unit_id')
            : null;

        return response()->json(['data' => [
            'readable' => $read !== null,
            'ocr_enabled' => $reader->ocrEnabled(),
            'amount' => $read['amount'] ?? null,
            'paid_at' => ($read['paid_at'] ?? null)?->toIso8601String(),
            'payer_name' => $read['payer_name'] ?? null,
            'transaction_id' => $read['transaction_id'] ?? null,
            'already_registered' => ! empty($read['transaction_id']) && RateioPayment::where('transaction_id', $read['transaction_id'])->exists(),
            'suggested_unit_id' => $suggestedUnit,
            'suggested_month' => $suggestedUnit ? app(RateioLedger::class)->oldestOpenMonth((int) $suggestedUnit) : null,
        ]]);
    }

    /** @return array<string, mixed> */
    private function present(RateioPayment $p): array
    {
        return [
            'id' => $p->id,
            'consumer_unit_id' => $p->consumer_unit_id,
            'unit_name' => $p->consumerUnit?->name,
            'reference_month' => $p->reference_month->format('Y-m-d'),
            'amount' => $p->amount,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'payer_name' => $p->payer_name,
            'transaction_id' => $p->transaction_id,
            'source' => $p->source,
            'notes' => $p->notes,
            'has_receipt' => $p->hasFile(),
            'receipt_url' => $p->hasFile() ? url("/api/v1/rateio/payments/{$p->id}/receipt") : null,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
