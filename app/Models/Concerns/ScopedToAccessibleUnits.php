<?php

namespace App\Models\Concerns;

use App\Models\Scopes\AccessibleUnitsScope;

trait ScopedToAccessibleUnits
{
    public static function bootScopedToAccessibleUnits(): void
    {
        static::addGlobalScope(new AccessibleUnitsScope);
    }

    /** @return list<string> colunas que ligam o registro a uma unidade consumidora */
    public function accessibleUnitColumns(): array
    {
        return ['consumer_unit_id'];
    }

    /** Unidade do registro, para checar permissão de edição/visualização. */
    public function accessibleUnitId(): ?int
    {
        return $this->consumer_unit_id;
    }
}
