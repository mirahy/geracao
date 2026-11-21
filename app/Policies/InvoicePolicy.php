<?php

namespace App\Policies;

class InvoicePolicy extends UnitScopedPolicy
{
    protected function prefix(): string
    {
        return 'faturas';
    }

    protected function readOnly(): bool
    {
        return true;
    }
}
