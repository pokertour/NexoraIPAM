<?php

namespace App\Support\DeviceApi;

use App\Models\NetworkDevice;
use App\Support\IpNetwork;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MikroTik RouterOS 7 REST API (/rest), which exposes the bridge VLAN table
 * that RouterOS does not publish over SNMP (Q-BRIDGE-MIB).
 */
class RouterOsRest implements DeviceApiDriver
{
    public function vlanMembership(NetworkDevice $device): array
    {
        $request = $this->request($device);
        $ports = [];
        $add = function (string $names, int $vlan, string $kind) use (&$ports): void {
            foreach (preg_split('/\s*,\s*/', trim($names), -1, PREG_SPLIT_NO_EMPTY) as $name) {
                $ports[$name] ??= ['pvid' => null, 'tagged' => [], 'untagged' => []];
                $ports[$name][$kind][] = $vlan;
            }
        };

        foreach ($this->get($request, 'interface/bridge/vlan') as $row) {
            if ($this->disabled($row)) {
                continue;
            }
            // current-* include the dynamic members RouterOS adds from the PVID of each port.
            $tagged = (string) ($row['current-tagged'] ?? $row['tagged'] ?? '');
            $untagged = (string) ($row['current-untagged'] ?? $row['untagged'] ?? '');
            foreach (DeviceApis::vlanList($row['vlan-ids'] ?? null) as $vlan) {
                $add($tagged, $vlan, 'tagged');
                $add($untagged, $vlan, 'untagged');
            }
        }

        foreach ($this->get($request, 'interface/vlan') as $row) {
            $vlan = DeviceApis::vlanList($row['vlan-id'] ?? null)[0] ?? null;
            if (! $this->disabled($row) && $vlan && filled($row['interface'] ?? null)) {
                $add((string) $row['interface'], $vlan, 'tagged');
            }
        }

        foreach ($this->get($request, 'interface/bridge/port') as $row) {
            $pvid = DeviceApis::vlanList($row['pvid'] ?? null)[0] ?? null;
            if (! $this->disabled($row) && $pvid && filled($row['interface'] ?? null)) {
                $ports[(string) $row['interface']] ??= ['pvid' => null, 'tagged' => [], 'untagged' => []];
                $ports[(string) $row['interface']]['pvid'] = $pvid;
            }
        }

        return array_map(function (array $port): array {
            foreach (['tagged', 'untagged'] as $kind) {
                $port[$kind] = array_values(array_unique($port[$kind]));
                sort($port[$kind]);
            }
            $port['tagged'] = array_values(array_diff($port['tagged'], $port['untagged']));

            return $port;
        }, $ports);
    }

    private function request(NetworkDevice $device): PendingRequest
    {
        $credentials = $device->api_credentials ?? [];
        $host = IpNetwork::address($device->host) && str_contains($device->host, ':') ? '['.$device->host.']' : $device->host;

        return Http::baseUrl('https://'.$host.':'.($device->api_port ?: 443).'/rest/')
            ->withBasicAuth((string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? ''))
            ->withOptions(['verify' => $device->api_verify_tls])
            ->acceptJson()->connectTimeout(5)->timeout(20);
    }

    /** @return list<array<string, mixed>> */
    private function get(PendingRequest $request, string $path): array
    {
        try {
            $rows = $request->get($path)->throw()->json();
        } catch (RequestException $exception) {
            throw new RuntimeException(in_array($exception->response->status(), [401, 403], true)
                ? __('L’API RouterOS a refusé les identifiants.')
                : __('L’API RouterOS a répondu :status sur :path.', ['status' => $exception->response->status(), 'path' => '/rest/'.$path]));
        } catch (ConnectionException $exception) {
            throw new RuntimeException(str_contains($exception->getMessage(), 'SSL') || str_contains($exception->getMessage(), 'certificate')
                ? __('Le certificat HTTPS de l’API RouterOS n’est pas reconnu. Installez un certificat valide ou désactivez la vérification.')
                : __('L’API RouterOS est injoignable. Vérifiez que le service www-ssl est activé et le port autorisé.'));
        }

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param  array<string, mixed>  $row */
    private function disabled(array $row): bool
    {
        return in_array($row['disabled'] ?? false, [true, 'true', 'yes'], true);
    }
}
