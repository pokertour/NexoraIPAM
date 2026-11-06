<?php

namespace App\Support;

use App\Models\Subnet;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

class NetworkDiscovery
{
    public const LIMIT = 256;

    /** @return list<string> */
    public function targets(Subnet $subnet): array
    {
        $addresses = IpNetwork::enumerate($subnet->cidr, self::LIMIT);
        if ($addresses === []) {
            $addresses = $subnet->addresses()->orderBy('id')->limit(self::LIMIT)->pluck('address')->all();
        }

        $delegated = $subnet->childSubnets()->pluck('cidr');

        return array_values(array_filter($addresses, fn (string $address): bool => IpNetwork::assignable($subnet->cidr, $address)
            && ! $delegated->contains(fn (string $cidr): bool => IpNetwork::contains($cidr, $address))
            && ! str_starts_with($address, '::ffff:')
            && ! in_array($address, ['0.0.0.0', '255.255.255.255', '::'], true)
            && ! IpNetwork::contains('224.0.0.0/4', $address)
            && ! IpNetwork::contains('ff00::/8', $address)
            && ! IpNetwork::contains('fe80::/10', $address)));
    }

    /** @param list<string> $addresses
     * @return array<string, array{reachable: bool, hostname: ?string, mac: ?string}>
     */
    public function probe(array $addresses): array
    {
        $results = [];
        foreach (array_chunk($addresses, 32) as $batch) {
            $pings = Process::pool(function (Pool $pool) use ($batch): void {
                foreach ($batch as $address) {
                    $pool->as($address)->timeout(3)->command(['ping', '-n', '-c', '1', '-W', '1', $address]);
                }
            })->run();

            $responsive = [];
            foreach ($batch as $address) {
                $result = $pings[$address];
                if (! in_array($result->exitCode(), [0, 1], true)) {
                    throw new RuntimeException('La sonde ICMP a échoué. Vérifiez les permissions du worker et le réseau.');
                }
                $results[$address] = ['reachable' => $result->successful(), 'hostname' => null, 'mac' => null];
                if ($result->successful()) {
                    $responsive[] = $address;
                }
            }

            $names = Process::pool(function (Pool $pool) use ($responsive): void {
                foreach ($responsive as $address) {
                    $pool->as($address)->env(['RES_OPTIONS' => 'attempts:1 timeout:1'])->timeout(3)
                        ->command(['getent', 'hosts', $address]);
                }
            })->start();

            foreach ($names as $address => $process) {
                try {
                    $result = $process->wait();
                } catch (ProcessTimedOutException) {
                    continue;
                }

                if ($result->exitCode() === 127) {
                    throw new RuntimeException('La résolution inverse nécessite getent.');
                }

                if ($result->successful() && preg_match('/^\S+\s+([^\s]{1,253})/', $result->output(), $matches)) {
                    $hostname = rtrim($matches[1], '.');
                    if (filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && ! IpNetwork::address($hostname)) {
                        $results[$address]['hostname'] = $hostname;
                    }
                }
            }
        }

        if (array_filter($results, fn (array $result): bool => $result['reachable'])) {
            foreach ($this->neighborMacs() as $address => $mac) {
                if ($results[$address]['reachable'] ?? false) {
                    $results[$address]['mac'] = $mac;
                }
            }
        }

        return $results;
    }

    /** @return array<string, string> */
    private function neighborMacs(): array
    {
        try {
            $response = Process::timeout(2)->run(['ip', '-j', 'neigh', 'show']);
        } catch (ProcessTimedOutException|ProcessStartFailedException) {
            return [];
        }

        $neighbors = $response->successful() ? json_decode($response->output(), true) : null;
        $byAddress = [];
        foreach (is_array($neighbors) ? $neighbors : [] as $neighbor) {
            $address = IpNetwork::address($neighbor['dst'] ?? '');
            $mac = MacAddress::normalize($neighbor['lladdr'] ?? '');
            $state = (array) ($neighbor['state'] ?? []);
            if ($address && $mac && array_intersect($state, ['REACHABLE', 'STALE', 'DELAY', 'PROBE', 'PERMANENT'])) {
                $byAddress[$address][$mac] = true;
            }
        }

        $resolved = [];
        foreach ($byAddress as $address => $matches) {
            if (count($matches) === 1) {
                $resolved[$address] = array_key_first($matches);
            }
        }

        return $resolved;
    }
}
