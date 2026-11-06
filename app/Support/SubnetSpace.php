<?php

namespace App\Support;

use App\Models\Subnet;
use Illuminate\Support\Collection;

class SubnetSpace
{
    /** @return Collection<int, string> */
    private function existingBlocks(Subnet $parent): Collection
    {
        return Subnet::where('scope_id', $parent->location_id ?? 0)->whereKeyNot($parent->id)->pluck('cidr')
            ->filter(fn (string $cidr): bool => IpNetwork::encompasses($parent->cidr, $cidr));
    }

    /** @return array<int, list<array{cidr: string, occupied: bool}>> */
    public function map(Subnet $parent): array
    {
        [$network, $prefix] = explode('/', $parent->cidr);
        $maximum = str_contains($network, ':') ? 128 : 32;
        $prefix = (int) $prefix;
        $blocks = $this->existingBlocks($parent);
        $lastPrefix = min($prefix + 6, $maximum);
        $addressBlocks = [];
        $rows = [];
        if ($lastPrefix === $prefix) {
            return $rows;
        }

        foreach ($parent->addresses()->select('address')->cursor() as $address) {
            for ($length = $prefix + 1; $length <= $lastPrefix; $length++) {
                $addressBlocks[IpNetwork::normalize($address->address.'/'.$length)] = true;
            }
        }

        for ($length = $prefix + 1; $length <= $lastPrefix; $length++) {
            $rows[$length] = array_map(fn (string $cidr): array => [
                'cidr' => $cidr,
                'occupied' => isset($addressBlocks[$cidr]) || $blocks->contains(fn (string $other): bool => $this->overlaps($cidr, $other)),
            ], IpNetwork::split($parent->cidr, $length));
        }

        return $rows;
    }

    public function canAllocate(Subnet $parent, string $cidr): bool
    {
        if (! IpNetwork::encompasses($parent->cidr, $cidr)
            || $this->existingBlocks($parent)->contains(fn (string $other): bool => $this->overlaps($cidr, $other))) {
            return false;
        }

        $bounds = IpNetwork::details($cidr);
        $first = explode('/', $cidr)[0];
        $last = $bounds['broadcast'] ?? $bounds['last'];

        return ! $parent->addresses()->whereBetween('address_key', [bin2hex(inet_pton($first)), bin2hex(inet_pton($last))])->exists();
    }

    public function delegated(Subnet $parent, string $address): bool
    {
        return $parent->childSubnets()->pluck('cidr')->contains(fn (string $cidr): bool => IpNetwork::contains($cidr, $address));
    }

    private function overlaps(string $left, string $right): bool
    {
        return $left === $right || IpNetwork::encompasses($left, $right) || IpNetwork::encompasses($right, $left);
    }
}
