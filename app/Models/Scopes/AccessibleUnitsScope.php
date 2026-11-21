<?php

namespace App\Models\Scopes;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Restringe as consultas às unidades consumidoras que o usuário autenticado pode ver.
 * Sem usuário autenticado (console, filas, importação por linha de comando) não filtra.
 */
class AccessibleUnitsScope implements Scope
{
    private static int $disabled = 0;

    /** Executa o callback sem o filtro (importação de documentos e cálculos globais). */
    public static function withoutScope(Closure $callback): mixed
    {
        self::$disabled++;

        try {
            return $callback();
        } finally {
            self::$disabled--;
        }
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (self::$disabled > 0) {
            return;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $ids = $user->viewableUnitIds();
        if ($ids === null) {
            return;
        }

        $columns = $model->accessibleUnitColumns();
        $table = $model->getTable();

        $builder->where(function (Builder $query) use ($columns, $table, $ids): void {
            foreach ($columns as $column) {
                $query->orWhereIn("{$table}.{$column}", $ids);
            }
        });
    }
}
