<?php

namespace Tests\Unit;

use App\Support\PortMedia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PortMediaTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?int, 2: ?string, 3: string}> */
    public static function ports(): array
    {
        return [
            'MikroTik copper' => ['ether1', 1000, null, 'rj45'],
            'MikroTik SFP+' => ['sfp-sfpplus1', 10000, null, 'sfp'],
            'MikroTik SFP28' => ['sfp28-1', 25000, null, 'sfp'],
            'MikroTik QSFP+' => ['qsfpplus1-1', 40000, null, 'qsfp'],
            'MikroTik combo by speed' => ['combo1', 1000, null, 'rj45'],
            'Cisco copper' => ['GigabitEthernet1/0/1', 1000, null, 'rj45'],
            'Cisco copper name with fibre module' => ['GigabitEthernet1/1/1', 1000, 'GE SFP 1000BaseLX', 'sfp'],
            'Cisco 10G' => ['TenGigabitEthernet1/1/1', 10000, null, 'sfp'],
            'Cisco short 25G' => ['Twe1/0/1', 25000, null, 'sfp'],
            'Cisco 40G' => ['FortyGigabitEthernet1/1/1', 40000, null, 'qsfp'],
            'Cisco 100G' => ['HundredGigE1/0/49', 100000, null, 'qsfp'],
            'Juniper copper' => ['ge-0/0/0', 1000, null, 'rj45'],
            'Juniper 10G' => ['xe-0/1/0', 10000, null, 'sfp'],
            'Juniper 40G' => ['et-0/0/48', 40000, null, 'qsfp'],
            'D-Link copper by speed' => ['1/0/1', 1000, null, 'rj45'],
            'D-Link generic eth copper' => ['eth1/0/1', 1000, null, 'rj45'],
            'D-Link generic eth SFP+ by speed' => ['eth1/0/25', 10000, null, 'sfp'],
            'D-Link uplink by speed' => ['1/0/25', 10000, null, 'sfp'],
            'D-Link fibre from inventory' => ['1/0/27', 1000, 'Port 27 1000BASE-SX', 'sfp'],
            'Unknown copper from inventory' => ['Port 3', 10000, '10GBASE-T RJ45', 'rj45'],
        ];
    }

    #[DataProvider('ports')]
    public function test_the_connector_is_detected_from_name_inventory_and_speed(?string $name, ?int $speed, ?string $inventory, string $expected): void
    {
        $this->assertSame($expected, PortMedia::detect($name, null, $speed, $inventory));
    }
}
