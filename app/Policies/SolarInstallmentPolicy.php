<?php

namespace App\Policies;

class SolarInstallmentPolicy extends UnitScopedPolicy
{
    protected function prefix(): string
    {
        return 'parcelas';
    }
}
