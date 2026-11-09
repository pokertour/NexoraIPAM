<?php

namespace App\Support;

use App\Models\IpAddress;
use App\Models\NetworkDevice;
use App\Models\Subnet;
use App\Support\DeviceApi\DeviceApis;
use Brick\Math\BigInteger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads the state of a switch or router through the standard IF-MIB,
 * BRIDGE-MIB, Q-BRIDGE-MIB, LLDP-MIB and IP-MIB tables, completed by the
 * device management API (App\Support\DeviceApi) when one is configured.
 */
class DevicePoller
{
    private const SYSTEM = ['descr' => '1.3.6.1.2.1.1.1.0', 'object' => '1.3.6.1.2.1.1.2.0', 'uptime' => '1.3.6.1.2.1.1.3.0', 'name' => '1.3.6.1.2.1.1.5.0'];

    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    private const IF_TYPE = '1.3.6.1.2.1.2.2.1.3';

    private const IF_SPEED = '1.3.6.1.2.1.2.2.1.5';

    private const IF_ADMIN = '1.3.6.1.2.1.2.2.1.7';

    private const IF_OPER = '1.3.6.1.2.1.2.2.1.8';

    private const IF_IN_ERRORS = '1.3.6.1.2.1.2.2.1.14';

    private const IF_OUT_ERRORS = '1.3.6.1.2.1.2.2.1.20';

    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';

    private const IF_HC_IN = '1.3.6.1.2.1.31.1.1.1.6';

    private const IF_HC_OUT = '1.3.6.1.2.1.31.1.1.1.10';

    private const IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';

    private const IF_ALIAS = '1.3.6.1.2.1.31.1.1.1.18';

    private const BRIDGE_PORT_IF = '1.3.6.1.2.1.17.1.4.1.2';

    private const FDB_PORT = '1.3.6.1.2.1.17.4.3.1.2';

    private const Q_FDB_PORT = '1.3.6.1.2.1.17.7.1.2.2.1.2';

    private const Q_PVID = '1.3.6.1.2.1.17.7.1.4.5.1.1';

    private const Q_VLAN_EGRESS = '1.3.6.1.2.1.17.7.1.4.3.1.2';

    private const Q_VLAN_UNTAGGED = '1.3.6.1.2.1.17.7.1.4.3.1.4';

    /** Currently active VLANs, used when the static VLAN table is empty (MikroTik and others). */
    private const Q_VLAN_CURRENT_EGRESS = '1.3.6.1.2.1.17.7.1.4.2.1.4';

    private const Q_VLAN_CURRENT_UNTAGGED = '1.3.6.1.2.1.17.7.1.4.2.1.5';

    private const IF_PHYS_ADDRESS = '1.3.6.1.2.1.2.2.1.6';

    private const ENT_DESCR = '1.3.6.1.2.1.47.1.1.1.1.2';

    private const ENT_CONTAINED_IN = '1.3.6.1.2.1.47.1.1.1.1.4';

    private const ENT_NAME = '1.3.6.1.2.1.47.1.1.1.1.7';

    private const ENT_ALIAS = '1.3.6.1.2.1.47.1.3.2.1.2';

    private const LLDP_REM_PORT_ID = '1.0.8802.1.1.2.1.4.1.1.7';

    private const LLDP_REM_PORT_DESC = '1.0.8802.1.1.2.1.4.1.1.8';

    private const LLDP_REM_SYS_NAME = '1.0.8802.1.1.2.1.4.1.1.9';

    private const LLDP_LOC_PORT_DESC = '1.0.8802.1.1.2.1.3.7.1.4';

    /** Management addresses of LLDP neighbours, indexed by neighbour then address. */
    private const LLDP_REM_MAN_ADDR = '1.0.8802.1.1.2.1.4.2.1.3';

    private const ARP = '1.3.6.1.2.1.4.22.1.2';

    /** Ethernet, fast Ethernet, ISO 88023 and gigabit Ethernet interface types. */
    private const PHYSICAL_TYPES = [6, 7, 62, 69, 117];

