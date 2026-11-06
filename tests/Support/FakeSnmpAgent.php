<?php

namespace Tests\Support;

/**
 * Simulates a 24-port access switch with four 10G uplinks and answers the
 * net-snmp commands built by App\Support\SnmpClient.
 */
class FakeSnmpAgent
{
    /** @var array<string, array{0: string, 1: string|int}> */
    public array $values = [];

    public bool $online = true;

    public function __construct(int $trafficStep = 0)
    {
        $this->set('1.3.6.1.2.1.1.1.0', 'STRING', "Cisco IOS XE Software, Catalyst L3 Switch Software (CAT9K_IOSXE)\nTechnical Support");
        $this->set('1.3.6.1.2.1.1.2.0', 'OID', '.1.3.6.1.4.1.9.1.2494');
        $this->set('1.3.6.1.2.1.1.3.0', 'Timeticks', 123456789);
        $this->set('1.3.6.1.2.1.1.5.0', 'STRING', 'lab-sw01');

        $interfaces = [];
        for ($port = 1; $port <= 24; $port++) {
            $interfaces[1000 + $port] = ['GigabitEthernet1/0/'.$port, 6, $port];
        }
        for ($port = 1; $port <= 4; $port++) {
            $interfaces[1100 + $port] = ['TenGigabitEthernet1/1/'.$port, 6, 24 + $port];
        }
        $interfaces[10] = ['Vlan10', 53, null];
        $interfaces[5001] = ['Port-channel1', 161, null];

        foreach ($interfaces as $index => [$name, $type, $bridge]) {
            $number = $bridge ?? 0;
            $up = in_array($number, [...range(1, 10), ...range(13, 18), 25, 26], true) || $type === 53;
            $speed = $number >= 25 ? 10000 : ($number === 5 ? 100 : 1000);
            $this->set('1.3.6.1.2.1.2.2.1.2.'.$index, 'STRING', $name);
            $this->set('1.3.6.1.2.1.2.2.1.3.'.$index, 'INTEGER', $type);
            $this->set('1.3.6.1.2.1.2.2.1.5.'.$index, 'Gauge32', min(4294967295, $speed * 1_000_000));
            $this->set('1.3.6.1.2.1.2.2.1.7.'.$index, 'INTEGER', $number === 23 ? 2 : 1);
            $this->set('1.3.6.1.2.1.2.2.1.8.'.$index, 'INTEGER', $up ? 1 : 2);
            $this->set('1.3.6.1.2.1.2.2.1.14.'.$index, 'Counter32', $number === 7 ? 42 : 0);
            $this->set('1.3.6.1.2.1.2.2.1.20.'.$index, 'Counter32', 0);
            $this->set('1.3.6.1.2.1.31.1.1.1.1.'.$index, 'STRING', $name);
            $this->set('1.3.6.1.2.1.31.1.1.1.6.'.$index, 'Counter64', $up ? 1_000_000_000 * $number + $trafficStep * 375_000_000 * max(1, $number) : 0);
            $this->set('1.3.6.1.2.1.31.1.1.1.10.'.$index, 'Counter64', $up ? 2_000_000_000 * $number + $trafficStep * 75_000_000 * max(1, $number) : 0);
            $this->set('1.3.6.1.2.1.31.1.1.1.15.'.$index, 'Gauge32', $speed);
            $this->set('1.3.6.1.2.1.31.1.1.1.18.'.$index, 'STRING', match ($number) {
                1 => 'Bureau 101 - poste', 3 => 'Imprimante RDC', 13 => 'srv-hyperv-01', 25 => 'Uplink core-sw01', default => '',
            });
            if ($bridge !== null) {
                $this->set('1.3.6.1.2.1.17.1.4.1.2.'.$bridge, 'INTEGER', $index);
                $this->set('1.3.6.1.2.1.17.7.1.4.5.1.1.'.$bridge, 'Gauge32', $bridge <= 12 ? 10 : ($bridge <= 20 ? 20 : 1));
            }
        }

        foreach ([10 => [range(1, 12), [...range(1, 12), 25, 26]], 20 => [range(13, 20), [...range(13, 20), 25, 26]], 30 => [[], [25]], 1 => [[...range(21, 28)], [...range(21, 28)]]] as $vlan => [$untagged, $egress]) {
            $this->set('1.3.6.1.2.1.17.7.1.4.3.1.2.'.$vlan, 'Hex', self::portList($egress));
            $this->set('1.3.6.1.2.1.17.7.1.4.3.1.4.'.$vlan, 'Hex', self::portList($untagged));
        }

        $fdb = [
            ['00:1a:2b:3c:4d:01', 10, 1], ['00:1a:2b:3c:4d:03', 10, 3], ['3c:2a:f4:00:00:03', 10, 3],
            ['00:15:5d:00:13:01', 20, 13], ['00:15:5d:00:13:02', 20, 13], ['00:15:5d:00:13:03', 20, 13],
            ['f8:b4:6a:00:00:01', 10, 25], ['f8:b4:6a:00:00:02', 20, 25],
        ];
        foreach ($fdb as [$mac, $vlan, $bridge]) {
            $this->set('1.3.6.1.2.1.17.7.1.2.2.1.2.'.$vlan.'.'.self::decimalMac($mac), 'INTEGER', $bridge);
        }

        foreach (['192.0.2.11' => '00:1a:2b:3c:4d:01', '192.0.2.13' => '00:1a:2b:3c:4d:03', '192.0.2.50' => '00:15:5d:00:13:01', '203.0.113.9' => 'f8:b4:6a:00:00:01'] as $ip => $mac) {
            $this->set('1.3.6.1.2.1.4.22.1.2.10.'.$ip, 'Hex', strtoupper(str_replace(':', ' ', $mac)).' ');
        }

        $this->set('1.0.8802.1.1.2.1.3.7.1.4.25', 'STRING', 'TenGigabitEthernet1/1/1');
        $this->set('1.0.8802.1.1.2.1.4.1.1.7.0.25.1', 'STRING', 'Te1/0/48');
        $this->set('1.0.8802.1.1.2.1.4.1.1.8.0.25.1', 'STRING', 'TenGigabitEthernet1/0/48');
        $this->set('1.0.8802.1.1.2.1.4.1.1.9.0.25.1', 'STRING', 'core-sw01.example.test');

        uksort($this->values, fn (string $left, string $right): int => version_compare($left, $right));
    }

