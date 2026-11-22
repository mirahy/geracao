<?php

namespace App\Http\Middleware;

use App\Services\ActivityLog\ActivityLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Registra as telas e arquivos consultados pelo app mobile (somente GET bem-sucedido). */
class LogApiView
{
    /** Rota => [rótulo, é download?]. Rotas de apoio (meses, perfil, lista de unidades, logs) não entram. */
    private const ROUTES = [
        'api/v1/dashboard' => ['Painel', false],
        'api/v1/invoices' => ['Faturas', false],
        'api/v1/invoices/{invoice}/file' => ['o PDF da fatura', true],
        'api/v1/generations' => ['Geração das placas', false],
        'api/v1/generations/pending' => ['Importação de geração pendente', false],
        'api/v1/rateio/summary' => ['Situação do rateio', false],
        'api/v1/rateio/payments' => ['Pagamentos do rateio', false],
        'api/v1/rateio/payments/{payment}/receipt' => ['o comprovante do pagamento', true],
        'api/v1/documents' => ['Importações', false],
        'api/v1/units/{unit}' => ['Unidade', false],
        'api/v1/admin/users' => ['Usuários', false],
        'api/v1/admin/users/{user}' => ['Usuário', false],
        'api/v1/admin/roles' => ['Perfis e permissões', false],
        'api/v1/admin/unit-requests' => ['Solicitações de acesso', false],
        'api/v1/admin/installments' => ['Parcelas das placas', false],
    ];

    private const FILTERS = ['unit_id', 'from', 'to', 'status', 'month'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->getStatusCode() < 400 && $request->user() && $route = $request->route()) {
            [$label, $download] = self::ROUTES[$route->uri()] ?? [null, false];

            if ($label) {
                $subject = collect($route->parameters())->first(fn ($value) => $value instanceof Model);
                ActivityLogger::viewed(
                    ($download ? 'Baixou ' : 'Visualizou ').$label,
                    $subject,
                    $request->only(self::FILTERS),
                );
            }
        }

        return $response;
    }
}
