<?php

namespace App\Support;

use App\Models\User;

class TwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        $secret = '';
        foreach (str_split(random_bytes(32)) as $byte) {
            $secret .= self::ALPHABET[ord($byte) & 31];
        }

        return $secret;
    }

    public function matchingStep(string $secret, string $code): ?int
    {
        if (! preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }

        $step = intdiv(now()->timestamp, 30);
        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            if (hash_equals($this->code($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    public function code(string $secret, int $step): string
    {
        $key = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($secret) as $symbol) {
            $buffer = ($buffer << 5) | strpos(self::ALPHABET, $symbol);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $key .= chr($buffer >> $bits);
                $buffer &= (1 << $bits) - 1;
            }
        }

        $digest = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($digest[19]) & 15;
        $value = unpack('N', substr($digest, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        return array_map(fn (): string => strtoupper(bin2hex(random_bytes(10))), range(1, 8));
    }

    public static function fingerprint(User $user): string
    {
        return hash('sha256', $user->two_factor['secret']);
    }

    /** The caller holds a write lock on the user until the update is saved. */
    public function consume(User $user, string $code): bool
    {
        $settings = $user->two_factor;
        $step = $this->matchingStep($settings['secret'], $code);
        if ($step !== null && $step > $settings['step']) {
            $settings['step'] = $step;
        } else {
            $hash = hash('sha256', strtoupper($code));
            $index = array_search($hash, $settings['recovery'], true);
            if ($index === false) {
                return false;
            }
            unset($settings['recovery'][$index]);
            $settings['recovery'] = array_values($settings['recovery']);
        }
        $user->forceFill(['two_factor' => $settings])->save();

        return true;
    }
}
