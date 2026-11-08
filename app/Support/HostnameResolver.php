<?php

namespace App\Support;

use App\Models\IpAddress;

/**
 * Keeps the discovered host name of an address from the most reliable source.
 */
final class HostnameResolver
{
    /** Sources from the most to the least reliable. @var array<string, string> */
    public const SOURCES = [
        'dns' => 'DNS inverse', 'dhcp' => 'Bail DHCP', 'netbios' => 'NetBIOS', 'mdns' => 'mDNS',
        'lldp' => 'Voisin LLDP', 'snmp' => 'SNMP',
    ];

    /**
     * Sets the discovered name unless a more reliable source already named the
     * address. Does not save the model. Returns whether the name changed.
     */
    public static function assign(IpAddress $address, ?string $name, string $source): bool
    {
        $name = self::clean($name);
        if ($name === null || ! isset(self::SOURCES[$source])) {
            return false;
        }

        $current = $address->hostname_source;
        $rank = array_flip(array_keys(self::SOURCES));
        if ($address->resolved_hostname !== null && $current !== null && isset($rank[$current]) && $rank[$current] < $rank[$source]) {
            return false;
        }
        if ($address->resolved_hostname === $name && $current === $source) {
            return false;
        }

        $address->resolved_hostname = $name;
        $address->hostname_source = $source;

        return true;
    }

    public static function clean(?string $name): ?string
    {
        $name = strtolower(rtrim(trim((string) $name), '.'));
        if ($name === '' || strlen($name) > 253 || IpNetwork::address($name) || ! filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        return $name;
    }
}
