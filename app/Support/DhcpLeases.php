<?php

namespace App\Support;

use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\Subnet;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Imports active DHCP leases from Kea or an ISC dhcpd leases file to learn the
 * host names and MAC addresses that clients announce.
 */
class DhcpLeases
{
    public function __construct(private AddressHistory $history) {}

    /** @return array{enabled: bool, kea_url: string, kea_username: string, kea_password: string, leases_file: string, interval: int} */
    public static function settings(): array
    {
        return Setting::read('dhcp') + ['enabled' => false, 'kea_url' => '', 'kea_username' => '', 'kea_password' => '', 'leases_file' => '', 'interval' => 15];
    }

    /** @return array{leases: int, named: int, created: int} */
    public function import(): array
    {
        $settings = self::settings();
        $leases = [];
        if (filled($settings['kea_url'])) {
            $leases += $this->fromKea($settings);
        }
        if (filled($settings['leases_file'])) {
            $leases += $this->fromFile($settings['leases_file']);
        }

        $summary = ['leases' => count($leases), 'named' => 0, 'created' => 0];
        if ($leases === []) {
            return $summary;
        }

        $subnets = Subnet::with('childSubnets')->where('cidr', 'not like', '%:%')->get()
            ->sortByDesc(fn (Subnet $subnet): int => (int) explode('/', $subnet->cidr)[1]);
        $now = now();
        foreach ($leases as $address => ['mac' => $mac, 'hostname' => $hostname]) {
            $records = IpAddress::where('address', $address)->get();
            if ($records->isEmpty()) {
                $subnet = $subnets->first(fn (Subnet $subnet): bool => IpNetwork::assignable($subnet->cidr, $address)
                    && ! $subnet->childSubnets->contains(fn (Subnet $child): bool => IpNetwork::contains($child->cidr, $address)));
                if (! $subnet) {
                    continue;
                }
                $records = collect([$subnet->addresses()->create(['address' => $address, 'status' => 'used'])]);
                $summary['created']++;
            }

            foreach ($records as $record) {
                $this->history->observe($record, $mac, HostnameResolver::clean($hostname), 'dhcp', $now);
                if ($mac !== null) {
                    $record->mac_address = $mac;
                }
                if (HostnameResolver::assign($record, $hostname, 'dhcp')) {
                    $summary['named']++;
                }
                $record->save();
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, array{mac: ?string, hostname: ?string}>
     */
    private function fromKea(array $settings): array
    {
        try {
            $request = Http::timeout(15)->acceptJson();
            if (filled($settings['kea_username'])) {
                $request = $request->withBasicAuth($settings['kea_username'], (string) $settings['kea_password']);
            }
            $response = $request->post($settings['kea_url'], ['command' => 'lease4-get-all', 'service' => ['dhcp4']])->throw()->json();
        } catch (Throwable) {
            throw new RuntimeException(__('Le serveur Kea n’a pas répondu. Vérifiez l’URL de l’agent de contrôle et les identifiants.'));
        }

        $leases = [];
        foreach ((array) $response as $answer) {
            if (($answer['result'] ?? 1) !== 0) {
                continue;
            }
            foreach ($answer['arguments']['leases'] ?? [] as $lease) {
                $address = IpNetwork::address((string) ($lease['ip-address'] ?? ''));
                if ($address && (int) ($lease['state'] ?? 0) === 0) {
                    $leases[$address] = ['mac' => MacAddress::normalize((string) ($lease['hw-address'] ?? '')), 'hostname' => $lease['hostname'] ?? null];
                }
            }
        }

        return $leases;
    }

    /** @return array<string, array{mac: ?string, hostname: ?string}> */
    private function fromFile(string $path): array
    {
        if (! is_readable($path) || ($content = file_get_contents($path)) === false) {
            throw new RuntimeException(__('Le fichier de baux :path est illisible pour le worker.', ['path' => $path]));
        }

        // The file is a journal: the last block of an address wins.
        $leases = [];
        preg_match_all('/lease\s+([0-9.]+)\s*\{(.*?)\}/s', $content, $blocks, PREG_SET_ORDER);
        foreach ($blocks as [, $ip, $body]) {
            $address = IpNetwork::address($ip);
            if (! $address) {
                continue;
            }
            if (! preg_match('/^\s*binding state active;/m', $body)) {
                unset($leases[$address]);

                continue;
            }
            preg_match('/hardware ethernet ([0-9a-f:]+);/i', $body, $mac);
            preg_match('/client-hostname "([^"]*)";/', $body, $name);
            $leases[$address] = ['mac' => isset($mac[1]) ? MacAddress::normalize($mac[1]) : null, 'hostname' => $name[1] ?? null];
        }

        return $leases;
    }
}
