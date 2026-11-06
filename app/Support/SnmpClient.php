<?php

namespace App\Support;

use App\Models\NetworkDevice;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

/**
 * Reads SNMP values with the net-snmp command line tools.
 */
class SnmpClient
{
    /**
     * @param  list<string>  $oids
     * @return array<string, string> Values keyed by numeric OID without the leading dot.
     */
    public function get(NetworkDevice $device, array $oids): array
    {
        return self::parse($this->run($device, 'snmpget', $oids, false, 10));
    }

    /**
     * @return array<string, string> Values keyed by the OID suffix below $oid.
     */
    public function walk(NetworkDevice $device, string $oid, bool $hex = false): array
    {
        $base = ltrim($oid, '.');
        $values = [];
        foreach (self::parse($this->run($device, 'snmpbulkwalk', [$oid], $hex, 60)) as $key => $value) {
            if (str_starts_with($key, $base.'.')) {
                $values[substr($key, strlen($base) + 1)] = $value;
            }
        }

        return $values;
    }

    /**
     * Parses `-On -Oq` output, including hexadecimal values wrapped over several lines.
     *
     * @return array<string, string>
     */
    public static function parse(string $output): array
    {
        $values = [];
        $current = null;
        foreach (preg_split('/\R/', $output) as $line) {
            if (preg_match('/^\.(\d+(?:\.\d+)+)(?: (.*))?$/', $line, $matches)) {
                $current = $matches[1];
                $values[$current] = $matches[2] ?? '';
            } elseif ($current !== null && trim($line) !== '') {
                $values[$current] .= ' '.trim($line);
            }
        }

        $parsed = [];
        foreach ($values as $oid => $value) {
            $value = trim($value);
            if ($value === '' || preg_match('/^(No Such (Object|Instance)|No more variables)/i', $value)) {
                continue;
            }
            if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
            }
            $parsed[$oid] = trim($value);
        }

        return $parsed;
    }

    /** @return list<int> */
    public static function bytes(string $hex): array
    {
        $hex = preg_replace('/[^0-9a-f]/i', '', $hex);

        return array_map('hexdec', str_split($hex, 2) ?: []);
    }

    /** Formats six decimal or hexadecimal bytes as a lowercase MAC address. */
    public static function mac(array $bytes): ?string
    {
        if (count($bytes) !== 6) {
            return null;
        }

        return implode(':', array_map(fn (int|string $byte): string => sprintf('%02x', (int) $byte), $bytes));
    }

    /**
     * Lists the 1-based port numbers set in an SNMP PortList bitmap.
     *
     * @return list<int>
     */
    public static function portList(string $hex): array
    {
        $ports = [];
        foreach (self::bytes($hex) as $offset => $byte) {
            for ($bit = 0; $bit < 8; $bit++) {
                if ($byte & (0x80 >> $bit)) {
                    $ports[] = $offset * 8 + $bit + 1;
                }
            }
        }

        return $ports;
    }

    /**
     * @param  list<string>  $oids
     */
    private function run(NetworkDevice $device, string $tool, array $oids, bool $hex, int $timeout): string
    {
        $credentials = $device->credentials ?? [];
        $command = [$tool, '-On', '-Oq', '-Oe', '-Ot', '-t', '2', '-r', '1'];
        if ($hex) {
            $command[] = '-Ox';
        }

        if ($device->snmp_version === '3') {
            $level = $credentials['security_level'] ?? 'authPriv';
            array_push($command, '-v3', '-l', $level, '-u', (string) ($credentials['username'] ?? ''));
            if ($level !== 'noAuthNoPriv') {
                array_push($command, '-a', $credentials['auth_protocol'] ?? 'SHA', '-A', (string) ($credentials['auth_password'] ?? ''));
            }
            if ($level === 'authPriv') {
                array_push($command, '-x', $credentials['privacy_protocol'] ?? 'AES', '-X', (string) ($credentials['privacy_password'] ?? ''));
            }
        } else {
            array_push($command, '-v2c', '-c', (string) ($credentials['community'] ?? 'public'));
        }

        $host = IpNetwork::address($device->host);
        $command[] = ($host !== null && str_contains($host, ':') ? 'udp6:['.$host.']' : $device->host).':'.$device->port;
        array_push($command, ...$oids);

        try {
            $result = Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException) {
            throw new RuntimeException(__('L’équipement n’a pas répondu à temps.'));
        } catch (ProcessStartFailedException) {
            throw new RuntimeException(__('Les outils net-snmp sont introuvables sur le serveur.'));
        }

        if ($result->exitCode() === 127) {
            throw new RuntimeException(__('Les outils net-snmp sont introuvables sur le serveur.'));
        }
        if (! $result->successful()) {
            $error = trim(strtok($result->errorOutput() ?: $result->output(), "\n") ?: '');
            throw new RuntimeException(str_contains($error, 'Timeout') ? __('L’équipement ne répond pas en SNMP. Vérifiez l’adresse, le port et les identifiants.') : mb_substr($error ?: __('Échec de la requête SNMP.'), 0, 200));
        }

        return $result->output();
    }
}
