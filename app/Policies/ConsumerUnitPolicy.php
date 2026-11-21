<?php

namespace App\Policies;

class ConsumerUnitPolicy extends UnitScopedPolicy
{
    protected function prefix(): string
    {
        return 'unidades';
    }
}
