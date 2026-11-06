<?php

namespace Database\Factories;

use App\Models\NetworkDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkDevice>
 */
class NetworkDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'sw-'.fake()->unique()->numberBetween(1, 9999),
            'host' => fake()->unique()->ipv4(),
            'port' => 161,
            'snmp_version' => '2c',
            'credentials' => ['community' => 'public'],
        ];
    }
}