    public function __construct(private SnmpClient $snmp, private AddressHistory $history, private AlertMonitor $monitor) {}

    /**
     * @return array{ports: int, up: int, macs: int, arp: int, addresses: int}
     */
    public function poll(NetworkDevice $device): array
    {
        $system = $this->snmp->get($device, array_values(self::SYSTEM));
        if ($system === []) {
            throw new RuntimeException(__('L’équipement a répondu sans données système.'));
        }

        $interfaces = [];
        foreach ([
            'descr' => self::IF_DESCR, 'type' => self::IF_TYPE, 'speed' => self::IF_SPEED, 'admin' => self::IF_ADMIN,
            'oper' => self::IF_OPER, 'in_errors' => self::IF_IN_ERRORS, 'out_errors' => self::IF_OUT_ERRORS,
            'name' => self::IF_NAME, 'in' => self::IF_HC_IN, 'out' => self::IF_HC_OUT, 'high_speed' => self::IF_HIGH_SPEED,
            'alias' => self::IF_ALIAS,
        ] as $key => $oid) {
            foreach ($this->snmp->walk($device, $oid) as $index => $value) {
                if (ctype_digit((string) $index)) {
                    $interfaces[(int) $index][$key] = $value;
                }
            }
        }

        $bridgeToIf = array_map('intval', array_filter($this->snmp->walk($device, self::BRIDGE_PORT_IF), 'ctype_digit'));
        if ($bridgeToIf === []) {
            // Without BRIDGE-MIB port mapping, bridge ports are numbered like interfaces.
            $bridgeToIf = array_combine(array_keys($interfaces), array_keys($interfaces));
        }
        $pvids = $this->snmp->walk($device, self::Q_PVID);
        $membership = $this->vlanMembership($device, $bridgeToIf);
        $apiVlans = $this->apiVlans($device, $interfaces);
        $inventory = $this->inventory($device);
        $physicalMacs = [];
        foreach ($this->snmp->walk($device, self::IF_PHYS_ADDRESS, true) as $index => $hex) {
            $mac = SnmpClient::mac(SnmpClient::bytes($hex));
            if (ctype_digit((string) $index) && $mac && $mac !== '00:00:00:00:00:00') {
                $physicalMacs[(int) $index] = $mac;
            }
        }
        $macs = $this->forwardingTable($device, $bridgeToIf);
        $neighbors = $this->neighbors($device, $interfaces, $bridgeToIf);
        $arp = $this->arpTable($device);
        $ipByMac = array_flip(array_reverse($arp, true));

        $now = now();
        $ifToBridge = array_flip($bridgeToIf);
        $summary = ['ports' => 0, 'up' => 0, 'macs' => 0, 'arp' => count($arp), 'addresses' => 0];
        if ($apiVlans !== null) {
            $summary['api_ports'] = count($apiVlans);
        }

        DB::transaction(function () use ($device, $system, $interfaces, $pvids, $membership, $apiVlans, $inventory, $physicalMacs, $macs, $neighbors, $ipByMac, $ifToBridge, $now, &$summary, $arp): void {
            $previous = $device->ports()->get()->keyBy('if_index');
            $seconds = $device->polled_at ? max(1, (int) round($device->polled_at->diffInSeconds($now, true))) : null;

            foreach ($interfaces as $index => $values) {
                $type = isset($values['type']) ? (int) $values['type'] : null;
                $name = $values['name'] ?? $values['descr'] ?? (string) $index;
                $physical = in_array($type, self::PHYSICAL_TYPES, true) && ! preg_match('/^(vlan|vl|lo|loopback|port-channel|po|null|tunnel|mgmt|management|cpu|stack|bond|br)/i', $name);
                $old = $previous->get($index);
                $bridge = $ifToBridge[$index] ?? null;
                $speed = isset($values['high_speed']) && (int) $values['high_speed'] > 0 ? (int) $values['high_speed'] : (isset($values['speed']) ? intdiv((int) $values['speed'], 1_000_000) : null);

                $attributes = [
                    'bridge_port' => $bridge, 'name' => $name, 'descr' => $values['descr'] ?? null,
                    'alias' => filled($values['alias'] ?? null) ? mb_substr($values['alias'], 0, 255) : null, 'type' => $type, 'physical' => $physical,
                    'admin_status' => isset($values['admin']) ? (int) $values['admin'] : null, 'oper_status' => isset($values['oper']) ? (int) $values['oper'] : null,
                    'speed_mbps' => $speed ?: null, 'pvid' => $bridge !== null && isset($pvids[$bridge]) ? (int) $pvids[$bridge] : null,
                    'tagged_vlans' => $membership['tagged'][$index] ?? null,
                    'untagged_vlans' => $membership['untagged'][$index] ?? null,
                    'media' => $physical ? PortMedia::detect($name, $values['descr'] ?? null, $speed ?: null, $inventory[$index] ?? null) : null,
                    'mac_address' => $physicalMacs[$index] ?? null,
                    'in_octets' => $this->counter($values['in'] ?? null), 'out_octets' => $this->counter($values['out'] ?? null),
                    'in_bps' => $this->rate($old?->in_octets, $values['in'] ?? null, $seconds),
                    'out_bps' => $this->rate($old?->out_octets, $values['out'] ?? null, $seconds),
                    'in_errors' => isset($values['in_errors']) ? (int) $values['in_errors'] : null,
                    'out_errors' => isset($values['out_errors']) ? (int) $values['out_errors'] : null,
                    'mac_count' => count($macs[$index] ?? []),
                    'neighbor_name' => $neighbors[$index]['name'] ?? null, 'neighbor_port' => $neighbors[$index]['port'] ?? null,
                    'polled_at' => $now,
                ];
                if ($apiVlans !== null) {
                    // The device API is authoritative for VLANs when it answers.
                    $attributes['pvid'] = $apiVlans[$index]['pvid'] ?? null;
                    $attributes['tagged_vlans'] = ($apiVlans[$index]['tagged'] ?? []) ?: null;
                    $attributes['untagged_vlans'] = ($apiVlans[$index]['untagged'] ?? []) ?: null;
                }
                $port = $device->ports()->updateOrCreate(['if_index' => $index], $attributes);

                $port->macs()->delete();
                foreach ($macs[$index] ?? [] as $mac => $vlan) {
                    $port->macs()->create(['network_device_id' => $device->id, 'mac_address' => $mac, 'vlan' => $vlan, 'ip_address' => $ipByMac[$mac] ?? null, 'seen_at' => $now]);
                }

                if ($physical) {
                    $summary['ports']++;
                    $summary['up'] += (int) ($attributes['oper_status'] === 1);
                }
                $summary['macs'] += count($macs[$index] ?? []);
            }

            $device->ports()->whereNotIn('if_index', array_keys($interfaces))->delete();
            $device->forceFill([
                'sys_name' => isset($system[self::SYSTEM['name']]) ? mb_substr($system[self::SYSTEM['name']], 0, 255) : null,
                'sys_descr' => $system[self::SYSTEM['descr']] ?? null,
                'sys_object_id' => isset($system[self::SYSTEM['object']]) ? ltrim($system[self::SYSTEM['object']], '.') : null,
                'sys_uptime' => isset($system[self::SYSTEM['uptime']]) && ctype_digit($system[self::SYSTEM['uptime']]) ? (int) $system[self::SYSTEM['uptime']] : null,
                'arp_entries' => count($arp), 'polled_at' => $now,
            ])->save();
        }, attempts: 3);

        $summary['addresses'] = $this->recordArp($device, $arp, $now);
        $this->nameAddresses($device, $system[self::SYSTEM['name']] ?? null);

        return $summary;
    }

