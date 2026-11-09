<?php

namespace App\Support\DeviceApi;

use App\Models\NetworkDevice;

/**
 * Registry of the management APIs a device can be read through in addition
 * to SNMP.
 */
class DeviceApis
{
    /** @var array<string, array{label: string, class: class-string<DeviceApiDriver>, port: int, hint: string}> */
    public const DRIVERS = [
        'routeros' => [
            'label' => 'MikroTik RouterOS 7 (REST)',
            'class' => RouterOsRest::class,
            'port' => 443,
            'hint' => 'Activez le service www-ssl et créez un utilisateur dont le groupe a les droits read, api et rest-api.',
        ],
    ];

    public static function for(NetworkDevice $device): ?DeviceApiDriver
    {
        $driver = self::DRIVERS[$device->api_driver ?? ''] ?? null;

        return $driver ? app($driver['class']) : null;
    }

    /**
     * Expands a VLAN list such as "10,20,100-102" into VLAN numbers.
     *
     * @return list<int>
     */
    public static function vlanList(string|int|null $value): array
    {
        $vlans = [];
        foreach (preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (preg_match('/^(\d{1,4})-(\d{1,4})$/', $part, $range) && (int) $range[1] <= (int) $range[2]) {
                $vlans = [...$vlans, ...range(max(1, (int) $range[1]), min(4094, (int) $range[2]))];
            } elseif (ctype_digit($part) && (int) $part >= 1 && (int) $part <= 4094) {
                $vlans[] = (int) $part;
            }
        }
        $vlans = array_values(array_unique($vlans));
        sort($vlans);

        return $vlans;
    }
}
