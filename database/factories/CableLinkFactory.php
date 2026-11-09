<?php

namespace Database\Factories;

use App\Models\CableLink;
use App\Models\RackItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CableLink>
 */
class CableLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'a_rack_item_id' => RackItem::factory(),
            'b_rack_item_id' => RackItem::factory(),
            'medium' => 'copper',
        ];
    }
}
