<?php

namespace App\Support;

final class IpNetwork
{
    public static function address(string $input): ?string
    {
        return filter_var($input, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($input)) : null;
    }

    public static function normalize(string $cidr): ?string
    {
        if (! preg_match('/^([^\/]+)\/(\d{1,3})$/D', $cidr, $parts)) {
            return null;
        }

        $address = self::address($parts[1]);
        $prefix = (int) $parts[2];

        if ($address === null || $prefix > (str_contains($address, ':') ? 128 : 32)) {
            return null;
        }

        return inet_ntop(self::mask(inet_pton($address), $prefix)).'/'.$prefix;
    }

    public static function contains(string $cidr, string $address): bool
    {
        [$network, $prefix] = explode('/', $cidr);
        $packed = inet_pton($address);
        $network = inet_pton($network);

        return strlen($network) === strlen($packed) && self::mask($packed, (int) $prefix) === $network;
    }

    public static function capacity(string $cidr): int|float
    {
        [$address, $prefix] = explode('/', $cidr);
        $bits = str_contains($address, ':') ? 128 : 32;
        $capacity = 2 ** ($bits - (int) $prefix);

        return $bits === 32 && (int) $prefix < 31 ? $capacity - 2 : $capacity;
    }

    public static function assignable(string $cidr, string $address): bool
    {
        if (! self::contains($cidr, $address)) {
            return false;
        }

        [$network, $prefix] = explode('/', $cidr);
        if (str_contains($network, ':') || (int) $prefix >= 31) {
            return true;
        }

        $broadcast = long2ip(ip2long($network) + self::capacity($cidr) + 1);

        return $address !== $network && $address !== $broadcast;
    }

    /** @return list<string> */
    public static function enumerate(string $cidr, int $limit): array
    {
        $count = self::capacity($cidr);
        if ($count > $limit) {
            return [];
        }

        [$network, $prefix] = explode('/', $cidr);
        $packed = inet_pton($network);
        $skipNetwork = strlen($packed) === 4 && (int) $prefix < 31;
        $addresses = [];

        for ($index = (int) $skipNetwork; $index < $count + (int) $skipNetwork; $index++) {
            $candidate = $packed;
            $carry = $index;
            for ($offset = strlen($candidate) - 1; $carry > 0; $offset--) {
                $sum = ord($candidate[$offset]) + $carry;
                $candidate[$offset] = chr($sum & 255);
                $carry = $sum >> 8;
            }
            $addresses[] = inet_ntop($candidate);
        }

        return $addresses;
    }

    /** @return array{cidr: string, version: string, mask: ?string, broadcast: ?string, first: string, last: string, capacity: string} */
    public static function details(string $cidr): array
    {
        [$network, $prefix] = explode('/', $cidr);
        $first = inet_pton($network);
        $ipv4 = strlen($first) === 4;
        $allBits = str_repeat("\xff", strlen($first));
        $mask = self::mask($allBits, (int) $prefix);
        $last = $first | ($allBits ^ $mask);
        $broadcast = null;

        if ($ipv4 && (int) $prefix < 31) {
            $broadcast = inet_ntop($last);
            $first[-1] = chr(ord($first[-1]) + 1);
            $last[-1] = chr(ord($last[-1]) - 1);
        }

        return [
            'cidr' => $cidr, 'version' => $ipv4 ? 'IPv4' : 'IPv6',
            'mask' => $ipv4 ? inet_ntop($mask) : null, 'broadcast' => $broadcast,
            'first' => inet_ntop($first), 'last' => inet_ntop($last),
            'capacity' => sprintf('%.0f', self::capacity($cidr)),
        ];
    }

    public static function encompasses(string $parent, string $child): bool
    {
        [, $parentPrefix] = explode('/', $parent);
        [$network, $childPrefix] = explode('/', $child);

        return (int) $childPrefix > (int) $parentPrefix && self::contains($parent, $network);
    }

    /** @return list<string> */
    public static function split(string $cidr, int $prefix): array
    {
        [$address, $original] = explode('/', $cidr);
        $original = (int) $original;
        $network = inet_pton($address);
        $difference = $prefix - $original;
        if ($difference < 1 || $difference > 6 || $prefix > strlen($network) * 8) {
            return [];
        }

        $blocks = [];
        for ($index = 0; $index < 2 ** $difference; $index++) {
            $base = $network;
            for ($bit = $original; $bit < $prefix; $bit++) {
                $offset = intdiv($bit, 8);
                $value = (($index >> ($prefix - $bit - 1)) & 1) << (7 - $bit % 8);
                $base[$offset] = chr(ord($base[$offset]) | $value);
            }
            $blocks[] = inet_ntop($base).'/'.$prefix;
        }

        return $blocks;
    }

    private static function mask(string $packed, int $prefix): string
    {
        for ($offset = 0; $offset < strlen($packed); $offset++) {
            $bits = max(0, min(8, $prefix - $offset * 8));
            $packed[$offset] = chr(ord($packed[$offset]) & (255 << (8 - $bits)));
        }

        return $packed;
    }
}
