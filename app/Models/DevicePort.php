<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Port state read from a device by SNMP. Rows are refreshed on every poll;
 * the poll itself is recorded in the audit trail. media_override is set by
 * an administrator and never touched by the poll.
 */
class DevicePort extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tagged_vlans' => 'array', 'untagged_vlans' => 'array', 'physical' => 'boolean', 'polled_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id');
    }

    public function macs(): HasMany
    {
        return $this->hasMany(DevicePortMac::class);
    }

    /** Connector shown for the port: the administrator's choice, else the detected one. */
    public function connector(): ?string
    {
        return $this->media_override ?? $this->media;
    }

    public function isUp(): bool
    {
        return $this->oper_status === 1;
    }

    public function isAdminDown(): bool
    {
        return $this->admin_status === 2;
    }

    public function isTrunk(): bool
    {
        return count($this->tagged_vlans ?? []) > 0;
    }

    /** Short label printed on the faceplate, such as 12 for GigabitEthernet1/0/12. */
    public function shortName(): string
    {
        return preg_match('/(\d+)\D*$/', (string) ($this->name ?: $this->descr), $matches) ? $matches[1] : (string) $this->if_index;
    }

    public function state(): string
    {
        return match (true) {
            $this->isAdminDown() => 'disabled',
            $this->isUp() => 'up',
            default => 'down',
        };
    }

    public function speedLabel(): ?string
    {
        if (! $this->speed_mbps) {
            return null;
        }

        return $this->speed_mbps >= 1000 ? rtrim(rtrim(number_format($this->speed_mbps / 1000, 1, '.', ''), '0'), '.').' Gb/s' : $this->speed_mbps.' Mb/s';
    }

    public static function rate(?int $bitsPerSecond): string
    {
        if ($bitsPerSecond === null) {
            return '—';
        }

        foreach (['Gb/s' => 1e9, 'Mb/s' => 1e6, 'kb/s' => 1e3] as $unit => $factor) {
            if ($bitsPerSecond >= $factor) {
                return number_format($bitsPerSecond / $factor, $bitsPerSecond >= 100 * $factor ? 0 : 1, ',', ' ').' '.$unit;
            }
        }

        return $bitsPerSecond.' b/s';
    }

    /** Usage of the link speed by the busiest direction, in percent. */
    public function utilization(): ?float
    {
        if (! $this->speed_mbps || ($this->in_bps === null && $this->out_bps === null)) {
            return null;
        }

        return min(100, round(100 * max($this->in_bps ?? 0, $this->out_bps ?? 0) / ($this->speed_mbps * 1e6), 1));
    }
}
