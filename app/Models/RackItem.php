<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RackItem extends Model
{
    use Auditable, HasFactory;

    /** @var array<string, string> */
    public const KINDS = [
        'device' => 'Équipement SNMP', 'patch_panel' => 'Panneau de brassage', 'server' => 'Serveur',
        'pdu' => 'Alimentation', 'other' => 'Autre',
    ];

    protected $fillable = ['rack_id', 'network_device_id', 'kind', 'label', 'position', 'height'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'height' => 'integer'];
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id');
    }

    /** Highest unit occupied by the item. */
    public function top(): int
    {
        return $this->position + $this->height - 1;
    }

    public function displayName(): string
    {
        return $this->device?->name ?? $this->label ?? __(self::KINDS[$this->kind] ?? $this->kind);
    }

    public function auditLabel(): string
    {
        return $this->displayName().' · U'.$this->position.($this->rack ? ' · '.$this->rack->name : '');
    }
}