    /**
     * Reads VLAN membership through the device management API, when one is
     * configured, and keys it by SNMP interface index. Returns null when no API
     * is configured or when it fails; the failure is kept on the device.
     *
     * @param  array<int, array<string, string>>  $interfaces
     * @return array<int, array{pvid: ?int, tagged: list<int>, untagged: list<int>}>|null
     */
    private function apiVlans(NetworkDevice $device, array $interfaces): ?array
    {
        $driver = DeviceApis::for($device);
        if (! $driver) {
            $device->api_error = null;

            return null;
        }

        try {
            $byName = $driver->vlanMembership($device);
        } catch (RuntimeException $exception) {
            $device->api_error = mb_substr($exception->getMessage(), 0, 255);

            return null;
        }
        $device->api_error = null;

        $byIndex = [];
        foreach ($interfaces as $index => $values) {
            foreach ([$values['name'] ?? null, $values['descr'] ?? null] as $name) {
                if ($name !== null && isset($byName[$name])) {
                    $byIndex[$index] = $byName[$name];
                    break;
                }
            }
        }

        return $byIndex;
    }

    /**
     * Reads VLAN membership from the static VLAN table, or from the table of
     * currently active VLANs when a device leaves the static one empty.
     *
     * @param  array<int, int>  $bridgeToIf
     * @return array{tagged: array<int, list<int>>, untagged: array<int, list<int>>} VLAN numbers keyed by interface index.
     */
    private function vlanMembership(NetworkDevice $device, array $bridgeToIf): array
    {
        $egress = $this->snmp->walk($device, self::Q_VLAN_EGRESS, true);
        $untagged = $this->snmp->walk($device, self::Q_VLAN_UNTAGGED, true);
        if ($egress === []) {
            // Rows of the current table are indexed by time mark, then VLAN.
            $byVlan = fn (array $rows): array => collect($rows)->mapWithKeys(fn (string $bitmap, string|int $suffix): array => [(string) last(explode('.', (string) $suffix)) => $bitmap])->all();
            $egress = $byVlan($this->snmp->walk($device, self::Q_VLAN_CURRENT_EGRESS, true));
            $untagged = $byVlan($this->snmp->walk($device, self::Q_VLAN_CURRENT_UNTAGGED, true));
        }

        $membership = ['tagged' => [], 'untagged' => []];
        foreach ($egress as $vlan => $bitmap) {
            if (! ctype_digit((string) $vlan)) {
                continue;
            }
            $plain = SnmpClient::portList($untagged[$vlan] ?? '');
            foreach (SnmpClient::portList($bitmap) as $bridge) {
                if (isset($bridgeToIf[$bridge])) {
                    $membership[in_array($bridge, $plain, true) ? 'untagged' : 'tagged'][$bridgeToIf[$bridge]][] = (int) $vlan;
                }
            }
        }

        foreach ($membership as $kind => $ports) {
            $membership[$kind] = array_map(function (array $vlans): array {
                sort($vlans);

                return array_values(array_unique($vlans));
            }, $ports);
        }

        return $membership;
    }

