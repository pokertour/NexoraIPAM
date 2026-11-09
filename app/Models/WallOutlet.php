<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\WallOutletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wall outlet of a location, optionally patched to a port of a switch.
 * The port is kept by name, which survives polls and interface renumbering.
 */
class WallOutlet extends Model
{
    /** @use HasFactory<WallOutletFactory> */
    use Auditable, HasFactory;

    /** Most outlets one batch can create. */
    public const BATCH_LIMIT = 500;

    protected $fillable = ['location_id', 'name', 'network_device_id', 'port', 'description'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id');
    }

    /** The polled port the outlet is patched to, when the device reports it. Requires device.ports. */
    public function devicePort(): ?DevicePort
    {
        return $this->port ? $this->device?->ports->firstWhere('name', $this->port) : null;
    }

    /**
     * Names of a batch, such as A1 to A24, or A01 to A24 with a width of 2.
     *
     * @return list<string>
     */
    public static function batchNames(string $prefix, int $start, int $end, int $width = 0, string $suffix = ''): array
    {
        return array_map(fn (int $number): string => $prefix.str_pad((string) $number, $width, '0', STR_PAD_LEFT).$suffix, range($start, $end));
    }

    public function auditLabel(): string
    {
        return $this->name.($this->location ? ' · '.$this->location->path() : '');
    }
}
