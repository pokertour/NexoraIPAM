<?php

namespace App\Support\DeviceApi;

use App\Models\NetworkDevice;
use RuntimeException;

/**
 * VLAN membership of a MikroTik RouterOS device, read from the bridge VLAN
 * table that RouterOS does not publish over SNMP (Q-BRIDGE-MIB). Subclasses
 * only fetch the rows, through the REST API or the binary API.
 */
abstract class RouterOs implements DeviceApiDriver
{
    /** @var list<string> */
    protected const MENUS = ['interface/bridge/vlan', 'interface/vlan', 'interface/bridge/port'];

    /**
     * Rows of each menu, with RouterOS property names as keys.
     *
     * @param  list<string>  $menus
     * @return array<string, list<array<string, mixed>>>
     *
     * @throws RuntimeException
     */
    abstract protected function read(NetworkDevice $device, array $menus): array;

    public function vlanMembership(NetworkDevice $device): array
    {
        $rows = $this->read($device, self::MENUS);
        $ports = [];
        $add = function (string $names, int $vlan, string $kind) use (&$ports): void {
            foreach (preg_split('/\s*,\s*/', trim($names), -1, PREG_SPLIT_NO_EMPTY) as $name) {
                $ports[$name] ??= ['pvid' => null, 'tagged' => [], 'untagged' => []];
                $ports[$name][$kind][] = $vlan;
            }
        };

        foreach ($rows['interface/bridge/vlan'] ?? [] as $row) {
            if ($this->disabled($row)) {
                continue;
            }
            // current-* include the dynamic members RouterOS adds from the PVID of each port.
            $tagged = (string) ($row['current-tagged'] ?? $row['tagged'] ?? '');
            $untagged = (string) ($row['current-untagged'] ?? $row['untagged'] ?? '');
            foreach (DeviceApis::vlanList($row['vlan-ids'] ?? null) as $vlan) {
                $add($tagged, $vlan, 'tagged');
                $add($untagged, $vlan, 'untagged');
            }
        }

        foreach ($rows['interface/vlan'] ?? [] as $row) {
            $vlan = DeviceApis::vlanList($row['vlan-id'] ?? null)[0] ?? null;
            if (! $this->disabled($row) && $vlan && filled($row['interface'] ?? null)) {
                $add((string) $row['interface'], $vlan, 'tagged');
            }
        }

        foreach ($rows['interface/bridge/port'] ?? [] as $row) {
            $pvid = DeviceApis::vlanList($row['pvid'] ?? null)[0] ?? null;
            if (! $this->disabled($row) && $pvid && filled($row['interface'] ?? null)) {
                $ports[(string) $row['interface']] ??= ['pvid' => null, 'tagged' => [], 'untagged' => []];
                $ports[(string) $row['interface']]['pvid'] = $pvid;
            }
        }

        return array_map(function (array $port): array {
            foreach (['tagged', 'untagged'] as $kind) {
                $port[$kind] = array_values(array_unique($port[$kind]));
                sort($port[$kind]);
            }
            $port['tagged'] = array_values(array_diff($port['tagged'], $port['untagged']));

            return $port;
        }, $ports);
    }

    /** Host as written in a URL or a socket address, with brackets around IPv6. */
    protected function host(NetworkDevice $device): string
    {
        return filter_var($device->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$device->host.']' : $device->host;
    }

    /** @param  array<string, mixed>  $row */
    private function disabled(array $row): bool
    {
        return in_array($row['disabled'] ?? false, [true, 'true', 'yes'], true);
    }
}
