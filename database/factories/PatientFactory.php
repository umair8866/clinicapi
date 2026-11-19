<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mrn' => fake()->unique()->bothify('MRN-########??'),
            'cpr' => fake()->unique()->numerify('#########'),
            'name' => fake()->name(),
            'phone' => '+973'.fake()->numerify('3#######'),
            'dob' => '1990-01-01',
            'gender' => 'unknown',
        ];
    }
}