    /**
     * Describes the hardware behind each interface with ENTITY-MIB: the port
     * entity and the transceiver it contains, when the device exposes them.
     *
     * @return array<int, string> Inventory text keyed by interface index.
     */
    private function inventory(NetworkDevice $device): array
    {
        $aliases = $this->snmp->walk($device, self::ENT_ALIAS);
        if ($aliases === []) {
            return [];
        }

        $descriptions = $this->snmp->walk($device, self::ENT_DESCR);
        $names = $this->snmp->walk($device, self::ENT_NAME);
        $children = [];
        foreach ($this->snmp->walk($device, self::ENT_CONTAINED_IN) as $entity => $parent) {
            $children[(string) $parent][] = (string) $entity;
        }

        $inventory = [];
        foreach ($aliases as $suffix => $target) {
            if (! preg_match('/1\.3\.6\.1\.2\.1\.2\.2\.1\.1\.(\d+)$/', $target, $matches)) {
                continue;
            }
            $entity = explode('.', (string) $suffix)[0];
            $parts = [$descriptions[$entity] ?? '', $names[$entity] ?? ''];
            foreach ($children[$entity] ?? [] as $child) {
                $parts[] = ($descriptions[$child] ?? '').' '.($names[$child] ?? '');
            }
            $inventory[(int) $matches[1]] = trim(implode(' ', array_filter($parts)));
        }

        return $inventory;
    }

