<?php

namespace App\Support\DeviceApi;

use App\Models\NetworkDevice;
use Closure;
use RuntimeException;

/**
 * MikroTik RouterOS binary API, served by the api-ssl service (TLS, port
 * 8729). Works with RouterOS 6.43 and later, unlike the REST API.
 *
 * The protocol exchanges sentences: words prefixed with their encoded
 * length, ended by an empty word. Replies start with !re (one row), !done,
 * !trap (error) or !fatal.
 *
 * @see https://help.mikrotik.com/docs/spaces/ROS/pages/47579160/API
 */
class RouterOsApi extends RouterOs
{
    protected bool $tls = true;

    protected string $service = 'api-ssl';

    /** @var resource|null */
    private $stream = null;

    /**
     * @param  (Closure(NetworkDevice, bool): resource)|null  $connector  Opens the connection; tests pass a socket pair.
     */
    public function __construct(private ?Closure $connector = null) {}

    protected function read(NetworkDevice $device, array $menus): array
    {
        $this->stream = $this->connect($device);
        try {
            $credentials = $device->api_credentials ?? [];
            $login = $this->command(['/login', '=name='.($credentials['username'] ?? ''), '=password='.($credentials['password'] ?? '')], true);
            if (isset($login['done']['ret'])) {
                throw new RuntimeException(__('Cette version de RouterOS est trop ancienne pour l’API : la version 6.43 ou plus récente est requise.'));
            }

            $rows = [];
            foreach ($menus as $menu) {
                $rows[$menu] = $this->command(['/'.$menu.'/print'])['rows'];
            }

            return $rows;
        } finally {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /** Encodes the length of a word as the RouterOS API expects it. */
    public static function encodeLength(int $length): string
    {
        return match (true) {
            $length < 0x80 => chr($length),
            $length < 0x4000 => pack('n', $length | 0x8000),
            $length < 0x200000 => substr(pack('N', $length | 0xC00000), 1),
            $length < 0x10000000 => pack('N', $length | 0xE0000000),
            default => chr(0xF0).pack('N', $length),
        };
    }

    /** @return resource */
    private function connect(NetworkDevice $device)
    {
        if ($this->connector) {
            return ($this->connector)($device, $this->tls);
        }

        $verify = $this->tls && $device->api_verify_tls;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => ! $verify,
            'peer_name' => $device->host, 'SNI_enabled' => true,
        ]]);
        $address = ($this->tls ? 'tls' : 'tcp').'://'.$this->host($device).':'.($device->api_port ?: ($this->tls ? 8729 : 8728));
        $warnings = [];
        set_error_handler(function (int $level, string $warning) use (&$warnings): bool {
            $warnings[] = $warning;

            return true;
        });
        try {
            $stream = stream_socket_client($address, $code, $message, 5, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            // OpenSSL reports a rejected certificate in a warning, not in the connection error.
            $error = implode(' ', [...$warnings, (string) $message]);
            throw new RuntimeException($this->tls && preg_match('/certificate|SSL operation failed|handshake/i', $error)
                ? __('Le certificat de l’API RouterOS n’est pas reconnu. Installez un certificat valide ou désactivez la vérification.')
                : __('L’API RouterOS est injoignable. Vérifiez que le service :service est activé et le port autorisé.', ['service' => $this->service]));
        }
        stream_set_timeout($stream, 20);

        return $stream;
    }

    /**
     * Sends a command and reads its reply until !done.
     *
     * @param  list<string>  $words
     * @return array{rows: list<array<string, string>>, done: array<string, string>}
     */
    private function command(array $words, bool $login = false): array
    {
        $payload = '';
        foreach ([...$words, ''] as $word) {
            $payload .= self::encodeLength(strlen($word)).$word;
        }
        if (@fwrite($this->stream, $payload) !== strlen($payload)) {
            throw new RuntimeException(__('La connexion à l’API RouterOS a été interrompue.'));
        }

        $rows = [];
        $error = null;
        while (true) {
            $sentence = $this->readSentence();
            $type = array_shift($sentence);
            $attributes = [];
            foreach ($sentence as $word) {
                if (preg_match('/^=([^=]+)=(.*)$/s', $word, $match)) {
                    $attributes[$match[1]] = $match[2];
                }
            }

            match ($type) {
                '!re' => $rows[] = $attributes,
                '!trap' => $error ??= $attributes['message'] ?? '',
                '!fatal' => throw new RuntimeException(__('L’API RouterOS a fermé la session : :message', ['message' => implode(' ', $sentence)])),
                default => null,
            };
            if ($type === '!done') {
                break;
            }
        }

        if ($error !== null) {
            throw new RuntimeException($login
                ? __('L’API RouterOS a refusé les identifiants.')
                : __('L’API RouterOS a refusé la commande :command : :message', ['command' => $words[0], 'message' => $error]));
        }

        return ['rows' => $rows, 'done' => $attributes];
    }

    /** @return list<string> */
    private function readSentence(): array
    {
        $words = [];
        while (($word = $this->readWord()) !== '') {
            $words[] = $word;
        }

        return $words;
    }

    private function readWord(): string
    {
        $first = ord($this->readBytes(1));
        $length = match (true) {
            $first < 0x80 => $first,
            $first < 0xC0 => (($first & 0x3F) << 8) | ord($this->readBytes(1)),
            $first < 0xE0 => (($first & 0x1F) << 16) | unpack('n', $this->readBytes(2))[1],
            $first < 0xF0 => (($first & 0x0F) << 24) | (unpack('N', "\0".$this->readBytes(3))[1]),
            default => unpack('N', $this->readBytes(4))[1],
        };

        return $length === 0 ? '' : $this->readBytes($length);
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if ($meta['timed_out'] || feof($this->stream) || $chunk === false) {
                    throw new RuntimeException(__('La connexion à l’API RouterOS a été interrompue.'));
                }
            }
            $data .= (string) $chunk;
        }

        return $data;
    }
}
