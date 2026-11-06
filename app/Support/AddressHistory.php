<?php

namespace App\Support;

use App\Models\AddressObservation;
use App\Models\IpAddress;
use Carbon\CarbonInterface;

class AddressHistory
{
    /**
     * Extends the current observation or opens a new one when the MAC address
     * or host name changed. Returns the previous MAC address when it changed.
     */
    public function observe(IpAddress $address, ?string $mac, ?string $hostname, string $source, CarbonInterface $at, ?string $location = null): ?string
    {
        /** @var AddressObservation|null $latest */
        $latest = $address->observations()->latest('last_seen_at')->latest('id')->first();
        $mac ??= $latest?->mac_address;
        $hostname ??= $latest?->hostname;

        if ($latest && $latest->mac_address === $mac && $latest->hostname === $hostname) {
            $latest->forceFill([
                'last_seen_at' => $at, 'times_seen' => $latest->times_seen + 1,
                'location' => $location ?? $latest->location,
            ])->save();

            return null;
        }

        $address->observations()->create([
            'mac_address' => $mac, 'hostname' => $hostname, 'source' => $source, 'location' => $location,
            'first_seen_at' => $at, 'last_seen_at' => $at,
        ]);

        return $latest?->mac_address !== null && $mac !== null && $latest->mac_address !== $mac ? $latest->mac_address : null;
    }
}
