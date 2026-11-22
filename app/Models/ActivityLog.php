<?php

namespace App\Models;

use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Registro de atividade (spatie/laravel-activitylog) com o contexto da requisição:
 * origem (app, painel ou sistema), IP e navegador. O tipo fica em "log_name".
 *
 * @property string|null $causer_name
 * @property string|null $channel
 * @property string|null $ip
 * @property string|null $user_agent
 */
class ActivityLog extends Activity
{
    public const TYPE_ACCESS = 'access';

    public const TYPE_VIEW = 'view';

    public const TYPE_CHANGE = 'change';

    public const TYPE_LABELS = [
        self::TYPE_ACCESS => 'Acesso',
        self::TYPE_VIEW => 'Visualização',
        self::TYPE_CHANGE => 'Alteração',
    ];

    public const TYPE_COLORS = [
        self::TYPE_ACCESS => 'info',
        self::TYPE_VIEW => 'gray',
        self::TYPE_CHANGE => 'warning',
    ];

    public const CHANNEL_LABELS = ['api' => 'App', 'panel' => 'Painel', 'console' => 'Sistema'];

    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log): void {
            $request = self::httpRequest();

            $log->channel ??= $request === null ? 'console' : ($request->is('api/*') ? 'api' : 'panel');
            $log->ip ??= $request?->ip();
            $log->user_agent ??= $request ? mb_substr((string) $request->userAgent(), 0, 255) : null;
            $log->causer_name ??= $log->causer?->name;
        });
    }

    /** A requisição HTTP em andamento (null em comandos e seeders). */
    private static function httpRequest(): ?Request
    {
        $request = request();

        return $request->route() !== null || ! app()->runningInConsole() ? $request : null;
    }
}
