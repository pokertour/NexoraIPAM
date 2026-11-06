<?php

namespace Database\Factories;

use App\Models\Subnet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subnet>
 */
class SubnetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'cidr' => fake()->unique()->ipv4().'/32',
        ];
    }
}
