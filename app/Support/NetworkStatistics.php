<?php

namespace App\Support;

use App\Models\IpAddress;
use App\Models\Location;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Builder;

class NetworkStatistics
{
    public function forLocation(?Location $location): array
    {
        $ids = $location?->descendantsAndSelf();
        $networks = Subnet::query()->when($ids, fn (Builder $query) => $query->whereIn('location_id', $ids));
        $addresses = IpAddress::query()
            ->when($ids, fn (Builder $query) => $query->whereHas('subnet', fn (Builder $subnets) => $subnets->whereIn('location_id', $ids)));
        $statuses = (clone $addresses)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $pingCounts = $addresses->toBase()->selectRaw(
            'COUNT(CASE WHEN last_probe_at IS NOT NULL THEN 1 END) AS probed,
             COUNT(CASE WHEN last_probe_responded = TRUE OR
                (last_probe_responded IS NULL AND last_probe_at = last_seen_at) THEN 1 END) AS responding'
        )->first();

        $ipv4 = ['capacity' => 0, 'assigned' => 0];
        $ipv4Reserved = 0;
        $busiest = [];
        foreach ((clone $networks)->with('childSubnets')->withCount([
            'addresses as assigned' => fn (Builder $query) => $query->whereIn('status', ['used', 'reserved']),
            'addresses as reserved' => fn (Builder $query) => $query->where('status', 'reserved'),
        ])->where('cidr', 'not like', '%:%')->lazyById() as $subnet) {
            $capacity = $subnet->ipv4Capacity();
            $ipv4['capacity'] += $capacity;
            $ipv4['assigned'] += $subnet->assigned;
            $ipv4Reserved += $subnet->reserved;
            if ($subnet->assigned > 0) {
                $busiest[] = ['subnet' => $subnet, 'capacity' => $capacity, 'percentage' => self::percentage($subnet->assigned, $capacity)];
            }
        }

        usort($busiest, fn (array $a, array $b): int => ($b['percentage'] <=> $a['percentage'])
            ?: ($b['subnet']->assigned <=> $a['subnet']->assigned));

        return [
            'networkCount' => (clone $networks)->count(),
            'vlanCount' => Vlan::query()->when($ids, fn (Builder $query) => $query->whereIn('location_id', $ids))->count(),
            'statuses' => collect(IpAddress::STATUSES)->map(fn (string $label, string $status): int => (int) ($statuses[$status] ?? 0)),
            'pingCounts' => [
                'responding' => (int) $pingCounts->responding,
                'unresponsive' => (int) $pingCounts->probed - (int) $pingCounts->responding,
                'unprobed' => $statuses->sum() - (int) $pingCounts->probed,
            ],
            'ipv4States' => [
                'used' => $ipv4['assigned'] - $ipv4Reserved,
                'reserved' => $ipv4Reserved,
                'available' => max(0, $ipv4['capacity'] - $ipv4['assigned']),
            ],
            'ipv4' => $ipv4 + ['percentage' => self::percentage($ipv4['assigned'], $ipv4['capacity'])],
            'busiest' => array_slice($busiest, 0, 5),
            'scans' => $networks->whereNotNull('scanned_at')->with('location.parent.parent')->latest('scanned_at')->limit(5)->get(),
        ];
    }

    private static function percentage(int $count, int|float $capacity): float
    {
        return $capacity > 0 ? min(100, round(100 * $count / $capacity, 2)) : 0;
    }
}
