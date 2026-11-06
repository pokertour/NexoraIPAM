<?php

namespace App\Support;

use App\Models\IpAddress;
use App\Models\Subnet;
use Brick\Math\BigInteger;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AddressAvailability
{
    /** @return list<array{address: string, record: ?IpAddress, child: ?Subnet}> */
    public function grid(Subnet $subnet, Collection $children): array
    {
        $addresses = IpNetwork::enumerate($subnet->cidr, 256);
        if ($addresses === []) {
            return [];
        }

        $records = $subnet->addresses()->whereIn('address', $addresses)->get()->keyBy('address');

        return array_map(fn (string $ip): array => [
            'address' => $ip,
            'record' => $records->get($ip),
            'child' => $children->first(fn (Subnet $child): bool => IpNetwork::contains($child->cidr, $ip)),
        ], $addresses);
    }

    /** @return list<IpAddress|array{first: string, last: string, count: string}> */
    public function rows(Subnet $subnet, LengthAwarePaginator $records, Collection $children): array
    {
        if ($records->currentPage() > $records->lastPage()) {
            return [];
        }

        $bounds = IpNetwork::details($subnet->cidr);
        $first = $this->number($bounds['first']);
        $last = $this->number($bounds['last']);
        $width = strlen(bin2hex(inet_pton($bounds['first'])));
        $delegated = $children->sortBy(fn (Subnet $child): string => bin2hex(inet_pton(explode('/', $child->cidr)[0])), SORT_STRING)
            ->map(function (Subnet $child): array {
                $bounds = IpNetwork::details($child->cidr);

                return [$this->number(explode('/', $child->cidr)[0]), $this->number($bounds['broadcast'] ?? $bounds['last'])];
            });

        $cursor = $first;
        if ($records->currentPage() > 1 && $records->isNotEmpty()) {
            $previous = $subnet->addresses()->where('address_key', '<', $records->first()->address_key)->orderByDesc('address_key')->value('address');
            if ($previous !== null) {
                $cursor = BigInteger::max($first, $this->number($previous)->plus(1));
            }
        }

        $rows = [];
        foreach ($records as $record) {
            $position = $this->number($record->address);
            $this->appendGaps($rows, $cursor, BigInteger::min($last, $position->minus(1)), $delegated, $width);
            $rows[] = $record;
            $cursor = BigInteger::max($cursor, $position->plus(1));
        }

        if (! $records->hasMorePages()) {
            $this->appendGaps($rows, $cursor, $last, $delegated, $width);
        }

        return $rows;
    }

    private function appendGaps(array &$rows, BigInteger $first, BigInteger $last, Collection $delegated, int $width): void
    {
        if ($first->isGreaterThan($last)) {
            return;
        }

        foreach ($delegated as [$blockStart, $blockEnd]) {
            if ($blockEnd->isLessThan($first)) {
                continue;
            }
            if ($blockStart->isGreaterThan($last)) {
                break;
            }
            if ($first->isLessThan($blockStart)) {
                $rows[] = $this->gap($first, $blockStart->minus(1), $width);
            }
            $first = $blockEnd->plus(1);
        }

        if ($first->isLessThanOrEqualTo($last)) {
            $rows[] = $this->gap($first, $last, $width);
        }
    }

    /** @return array{first: string, last: string, count: string} */
    private function gap(BigInteger $first, BigInteger $last, int $width): array
    {
        return [
            'first' => inet_ntop(hex2bin(str_pad($first->toBase(16), $width, '0', STR_PAD_LEFT))),
            'last' => inet_ntop(hex2bin(str_pad($last->toBase(16), $width, '0', STR_PAD_LEFT))),
            'count' => (string) $last->minus($first)->plus(1),
        ];
    }

    private function number(string $address): BigInteger
    {
        return BigInteger::fromBase(bin2hex(inet_pton($address)), 16);
    }
}
