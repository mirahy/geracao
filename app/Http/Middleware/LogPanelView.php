<?php

namespace App\Http\Middleware;

use App\Services\ActivityLog\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Registra as páginas do painel abertas pelo usuário (GET de página; o Livewire e os assets ficam de fora). */
class LogPanelView
{
    /** Primeiro segmento da URL => nome da tela. */
    private const PAGES = [
        'consumer-units' => 'Unidades consumidoras',
        'invoices' => 'Faturas',
        'energy-statements' => 'Demonstrativos de compensação',
        'imported-documents' => 'Importações',
        'solar-generations' => 'Geração das placas',
        'solar-installments' => 'Parcelas das placas',
        'rateio-payments' => 'Pagamentos do rateio',
        'users' => 'Usuários',
        'roles' => 'Perfis e permissões',
        'unit-requests' => 'Solicitações de acesso',
        'activity-logs' => 'Registro de atividades',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->getStatusCode() === 200 && $request->user() && ! $request->expectsJson() && ! $request->is('livewire*', 'filament*')) {
            $segments = $request->segments();
            $first = $segments[0] ?? null;
            $isFile = in_array('file', $segments, true);

            $description = match (true) {
                $first === null => 'Abriu o Painel',
                $isFile => 'Visualizou o arquivo original ('.($first === 'documents' ? 'importação' : 'comprovante').')',
                default => 'Abriu '.(self::PAGES[$first] ?? $request->path()),
            };

            ActivityLogger::viewed($description, null, ['path' => '/'.$request->path()]);
        }

        return $response;
    }
}
