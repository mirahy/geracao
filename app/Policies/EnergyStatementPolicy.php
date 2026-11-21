<?php

namespace App\Policies;

class EnergyStatementPolicy extends UnitScopedPolicy
{
    protected function prefix(): string
    {
        return 'demonstrativos';
    }

    protected function readOnly(): bool
    {
        return true;
    }
}
