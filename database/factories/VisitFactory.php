<?php

namespace Database\Factories;

use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => \App\Models\Patient::factory(),
            'doctor_id' => \App\Models\Doctor::factory(),
            'branch_id' => fn (array $attributes): int => \App\Models\Doctor::findOrFail($attributes['doctor_id'])->branch_id,
            'visit_date' => '2026-01-15 09:00:00',
            'status' => 'completed',
        ];
    }
}
