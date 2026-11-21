<?php

namespace App\Support;

use App\Models\ConsumerUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** Unidades oferecidas nos formulários de edição, respeitando o acesso do usuário. */
class UnitOptions
{
    /** Unidades geradoras que o usuário autenticado pode editar. */
    public static function editableGenerators(): Builder
    {
        $user = Auth::user();
        $ids = $user instanceof User ? $user->editableUnitIds() : null;

        return ConsumerUnit::query()
            ->where('role', ConsumerUnit::ROLE_GENERATOR)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->orderBy('name');
    }

    /** @return array<int, string> */
    public static function editableGeneratorOptions(): array
    {
        return self::editableGenerators()->pluck('name', 'id')->all();
    }
}
