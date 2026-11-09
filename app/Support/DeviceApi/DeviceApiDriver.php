<?php

namespace App\Support\DeviceApi;

use App\Models\NetworkDevice;
use RuntimeException;

/**
 * Reads from a device's management API what SNMP does not expose. A driver
 * speaks the vendor's API and returns data keyed by interface name, which
 * the poller matches with the SNMP interfaces (ifName, then ifDescr).
 *
 * To support another vendor, implement this interface and register the
 * class in DeviceApis::DRIVERS.
 */
interface DeviceApiDriver
{
    /**
     * VLAN membership of each interface.
     *
     * @return array<string, array{pvid: ?int, tagged: list<int>, untagged: list<int>}>
     *
     * @throws RuntimeException When the API cannot be reached or refuses the credentials.
     */
    public function vlanMembership(NetworkDevice $device): array;
}