    /**
     * @param  array<int, int>  $bridgeToIf
     * @return array<int, array<string, ?int>> MAC addresses and their VLAN keyed by interface index.
     */
    private function forwardingTable(NetworkDevice $device, array $bridgeToIf): array
    {
        $entries = [];
        foreach ($this->snmp->walk($device, self::Q_FDB_PORT) as $suffix => $bridge) {
            $parts = explode('.', $suffix);
            if (count($parts) === 7 && ($mac = SnmpClient::mac(array_slice($parts, 1)))) {
                $entries[] = [$mac, (int) $parts[0], (int) $bridge];
            }
        }
        if ($entries === []) {
            foreach ($this->snmp->walk($device, self::FDB_PORT) as $suffix => $bridge) {
                if ($mac = SnmpClient::mac(explode('.', $suffix))) {
                    $entries[] = [$mac, null, (int) $bridge];
                }
            }
        }

        $byInterface = [];
        foreach ($entries as [$mac, $vlan, $bridge]) {
            if ($bridge > 0 && isset($bridgeToIf[$bridge])) {
                $byInterface[$bridgeToIf[$bridge]][$mac] = $vlan;
            }
        }

        return $byInterface;
    }

    /**
     * Matches LLDP neighbours to interfaces. Vendors number LLDP ports either
     * like bridge ports or like interfaces, so the local description wins.
     *
     * @param  array<int, array<string, string>>  $interfaces
     * @param  array<int, int>  $bridgeToIf
     * @return array<int, array{name: ?string, port: ?string}>
     */
    private function nameAddresses(NetworkDevice $device, ?string $systemName): void
    {
        $names = [];
        if ($systemName !== null && ($address = IpNetwork::address($device->host))) {
            $names[$address] = [$systemName, 'snmp'];
        }

        $neighbours = $this->snmp->walk($device, self::LLDP_REM_SYS_NAME);
        if ($neighbours !== []) {
            foreach ($this->snmp->walk($device, self::LLDP_REM_MAN_ADDR) as $suffix => $value) {
                $parts = explode('.', (string) $suffix);
                // timeMark.localPort.remIndex.addressSubtype.length.address…; subtype 1 is IPv4.
                if (count($parts) === 9 && $parts[3] === '1' && $parts[4] === '4') {
                    $address = IpNetwork::address(implode('.', array_slice($parts, 5)));
                    $name = $neighbours[implode('.', array_slice($parts, 0, 3))] ?? null;
                    if ($address && $name) {
                        $names[$address] ??= [$name, 'lldp'];
                    }
                }
            }
        }

        foreach ($names as $address => [$name, $source]) {
            IpAddress::where('address', $address)->get()->each(function (IpAddress $record) use ($name, $source): void {
                if (HostnameResolver::assign($record, $name, $source)) {
                    $record->save();
                }
            });
        }
    }

    private function neighbors(NetworkDevice $device, array $interfaces, array $bridgeToIf): array
    {
        $names = $this->snmp->walk($device, self::LLDP_REM_SYS_NAME);
        if ($names === []) {
            return [];
        }
        $ports = $this->snmp->walk($device, self::LLDP_REM_PORT_DESC) + $this->snmp->walk($device, self::LLDP_REM_PORT_ID);
        $localDescriptions = $this->snmp->walk($device, self::LLDP_LOC_PORT_DESC);
        $byLabel = [];
        foreach ($interfaces as $index => $values) {
            foreach (['name', 'descr', 'alias'] as $key) {
                if (filled($values[$key] ?? null)) {
                    $byLabel[strtolower($values[$key])] ??= $index;
                }
            }
        }

        $neighbors = [];
        foreach ($names as $suffix => $name) {
            $parts = explode('.', $suffix);
            if (count($parts) < 3) {
                continue;
            }
            $local = (int) $parts[1];
            $description = strtolower($localDescriptions[(string) $local] ?? '');
            $index = $byLabel[$description] ?? $bridgeToIf[$local] ?? (isset($interfaces[$local]) ? $local : null);
            if ($index !== null) {
                $neighbors[$index] = ['name' => mb_substr($name, 0, 255), 'port' => isset($ports[$suffix]) ? mb_substr($ports[$suffix], 0, 255) : null];
            }
        }

        return $neighbors;
    }

