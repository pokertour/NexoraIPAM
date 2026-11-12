<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PortDestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Where a switch port is patched when it does not reach a wall outlet, as
 * free text: a printer, an access point, another rack. Like outlets, the
 * port is kept by name, which survives polls and interface renumbering.
 */
class PortDestination extends Model
{
    /** @use HasFactory<PortDestinationFactory> */
    use Auditable, HasFactory;

    public const MAX_LENGTH = 100;

    protected $fillable = ['network_device_id', 'port', 'label'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id');
    }

    /**
     * Patches a port to a wall outlet or to a free destination, or frees it
     * when neither is given. A port leads to one place: setting one clears
     * the other.
     */
    public static function assign(int $deviceId, string $port, ?int $outletId, ?string $label): void
    {
        $label = trim((string) $label);

        DB::transaction(function () use ($deviceId, $port, $outletId, $label): void {
            WallOutlet::patch($deviceId, $port, $outletId);
            $current = self::where('network_device_id', $deviceId)->where('port', $port)->lockForUpdate()->first();
            if ($outletId !== null || $label === '') {
                $current?->delete();

                return;
            }
            if ($current) {
                $current->update(['label' => $label]);
            } else {
                self::create(['network_device_id' => $deviceId, 'port' => $port, 'label' => $label]);
            }
        }, attempts: 3);
    }

    public function auditLabel(): string
    {
        return $this->label.' · '.($this->device?->name ?? '#'.$this->network_device_id).' '.$this->port;
    }
}
