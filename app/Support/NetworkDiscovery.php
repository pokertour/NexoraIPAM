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
     * @return array<string, array{reachable: bool, hostname: ?string, source: ?string, mac: ?string}>
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
                $results[$address] = ['reachable' => $result->successful(), 'hostname' => null, 'source' => null, 'mac' => null];
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
                        $results[$address]['source'] = 'dns';
                    }
                }
            }

            $unnamed = array_values(array_filter($responsive, fn (string $address): bool => $results[$address]['hostname'] === null));
            foreach (['netbios' => fn (string $address): array => ['nmblookup', '-A', $address], 'mdns' => fn (string $address): array => ['dig', '+short', '+time=1', '+tries=1', '-p', '5353', '@'.$address, '-x', $address]] as $source => $command) {
                $unnamed = array_values(array_filter($unnamed, fn (string $address): bool => $results[$address]['hostname'] === null));
                foreach ($this->askNames($unnamed, $command, $source) as $address => $hostname) {
                    $results[$address]['hostname'] = $hostname;
                    $results[$address]['source'] = $source;
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

    /**
     * Asks hosts for their own name over NetBIOS or multicast DNS. Missing tools
     * and silent hosts are skipped: these sources only complement DNS.
     *
     * @param  list<string>  $addresses
     * @param  \Closure(string): list<string>  $command
     * @return array<string, string>
     */
    private function askNames(array $addresses, \Closure $command, string $source): array
    {
        if ($addresses === []) {
            return [];
        }

        try {
            $processes = Process::pool(function (Pool $pool) use ($addresses, $command): void {
                foreach ($addresses as $address) {
                    $pool->as($address)->timeout(4)->command($command($address));
                }
            })->start();
        } catch (ProcessStartFailedException) {
            return [];
        }

        $names = [];
        foreach ($processes as $address => $process) {
            try {
                $result = $process->wait();
            } catch (ProcessTimedOutException|ProcessStartFailedException) {
                continue;
            }
            if (! $result->successful()) {
                continue;
            }
            $name = $source === 'netbios' ? self::netbiosName($result->output()) : strtok(trim($result->output()), "\n");
            if ($name = HostnameResolver::clean($name ?: null)) {
                $names[$address] = $name;
            }
        }

        return $names;
    }

    /** Reads the workstation name from `nmblookup -A`, ignoring group names. */
    public static function netbiosName(string $output): ?string
    {
        foreach (preg_split('/\R/', $output) as $line) {
            if (preg_match('/^\s*([^\s<]+)\s+<00>\s+-\s+(?!<GROUP>)/', $line, $matches)) {
                return $matches[1];
            }
        }

        return null;
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
