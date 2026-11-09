<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CableLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cable documented by hand between two rack items: patch cords, fibres
 * between racks, or links to devices that LLDP and MAC tables cannot see.
 * A port is the name of a device port, or free text on passive items.
 */
class CableLink extends Model
{
    /** @use HasFactory<CableLinkFactory> */
    use Auditable, HasFactory;

    /** @var array<string, string> */
    public const MEDIA = ['copper' => 'Cuivre', 'fiber' => 'Fibre', 'dac' => 'DAC / câble direct', 'other' => 'Autre'];

    protected $fillable = ['a_rack_item_id', 'a_port', 'b_rack_item_id', 'b_port', 'medium', 'label'];

    public function a(): BelongsTo
    {
        return $this->belongsTo(RackItem::class, 'a_rack_item_id');
    }

    public function b(): BelongsTo
    {
        return $this->belongsTo(RackItem::class, 'b_rack_item_id');
    }

    public function auditLabel(): string
    {
        $end = fn (?RackItem $item, ?string $port): string => ($item?->displayName() ?? '?').($port ? ' '.$port : '');

        return ($this->label ? $this->label.' · ' : '').$end($this->a, $this->a_port).' ↔ '.$end($this->b, $this->b_port);
    }
}
