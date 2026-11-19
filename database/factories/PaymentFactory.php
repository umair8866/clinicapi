<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => \App\Models\Invoice::factory()->state(['status' => 'paid']),
            'method' => 'cash',
            'amount_minor' => 3000,
            'paid_at' => '2026-01-15 09:30:00',
        ];
    }
}