    /** @param list<string> $command */
    public function respond(array $command): string
    {
        if (! $this->online) {
            return '';
        }

        $tool = $command[0];
        $targets = array_slice($command, (int) array_key_last(array_filter($command, fn (string $part): bool => str_contains($part, ':161'))) + 1);
        $hex = in_array('-Ox', $command, true);
        $lines = [];
        foreach ($targets as $target) {
            $target = ltrim($target, '.');
            if ($tool === 'snmpget') {
                $lines[] = '.'.$target.' '.(isset($this->values[$target]) ? $this->format($this->values[$target], $hex) : 'No Such Object available on this agent at this OID');

                continue;
            }
            $found = false;
            foreach ($this->values as $oid => $value) {
                if (str_starts_with($oid, $target.'.')) {
                    $lines[] = '.'.$oid.' '.$this->format($value, $hex);
                    $found = true;
                }
            }
            if (! $found) {
                $lines[] = '.'.$target.' No Such Object available on this agent at this OID';
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function set(string $oid, string $type, string|int $value): void
    {
        $this->values[$oid] = [$type, $value];
    }

    /** @param array{0: string, 1: string|int} $value */
    private function format(array $value, bool $hex): string
    {
        [$type, $content] = $value;

        return match ($type) {
            'STRING' => $hex ? '"'.strtoupper(implode(' ', str_split(bin2hex((string) $content), 2))).' "' : '"'.str_replace('"', '\"', (string) $content).'"',
            'Hex' => '"'.$this->wrap((string) $content).'"',
            default => (string) $content,
        };
    }

    /** Mimics net-snmp, which wraps long hexadecimal values over several lines. */
    private function wrap(string $hex): string
    {
        return implode("\n", str_split($hex, 48));
    }

    /** @param list<int> $ports */
    private static function portList(array $ports): string
    {
        $bytes = array_fill(0, 8, 0);
        foreach ($ports as $port) {
            $bytes[intdiv($port - 1, 8)] |= 0x80 >> (($port - 1) % 8);
        }

        return implode(' ', array_map(fn (int $byte): string => sprintf('%02X', $byte), $bytes)).' ';
    }

    private static function decimalMac(string $mac): string
    {
        return implode('.', array_map('hexdec', explode(':', $mac)));
    }
}
