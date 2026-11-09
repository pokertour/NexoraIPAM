<?php

namespace App\Support\DeviceApi;

use App\Models\NetworkDevice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MikroTik RouterOS 7 REST API (/rest), served by the www-ssl service.
 */
class RouterOsRest extends RouterOs
{
    protected function read(NetworkDevice $device, array $menus): array
    {
        $credentials = $device->api_credentials ?? [];
        $request = Http::baseUrl('https://'.$this->host($device).':'.($device->api_port ?: 443).'/rest/')
            ->withBasicAuth((string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? ''))
            // Without verification, also accept the weak keys and signatures of older RouterOS certificates.
            ->withOptions(['verify' => $device->api_verify_tls] + ($device->api_verify_tls ? [] : ['curl' => [CURLOPT_SSL_CIPHER_LIST => 'DEFAULT:@SECLEVEL=0']]))
            ->acceptJson()->connectTimeout(5)->timeout(20);

        $rows = [];
        foreach ($menus as $menu) {
            try {
                $response = $request->get($menu)->throw()->json();
            } catch (RequestException $exception) {
                throw new RuntimeException(in_array($exception->response->status(), [401, 403], true)
                    ? __('L’API RouterOS a refusé les identifiants.')
                    : __('L’API RouterOS a répondu :status sur :path.', ['status' => $exception->response->status(), 'path' => '/rest/'.$menu]));
            } catch (ConnectionException $exception) {
                throw $this->connectionFailure($device, $exception->getMessage(), 'www-ssl');
            }
            $rows[$menu] = is_array($response) ? array_values(array_filter($response, 'is_array')) : [];
        }

        return $rows;
    }
}