    /** @return array<string, string> MAC addresses keyed by IPv4 address. */
    private function arpTable(NetworkDevice $device): array
    {
        $table = [];
        foreach ($this->snmp->walk($device, self::ARP, true) as $suffix => $hex) {
            $parts = explode('.', $suffix);
            $address = count($parts) === 5 ? IpNetwork::address(implode('.', array_slice($parts, 1))) : null;
            $mac = SnmpClient::mac(SnmpClient::bytes($hex));
            if ($address && $mac && $mac !== '00:00:00:00:00:00' && $mac !== 'ff:ff:ff:ff:ff:ff') {
                $table[$address] = $mac;
            }
        }

        return $table;
    }

    /**
     * Updates the inventory with the ARP table: known addresses get their MAC
     * and history, unknown addresses inside an inventoried subnet are added.
     *
     * @param  array<string, string>  $arp
     */
    private function recordArp(NetworkDevice $device, array $arp, CarbonInterface $now): int
    {
        if ($arp === []) {
            return 0;
        }

        $settings = AlertMonitor::settings();
        $subnets = Subnet::with('childSubnets')->where('cidr', 'not like', '%:%')->get()
            ->sortByDesc(fn (Subnet $subnet): int => (int) explode('/', $subnet->cidr)[1]);
        $updated = 0;

        foreach ($arp as $address => $mac) {
            $records = IpAddress::where('address', $address)->get();
            if ($records->isEmpty()) {
                $subnet = $subnets->first(fn (Subnet $subnet): bool => IpNetwork::assignable($subnet->cidr, $address)
                    && ! $subnet->childSubnets->contains(fn (Subnet $child): bool => IpNetwork::contains($child->cidr, $address)));
                if (! $subnet) {
                    continue;
                }
                $record = $subnet->addresses()->create(['address' => $address, 'status' => 'used']);
                if ($settings['conflicts']) {
                    $this->monitor->raise('unknown_host', $record, ['subnet' => $subnet->cidr, 'device' => $device->name]);
                }
                $records = collect([$record]);
            }

            foreach ($records as $record) {
                $previous = $this->history->observe($record, $mac, null, 'snmp', $now, $device->name);
                if ($record->mac_address !== $mac) {
                    $record->forceFill(['mac_address' => $mac])->save();
                }
                if ($previous !== null && $settings['conflicts']) {
                    $this->monitor->raise('mac_changed', $record, ['old' => $previous, 'new' => $mac, 'device' => $device->name], 'critical');
                }
                if ($record->status === 'available' && $settings['conflicts']) {
                    $this->monitor->raise('status_conflict', $record, ['device' => $device->name], 'critical');
                }
                $updated++;
            }
        }

        return $updated;
    }

    private function counter(?string $value): ?string
    {
        return $value !== null && ctype_digit($value) ? $value : null;
    }

    /** Converts two counter readings to bits per second, ignoring resets. */
    private function rate(?string $previous, ?string $current, ?int $seconds): ?int
    {
        if ($previous === null || $current === null || $seconds === null || ! ctype_digit($previous) || ! ctype_digit($current)) {
            return null;
        }

        $delta = BigInteger::of($current)->minus(BigInteger::of($previous));
        if ($delta->isNegative()) {
            return null;
        }

        return $delta->multipliedBy(8)->quotient($seconds)->toInt();
    }
}
