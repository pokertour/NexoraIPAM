<?php

namespace App\Support;

use App\Models\Vlan;
use Illuminate\Support\Collection;

/**
 * Gives every VLAN a stable colour so it can be spotted at a glance.
 */
final class VlanColor
{
    /** @var list<string> */
    public const PALETTE = [
        '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e', '#10b981', '#14b8a6',
        '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899',
    ];

    /** Spreads consecutive VLAN numbers across the colour wheel with the golden angle. */
    public static function automatic(int $number): string
    {
        $hue = fmod($number * 137.508, 360);

        return self::hslToHex($hue, 0.68, 0.52);
    }

    public static function for(int $number, ?string $override = null): string
    {
        return $override ?: self::automatic($number);
    }

    /**
     * Resolves the colours of several VLAN numbers, using the colour chosen in
     * the inventory when one exists.
     *
     * @param  iterable<int|string|null>  $numbers
     * @return array<int, string>
     */
    public static function map(iterable $numbers): array
    {
        $numbers = collect($numbers)->filter(fn (mixed $number): bool => is_numeric($number))->map(fn (mixed $number): int => (int) $number)->unique()->values();
        if ($numbers->isEmpty()) {
            return [];
        }

        /** @var Collection<int, string> $chosen */
        $chosen = Vlan::whereIn('number', $numbers)->whereNotNull('color')->orderBy('id')->get(['number', 'color'])
            ->unique('number')->pluck('color', 'number');

        return $numbers->mapWithKeys(fn (int $number): array => [$number => self::for($number, $chosen->get($number))])->all();
    }

    /** Text colour that stays readable on a VLAN colour: dark on light colours, white otherwise. */
    public static function ink(string $color): string
    {
        if (! preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $color, $matches)) {
            return '#ffffff';
        }
        $luminance = (0.299 * hexdec($matches[1]) + 0.587 * hexdec($matches[2]) + 0.114 * hexdec($matches[3])) / 255;

        return $luminance > 0.6 ? '#111827' : '#ffffff';
    }

    private static function hslToHex(float $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $chroma / 2;
        [$red, $green, $blue] = match (intdiv((int) $hue, 60)) {
            0 => [$chroma, $x, 0], 1 => [$x, $chroma, 0], 2 => [0, $chroma, $x],
            3 => [0, $x, $chroma], 4 => [$x, 0, $chroma], default => [$chroma, 0, $x],
        };

        return sprintf('#%02x%02x%02x', (int) round(($red + $m) * 255), (int) round(($green + $m) * 255), (int) round(($blue + $m) * 255));
    }
}
