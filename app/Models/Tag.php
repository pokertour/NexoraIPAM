<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends Model
{
    use Auditable, HasFactory;

    /** @var array<string, string> */
    public const COLORS = [
        'violet' => 'Violet', 'blue' => 'Bleu', 'cyan' => 'Cyan', 'green' => 'Vert',
        'amber' => 'Ambre', 'red' => 'Rouge', 'pink' => 'Rose', 'slate' => 'Gris',
    ];

    protected $fillable = ['name', 'color', 'description'];

    public function subnets(): MorphToMany
    {
        return $this->morphedByMany(Subnet::class, 'taggable');
    }

    public function addresses(): MorphToMany
    {
        return $this->morphedByMany(IpAddress::class, 'taggable');
    }

    public function vlans(): MorphToMany
    {
        return $this->morphedByMany(Vlan::class, 'taggable');
    }

    public function devices(): MorphToMany
    {
        return $this->morphedByMany(NetworkDevice::class, 'taggable');
    }
}
