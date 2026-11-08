<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rack extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['name', 'location_id', 'units', 'description'];

    protected function casts(): array
    {
        return ['units' => 'integer'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RackItem::class)->orderByDesc('position');
    }

    /** Checks that units [$position, $position + $height - 1] are inside the rack and free. */
    public function fits(int $position, int $height, ?int $ignoreItemId = null): bool
    {
        $top = $position + $height - 1;
        if ($position < 1 || $top > $this->units) {
            return false;
        }

        return ! $this->items()->when($ignoreItemId, fn ($query) => $query->whereKeyNot($ignoreItemId))->get()
            ->contains(fn (RackItem $item): bool => $item->position <= $top && $item->top() >= $position);
    }

    public function auditLabel(): string
    {
        return $this->name.($this->location ? ' · '.$this->location->path() : '');
    }
}
