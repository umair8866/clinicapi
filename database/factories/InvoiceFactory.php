<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visit_id' => \App\Models\Visit::factory(),
            'billing_date' => '2026-01-15',
            'currency' => 'BHD',
            'total_minor' => 3000,
            'discount_minor' => 0,
            'vat_rate_bps' => 0,
            'vat_minor' => 0,
            'net_minor' => 3000,
            'status' => 'unpaid',
        ];
    }
}
