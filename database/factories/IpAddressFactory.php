<?php

namespace Database\Factories;

use App\Models\IpAddress;
use App\Models\Subnet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpAddress>
 */
class IpAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subnet_id' => Subnet::factory(),
            'address' => fn (array $attributes): string => explode('/', Subnet::findOrFail($attributes['subnet_id'])->cidr)[0],
            'hostname' => fake()->domainName(),
            'status' => 'used',
        ];
    }
}
