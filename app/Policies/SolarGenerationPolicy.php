<?php

namespace App\Policies;

class SolarGenerationPolicy extends UnitScopedPolicy
{
    protected function prefix(): string
    {
        return 'geracao';
    }
}
