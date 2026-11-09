<?php

namespace App\Support;

/**
 * Guesses the physical connector of a switch port. SNMP reports almost every
 * port as Ethernet, so the port name comes first, then the hardware inventory
 * text, then the speed. A generic name such as eth1/0/25 (D-Link) says
 * nothing about the connector. Administrators can override the result per
 * port (DevicePort::$media_override).
 */
final class PortMedia
{
    public const RJ45 = 'rj45';

    public const SFP = 'sfp';

    public const QSFP = 'qsfp';

    /** @var array<string, string> */
    public const LABELS = ['rj45' => 'RJ45', 'sfp' => 'SFP', 'qsfp' => 'QSFP'];

    public static function detect(?string $name, ?string $descr, ?int $speedMbps, ?string $inventory = null): string
    {
        $label = strtolower(trim(($name ?? '').' '.($descr ?? '')));

        if (preg_match('/qsfp|^(fortygig|hundredgig|twohundredgig|fourhundredgig|(fo|hu)\d|et-\d)/', $label)) {
            return self::QSFP;
        }
        if (preg_match('/sfp|xfp|^(tengig|twentyfivegig|(te|twe)\d|xe-\d)/', $label)) {
            return self::SFP;
        }
        if (preg_match('/^(ether\d|gigabitethernet|fastethernet|(gi|fa)\d|ge-\d)/', $label)
            && ($inventory === null || self::fromInventory($inventory) === null)) {
            return self::RJ45;
        }

        if ($inventory !== null && ($media = self::fromInventory($inventory))) {
            return $media;
        }

        return match (true) {
            $speedMbps !== null && $speedMbps >= 40000 => self::QSFP,
            $speedMbps !== null && $speedMbps >= 10000 => self::SFP,
            default => self::RJ45,
        };
    }

    private static function fromInventory(string $text): ?string
    {
        $text = strtolower($text);

        return match (true) {
            (bool) preg_match('/qsfp/', $text) => self::QSFP,
            (bool) preg_match('/sfp|xfp|transceiver|fib(er|re)|base-?(s|l|e|z|c|b)x|\b(sr|lr|er|zr)4?\b/', $text) => self::SFP,
            (bool) preg_match('/rj-?45|base-?t\b|copper|10\/100/', $text) => self::RJ45,
            default => null,
        };
    }
}
