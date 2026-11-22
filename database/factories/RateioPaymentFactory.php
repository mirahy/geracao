<?php

namespace Database\Factories;

use App\Models\RateioPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateioPayment>
 */
class RateioPaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reference_month' => now()->startOfMonth()->format('Y-m-d'),
            'amount' => fake()->randomFloat(2, 5, 200),
            'paid_at' => now(),
            'source' => RateioPayment::SOURCE_MANUAL,
        ];
    }
}
