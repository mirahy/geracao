<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Services\ActivityLog\ActivityLogger;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Registra criação, alteração (antes/depois) e exclusão do model no registro de atividades
 * (spatie/laravel-activitylog). O model define ACTIVITY_NAME (ex.: "unidade") para o texto do registro.
 */
trait RecordsActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['password', 'remember_token', 'updated_at', 'created_at', 'parsed', 'hash', 'token'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(ActivityLog::TYPE_CHANGE)
            ->setDescriptionForEvent(fn (string $event) => [
                'created' => 'Criou',
                'updated' => 'Alterou',
                'deleted' => 'Excluiu',
            ][$event].' '.(defined(static::class.'::ACTIVITY_NAME') ? static::ACTIVITY_NAME : class_basename($this)).': '.ActivityLogger::label($this));
    }
}
