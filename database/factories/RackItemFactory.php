<?php

namespace Database\Factories;

use App\Models\Rack;
use App\Models\RackItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RackItem>
 */
class RackItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rack_id' => Rack::factory(),
            'kind' => 'other',
            'label' => fake()->words(2, true),
            'position' => 1,
            'height' => 1,
        ];
    }
}
