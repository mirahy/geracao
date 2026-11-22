<?php

namespace App\Policies;

class RateioPaymentPolicy extends UnitScopedPolicy
{
    protected function prefix(): string
    {
        return 'pagamentos';
    }
}
