<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class CustomField extends Model
{
    use Auditable, HasFactory;

    /** @var array<string, string> */
    public const TARGETS = ['subnets' => 'Sous-réseaux', 'addresses' => 'Adresses IP', 'vlans' => 'VLAN'];

    /** @var array<string, string> */
    public const TYPES = ['text' => 'Texte', 'number' => 'Nombre', 'url' => 'Lien', 'date' => 'Date', 'select' => 'Liste de choix'];

    protected $fillable = ['label', 'key', 'applies_to', 'type', 'options', 'required', 'position'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean'];
    }

    /** @return Collection<int, self> */
    public static function for(string $target): Collection
    {
        return static::where('applies_to', $target)->orderBy('position')->orderBy('label')->get();
    }

    /** @return list<mixed> */
    public function rules(): array
    {
        return [
            $this->required ? 'required' : 'nullable',
            ...match ($this->type) {
                'number' => ['numeric', 'between:-1000000000000,1000000000000'],
                'url' => ['url:http,https', 'max:500'],
                'date' => ['date_format:Y-m-d'],
                'select' => [Rule::in($this->options ?? [])],
                default => ['string', 'max:500'],
            },
        ];
    }

    public function auditLabel(): string
    {
        return $this->label.' · '.$this->applies_to;
    }
}
