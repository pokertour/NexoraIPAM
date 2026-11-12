<?php

namespace Database\Factories;

use App\Models\NetworkDevice;
use App\Models\PortDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortDestination>
 */
class PortDestinationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network_device_id' => NetworkDevice::factory(),
            'port' => 'ge'.fake()->numberBetween(1, 48),
            'label' => fake()->randomElement(['Imprimante accueil', 'Borne Wi-Fi plafond', 'Baie B · panneau 2']),
        ];
    }
}
