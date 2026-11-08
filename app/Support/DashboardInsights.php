<?php

namespace App\Support;

use App\Models\AddressObservation;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\DevicePort;
use App\Models\DevicePortMac;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\NetworkDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Recent activity shown on the dashboard, scoped to a location.
 */
class DashboardInsights
{
    public const DAYS = 7;

    public function __construct(private MacAddress $macs) {}

    /** @return array<string, mixed> */
    public function forLocation(?Location $location, bool $withChanges): array
    {
        $ids = $location?->descendantsAndSelf();
        $since = now()->subDays(self::DAYS);
        $devices = NetworkDevice::query()->when($ids, fn (Builder $query) => $query->whereIn('location_id', $ids))->get();

        return [
            'newHosts' => $this->newHosts($ids, $since),
            'devices' => [
                'total' => $devices->count(),
                'online' => $devices->filter(fn (NetworkDevice $device): bool => $device->polled_at !== null && $device->poll_status !== 'failed')->count(),
                'unreachable' => $devices->where('poll_status', 'failed')->values(),
            ],
            'busyPorts' => $this->busyPorts($devices->modelKeys()),
            'macChanges' => Alert::where('type', 'mac_changed')->where('created_at', '>=', $since)
                ->when($ids, fn (Builder $query) => $query->whereIn('subject_id', IpAddress::whereHas('subnet', fn (Builder $subnets) => $subnets->whereIn('location_id', $ids))->select('id')))
                ->latest('id')->limit(5)->get(),
            'recentChanges' => $withChanges
                ? AuditLog::whereIn('action', ['created', 'updated', 'deleted'])->latest('id')->limit(6)->get()
                : collect(),
        ];
    }

    /**
     * Addresses first seen during the period, with their MAC vendor, the switch
     * port where the MAC is learned and whether the MAC itself is new.
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, array{address: IpAddress, first_seen: string, mac: ?string, vendor: ?string, new_mac: bool, port: ?DevicePortMac}>
     */
    private function newHosts(?array $ids, \DateTimeInterface $since): Collection
    {
        $firsts = AddressObservation::selectRaw('ip_address_id, MIN(first_seen_at) AS first_seen')->groupBy('ip_address_id');
        $addresses = IpAddress::with('subnet')
            ->joinSub($firsts, 'firsts', fn ($join) => $join->on('firsts.ip_address_id', '=', 'ip_addresses.id'))
            ->where('firsts.first_seen', '>=', $since)
            ->when($ids, fn (Builder $query) => $query->whereHas('subnet', fn (Builder $subnets) => $subnets->whereIn('location_id', $ids)))
            ->orderByDesc('firsts.first_seen')->limit(8)
            ->get(['ip_addresses.*', 'firsts.first_seen']);

        $macs = $addresses->pluck('mac_address')->filter()->unique()->values();
        $macFirstSeen = AddressObservation::whereIn('mac_address', $macs)->selectRaw('mac_address, MIN(first_seen_at) AS first_seen')
            ->groupBy('mac_address')->pluck('first_seen', 'mac_address');
        $ports = DevicePortMac::with(['port', 'device'])->whereIn('mac_address', $macs)->get()->keyBy('mac_address');

        return $addresses->map(fn (IpAddress $address): array => [
            'address' => $address,
            'first_seen' => (string) $address->first_seen,
            'mac' => $address->mac_address,
            'vendor' => $address->mac_address ? $this->macs->vendor($address->mac_address) : null,
            'new_mac' => $address->mac_address !== null && isset($macFirstSeen[$address->mac_address]) && $macFirstSeen[$address->mac_address] >= $since->format('Y-m-d H:i:s'),
            'port' => $address->mac_address ? $ports->get($address->mac_address) : null,
        ]);
    }

    /**
     * @param  list<int>  $deviceIds
     * @return Collection<int, DevicePort>
     */
    private function busyPorts(array $deviceIds): Collection
    {
        return DevicePort::with('device')->whereIn('network_device_id', $deviceIds)->where('physical', true)
            ->whereNotNull('speed_mbps')->where(fn (Builder $query) => $query->where('in_bps', '>', 0)->orWhere('out_bps', '>', 0))
            ->get()->sortByDesc(fn (DevicePort $port): float => $port->utilization() ?? 0)->take(5)->values();
    }
}
