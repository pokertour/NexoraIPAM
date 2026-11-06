<?php

namespace Database\Factories;

use App\Models\Vlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vlan>
 */
class VlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(1, 4094),
            'name' => fake()->unique()->word(),
        ];
    }
}
