<?php

namespace App\Http\Controllers;

use App\Models\RateioPayment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RateioPaymentFileController extends Controller
{
    /** Exibe o comprovante anexado ao pagamento (somente quem pode ver pagamentos da unidade). */
    public function __invoke(RateioPayment $payment): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user->can('pagamentos.ver') && $user->canViewUnit($payment->consumer_unit_id), 403);
        abort_unless($payment->hasFile() && Storage::disk('local')->exists($payment->file_path), 404, 'Comprovante não disponível.');

        $name = $payment->file_name ?: basename($payment->file_path);

        return Storage::disk('local')->response($payment->file_path, $name, [
            'Content-Type' => Storage::disk('local')->mimeType($payment->file_path) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $name).'"',
        ]);
    }
}
