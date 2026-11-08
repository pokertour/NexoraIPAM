<?php

namespace Database\Factories;

use App\Models\ScanRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanRun>
 */
class ScanRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = now()->subMinutes(fake()->numberBetween(2, 600));

        return [
            'kind' => 'discovery',
            'subject_label' => fake()->ipv4().'/24',
            'trigger' => 'scheduled',
            'status' => 'completed',
            'summary' => ['targets' => 254, 'responded' => fake()->numberBetween(0, 254)],
            'started_at' => $started,
            'finished_at' => $started->copy()->addSeconds(fake()->numberBetween(1, 60)),
        ];
    }

    public function failed(string $error = 'Délai dépassé'): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'failed', 'summary' => null, 'error' => $error]);
    }
}
