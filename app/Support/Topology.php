<?php

namespace App\Support;

use App\Models\DevicePort;
use App\Models\DevicePortMac;
use App\Models\NetworkDevice;
use Illuminate\Support\Collection;

/**
 * Works out which devices are cabled together. LLDP neighbours are matched by
 * system name first; without LLDP, two switches are linked when each one has
 * learned a MAC address of the other, keeping the closest candidate.
 */
class Topology
{
    /**
     * @return Collection<int, array{a: NetworkDevice, a_port: DevicePort, b: NetworkDevice, b_port: ?DevicePort, source: string}>
     */
    public function links(): Collection
    {
        $devices = NetworkDevice::with(['ports' => fn ($query) => $query->where('physical', true)])->get()->keyBy('id');
        if ($devices->count() < 2) {
            return collect();
        }

        $links = collect();
        $taken = [];
        $byName = [];
        foreach ($devices as $device) {
            foreach (array_filter([$device->sys_name, $device->name]) as $name) {
                $byName[self::normalize($name)] ??= $device->id;
            }
        }

        foreach ($devices as $device) {
            foreach ($device->ports->whereNotNull('neighbor_name') as $port) {
                $peerId = $byName[self::normalize($port->neighbor_name)] ?? null;
                if ($peerId === null || $peerId === $device->id) {
                    continue;
                }
                $peer = $devices[$peerId];
                $peerPort = $peer->ports->first(fn (DevicePort $candidate): bool => self::samePort($candidate, $port->neighbor_port));
                $this->add($links, $taken, $device, $port, $peer, $peerPort, 'lldp');
            }
        }

        $owners = [];
        foreach ($devices as $device) {
            foreach ($device->ports->whereNotNull('mac_address') as $port) {
                $owners[$port->mac_address] = $device->id;
            }
        }
        if ($owners === []) {
            return $links->values();
        }

        /** @var array<int, array<int, DevicePort>> $sightings Ports of device A where a MAC of device B was learned. */
        $sightings = [];
        $ports = $devices->flatMap(fn (NetworkDevice $device) => $device->ports)->keyBy('id');
        DevicePortMac::whereIn('mac_address', array_keys($owners))->get(['device_port_id', 'network_device_id', 'mac_address'])
            ->each(function (DevicePortMac $entry) use (&$sightings, $owners, $ports): void {
                $peerId = $owners[$entry->mac_address];
                $port = $ports->get($entry->device_port_id);
                if ($port && $peerId !== $entry->network_device_id) {
                    $sightings[$entry->network_device_id][$peerId] = $port;
                }
            });

        foreach ($sightings as $deviceId => $peers) {
            foreach ($peers as $peerId => $port) {
                $back = $sightings[$peerId][$deviceId] ?? null;
                if ($back === null) {
                    continue;
                }
                $closest = collect($peers)->filter(fn (DevicePort $candidate, int $candidateId): bool => $candidate->is($port) && isset($sightings[$candidateId][$deviceId]))
                    ->keys()->sortBy(fn (int $candidateId): int => $sightings[$candidateId][$deviceId]->mac_count)->first();
                if ($closest === $peerId) {
                    $this->add($links, $taken, $devices[$deviceId], $port, $devices[$peerId], $back, 'mac');
                }
            }
        }

        return $links->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $links
     * @param  array<string, true>  $taken
     */
    private function add(Collection $links, array &$taken, NetworkDevice $a, DevicePort $aPort, NetworkDevice $b, ?DevicePort $bPort, string $source): void
    {
        $pair = $a->id < $b->id ? $a->id.'-'.$b->id : $b->id.'-'.$a->id;
        $ends = array_filter([$a->id.':'.$aPort->id, $bPort ? $b->id.':'.$bPort->id : null]);
        if (isset($taken['pair:'.$pair]) || array_intersect_key($taken, array_flip($ends)) !== []) {
            return;
        }

        foreach ($ends as $end) {
            $taken[$end] = true;
        }
        $taken['pair:'.$pair] = true;
        $links->push(['a' => $a, 'a_port' => $aPort, 'b' => $b, 'b_port' => $bPort, 'source' => $source]);
    }

    private static function normalize(string $name): string
    {
        return strtolower(explode('.', trim($name))[0]);
    }

    /**
     * Matches Te1/0/48 with TenGigabitEthernet1/0/48, exact names, and the
     * last segment of RouterOS labels such as bridge/UplinkCore01/sfp-sfpplus22.
     */
    private static function samePort(DevicePort $port, ?string $label): bool
    {
        if ($label === null || $label === '') {
            return false;
        }

        $label = strtolower($label);
        $last = last(explode('/', $label));
        if ($last !== $label && preg_match('/[a-z]/', $last) && in_array($last, array_map('strtolower', array_filter([$port->name, $port->descr, $port->alias])), true)) {
            return true;
        }
        foreach (array_filter([$port->name, $port->descr, $port->alias]) as $candidate) {
            $candidate = strtolower($candidate);
            if ($candidate === $label) {
                return true;
            }
            if (preg_match('/^([a-z]{2})[a-z-]*([\d\/.:-]+)$/', $candidate, $left) && preg_match('/^([a-z]{2})[a-z-]*([\d\/.:-]+)$/', $label, $right)
                && $left[1] === $right[1] && $left[2] === $right[2]) {
                return true;
            }
        }

        return false;
    }
}
