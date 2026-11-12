<?php

namespace Tests\Unit;

use App\Support\DevicePoller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhysicalPortTest extends TestCase
{
    /** @return array<string, array{0: ?int, 1: string, 2: bool}> */
    public static function interfaces(): array
    {
        return [
            'SwOS port' => [6, 'Port1', true],
            'SwOS port 16' => [6, 'Port16', true],
            'SwOS SFP+' => [6, 'SFP+1', true],
            'port named Trunk' => [6, 'Trunk', true],
            'Cisco gigabit' => [6, 'GigabitEthernet1/0/1', true],
            'RouterOS ether' => [6, 'ether1', true],
            'port named Local' => [6, 'Local', true],
            'port named Bureau' => [6, 'Bureau 12', true],
            'port named Vlan users' => [6, 'Vl-users', true],
            'Cisco port-channel' => [6, 'Po1', false],
            'port-channel long' => [6, 'Port-channel10', false],
            'VLAN interface' => [6, 'Vlan10', false],
            'short VLAN interface' => [6, 'Vl20', false],
            'loopback' => [6, 'Lo0', false],
            'bridge' => [6, 'bridge1', false],
            'Linux bridge' => [6, 'br0', false],
            'bond' => [6, 'bond0', false],
            'CPU port' => [6, 'CPU', false],
            'management' => [6, 'mgmt0', false],
            'software loopback type' => [24, 'Port1', false],
            'unknown type' => [null, 'Port1', false],
        ];
    }

    #[DataProvider('interfaces')]
    public function test_front_ports_are_told_from_logical_interfaces(?int $type, string $name, bool $physical): void
    {
        $this->assertSame($physical, DevicePoller::isPhysical($type, $name));
    }
}
