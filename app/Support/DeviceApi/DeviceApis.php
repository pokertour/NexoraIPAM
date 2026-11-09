<?php

namespace App\Support\DeviceApi;

use App\Models\NetworkDevice;

/**
 * Registry of the management APIs a device can be read through in addition
 * to SNMP.
 */
class DeviceApis
{
    /**
     * tls tells whether the connection is encrypted; warning is shown in the
     * form when it is not.
     *
     * @var array<string, array{label: string, class: class-string<DeviceApiDriver>, port: int, tls: bool, hint: string, warning?: string}>
     */
    public const DRIVERS = [
        'routeros-api-ssl' => [
            'label' => 'MikroTik RouterOS (API-SSL, port 8729)',
            'class' => RouterOsApi::class,
            'port' => 8729,
            'tls' => true,
            'hint' => 'RouterOS 6.43 ou plus récent. Activez le service api-ssl avec un certificat et créez un utilisateur dont le groupe a les droits read et api.',
        ],
        'routeros' => [
            'label' => 'MikroTik RouterOS 7 (REST, HTTPS)',
            'class' => RouterOsRest::class,
            'port' => 443,
            'tls' => true,
            'hint' => 'RouterOS 7.1 ou plus récent. Activez le service www-ssl et créez un utilisateur dont le groupe a les droits read, api et rest-api.',
        ],
        'routeros-api' => [
            'label' => 'MikroTik RouterOS (API, port 8728)',
            'class' => RouterOsApiPlain::class,
            'port' => 8728,
            'tls' => false,
            'hint' => 'RouterOS 6.43 ou plus récent. Activez le service api et créez un utilisateur dont le groupe a les droits read et api.',
            'warning' => 'Le port 8728 n’est pas chiffré : l’identifiant, le mot de passe et la configuration passent en clair. Préférez API-SSL, ou réservez-le à un réseau d’administration isolé.',
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
