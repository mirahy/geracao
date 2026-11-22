<?php

namespace App\Services\ActivityLog;

use App\Models\ActivityLog;
use App\Models\ConsumerUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Camada fina sobre o spatie/laravel-activitylog: acessos, visualizações e mudanças de perfis/permissões. */
class ActivityLogger
{
    /** Acesso: login, login recusado ou logout. */
    public static function access(string $event, string $description, ?User $user = null, array $properties = []): void
    {
        self::log(ActivityLog::TYPE_ACCESS, $event, $description, $user, null, $properties);
    }

    /** Visualização repetida (mesmo usuário e mesma tela) em até 30 segundos conta uma vez só. */
    public static function viewed(string $description, ?Model $subject = null, array $properties = []): void
    {
        $user = Auth::user();
        $key = 'activity-log:view:'.($user?->getAuthIdentifier() ?? 'guest').':'.md5($description.json_encode($properties).($subject?->getKey() ?? ''));

        if (! Cache::add($key, true, 30)) {
            return;
        }

        self::log(ActivityLog::TYPE_VIEW, 'view', $description, null, $subject, $properties);
    }

    /** Alteração que não passa pelo trait dos models (ex.: perfis do Spatie Permission). */
    public static function changed(string $event, string $description, Model $subject, array $properties = []): void
    {
        self::log(ActivityLog::TYPE_CHANGE, $event, $description, null, $subject, $properties);
    }

    /**
     * Mudança nos perfis de um usuário ou nas permissões de um perfil, pelo que de fato entrou e saiu.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public static function membershipChanged(Model $holder, string $what, array $before, array $after): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if ($added === [] && $removed === []) {
            return;
        }

        $who = $holder instanceof User ? "do usuário {$holder->name}" : 'do perfil '.self::label($holder);
        $parts = [...array_map(fn ($n) => "+ {$n}", $added), ...array_map(fn ($n) => "- {$n}", $removed)];

        self::changed('updated', "{$what} {$who}: ".implode(', ', $parts), $holder, ['added' => $added, 'removed' => $removed]);
    }

    /** Texto que identifica o registro (nome, arquivo ou usuário/unidade + mês). */
    public static function label(Model $model): string
    {
        foreach (['name', 'original_name'] as $attribute) {
            if (filled($model->getAttribute($attribute))) {
                return (string) $model->getAttribute($attribute);
            }
        }

        $parts = [];
        if ($model->getAttribute('user_id') && $user = User::find($model->getAttribute('user_id'))) {
            $parts[] = $user->name;
        }
        if ($model->getAttribute('consumer_unit_id') && $unit = ConsumerUnit::withoutGlobalScopes()->find($model->getAttribute('consumer_unit_id'))) {
            $parts[] = $unit->name;
        }
        foreach (['reference_month', 'start_month'] as $attribute) {
            $value = $model->getAttribute($attribute);
            if ($value) {
                $parts[] = ($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value))->format('m/Y');
                break;
            }
        }

        return $parts ? implode(' · ', $parts) : '#'.$model->getKey();
    }

    /** Nunca interrompe a operação: falhas ao gravar só são reportadas. */
    private static function log(string $type, string $event, string $description, ?User $causer, ?Model $subject, array $properties): void
    {
        try {
            $logger = activity($type)->event($event);

            if ($causer) {
                $logger->causedBy($causer);
            }
            if ($subject) {
                $logger->performedOn($subject);
            }

            $logger->withProperties($properties)->log(mb_substr($description, 0, 500));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
