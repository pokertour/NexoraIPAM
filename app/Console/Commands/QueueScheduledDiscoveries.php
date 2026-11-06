<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Subnet;
use App\Support\DiscoveryQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueScheduledDiscoveries extends Command
{
    protected $signature = 'nexora:discover-due';

    protected $description = 'Planifie les découvertes réseau arrivées à échéance';

    public function handle(DiscoveryQueue $queue): int
    {
        $now = now();
        Location::where('next_discovery_at', '<=', $now)->select('id')->eachById(function (Location $candidate) use ($queue, $now): void {
            DB::transaction(function () use ($candidate, $queue, $now): void {
                $location = Location::where('next_discovery_at', '<=', $now)->lockForUpdate()->find($candidate->id);
                if (! $location?->discovery_interval) {
                    return;
                }

                $location->next_discovery_at = $now->copy()->addMinutes($location->discovery_interval);
                $location->save();

                $location->subnets()->select('id')->eachById(function (Subnet $subnet) use ($queue, $location): void {
                    $queue->enqueue($subnet->id, $location->id);
                });
            }, attempts: 3);
        });

        return self::SUCCESS;
    }
}
