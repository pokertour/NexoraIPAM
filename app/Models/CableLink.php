<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CableLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cable documented by hand: patch cords, fibres between racks, links to
 * devices that LLDP and MAC tables cannot see, or the medium of a detected
 * link. Each end is a rack item or, for a device that is not racked, the
 * device itself. A port is the name of a device port, or free text on
 * passive items.
 */
class CableLink extends Model
{
    /** @use HasFactory<CableLinkFactory> */
    use Auditable, HasFactory;

    /** @var array<string, string> */
    public const MEDIA = ['copper' => 'Ethernet (cuivre)', 'fiber' => 'Fibre', 'dac' => 'DAC / câble direct', 'other' => 'Autre'];

    protected $fillable = ['a_rack_item_id', 'a_network_device_id', 'a_port', 'b_rack_item_id', 'b_network_device_id', 'b_port', 'medium', 'label'];

    public function a(): BelongsTo
    {
        return $this->belongsTo(RackItem::class, 'a_rack_item_id');
    }

    public function b(): BelongsTo
    {
        return $this->belongsTo(RackItem::class, 'b_rack_item_id');
    }

    public function aDevice(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'a_network_device_id');
    }

    public function bDevice(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'b_network_device_id');
    }

    /** The device at one end ('a' or 'b'), racked or not. */
    public function deviceId(string $side): ?int
    {
        return $this->{$side}?->network_device_id ?? $this->{$side.'_network_device_id'};
    }

    public function endName(string $side): string
    {
        return $this->{$side}?->displayName() ?? $this->{$side.'Device'}?->name ?? '?';
    }

    public function auditLabel(): string
    {
        $end = fn (string $side): string => $this->endName($side).($this->{$side.'_port'} ? ' '.$this->{$side.'_port'} : '');

        return ($this->label ? $this->label.' · ' : '').$end('a').' ↔ '.$end('b');
    }
}
