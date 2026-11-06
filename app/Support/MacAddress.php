<?php

namespace App\Support;

class MacAddress
{
    public static function normalize(string $input): ?string
    {
        $input = trim($input);
        if (! preg_match('/\A(?:[a-f0-9]{12}|(?:[a-f0-9]{2}:){5}[a-f0-9]{2}|(?:[a-f0-9]{2}-){5}[a-f0-9]{2}|(?:[a-f0-9]{4}\.){2}[a-f0-9]{4})\z/i', $input)) {
            return null;
        }

        return implode(':', str_split(strtolower(str_replace([':', '-', '.'], '', $input)), 2));
    }

    /** @return array{kind: string, organization?: string, prefix?: string, registry?: string} */
    public function lookup(string $mac, string $directory = '/usr/share/ieee-data'): array
    {
        $hex = strtoupper(str_replace(':', '', $mac));
        $firstByte = hexdec(substr($hex, 0, 2));
        if (($firstByte & 1) !== 0) {
            return ['kind' => 'group'];
        }
        if (($firstByte & 2) !== 0) {
            return ['kind' => 'local'];
        }

        $available = false;
        foreach (['oui36' => 9, 'iab' => 9, 'mam' => 7, 'oui' => 6] as $file => $length) {
            $path = $directory.'/'.$file.'.csv';
            if (! is_readable($path) || ! ($stream = fopen($path, 'r'))) {
                continue;
            }

            $available = true;
            $prefix = substr($hex, 0, $length);
            try {
                while (($row = fgetcsv($stream, escape: '')) !== false) {
                    if (($row[1] ?? null) === $prefix && filled($row[2] ?? null)) {
                        return ['kind' => 'found', 'organization' => $row[2], 'prefix' => $prefix, 'registry' => $row[0]];
                    }
                }
            } finally {
                fclose($stream);
            }
        }

        return ['kind' => $available ? 'unknown' : 'unavailable'];
    }
}
