<?php

namespace Database\Factories;

use App\Models\WallOutlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WallOutlet>
 */
class WallOutletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => strtoupper(fake()->randomLetter()).fake()->unique()->numberBetween(1, 999),
        ];
    }
}
