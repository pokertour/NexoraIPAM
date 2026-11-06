<?php

namespace App\Livewire;

use App\Models\CustomField;
use App\Models\Tag;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Metadata extends Component
{
    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public ?string $deleting = null;

    #[Locked]
    public ?int $recordId = null;

    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('administer');
    }

    public function open(string $kind, ?int $id = null): void
    {
        Gate::authorize('administer');
        abort_unless(in_array($kind, ['tag', 'field'], true), 404);
        $this->close();
        $this->editing = $kind;
        $this->recordId = $id;

        if ($kind === 'tag') {
            $tag = $id ? Tag::findOrFail($id) : null;
            $this->form = ['name' => $tag?->name ?? '', 'color' => $tag?->color ?? 'violet', 'description' => $tag?->description ?? ''];
        } else {
            $field = $id ? CustomField::findOrFail($id) : null;
            $this->form = [
                'label' => $field?->label ?? '', 'applies_to' => $field?->applies_to ?? 'subnets', 'type' => $field?->type ?? 'text',
                'options' => implode(', ', $field?->options ?? []), 'required' => $field?->required ?? false,
            ];
        }
    }

    public function close(): void
    {
        $this->reset('editing', 'deleting', 'recordId', 'form');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('administer');
        abort_unless($this->editing !== null, 409);

        if ($this->editing === 'tag') {
            $tag = $this->recordId ? Tag::findOrFail($this->recordId) : new Tag;
            $this->form['name'] = trim((string) ($this->form['name'] ?? ''));
            $data = $this->validate([
                'form.name' => ['required', 'string', 'max:50', Rule::unique('tags', 'name')->ignore($tag)],
                'form.color' => ['required', Rule::in(array_keys(Tag::COLORS))],
                'form.description' => ['nullable', 'string', 'max:255'],
            ])['form'];
            $tag->fill($data)->save();
        } else {
            $field = $this->recordId ? CustomField::findOrFail($this->recordId) : new CustomField;
            if ($field->exists) {
                $this->form['applies_to'] = $field->applies_to;
            }
            $this->form['options'] = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($this->form['options'] ?? ''))))));
            $data = $this->validate([
                'form.label' => ['required', 'string', 'max:100'],
                'form.applies_to' => ['required', Rule::in(array_keys(CustomField::TARGETS))],
                'form.type' => ['required', Rule::in(array_keys(CustomField::TYPES))],
                'form.options' => ['array', 'max:50', Rule::requiredIf(($this->form['type'] ?? null) === 'select')],
                'form.options.*' => ['string', 'max:100'],
                'form.required' => ['boolean'],
            ], ['form.options.required' => __('Saisissez au moins un choix, séparés par des virgules.')])['form'];
            $this->form['options'] = implode(', ', $this->form['options']);

            if (! $field->exists) {
                $base = Str::slug($data['label'], '_') ?: 'champ';
                $key = $base;
                for ($suffix = 2; CustomField::where('applies_to', $data['applies_to'])->where('key', $key)->exists(); $suffix++) {
                    $key = $base.'_'.$suffix;
                }
                $field->key = $key;
                $field->position = (int) CustomField::where('applies_to', $data['applies_to'])->max('position') + 1;
            }
            $data['options'] = $data['type'] === 'select' ? $data['options'] : null;
            $field->fill($data)->save();
        }

        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Entrée enregistrée.'));
    }

    public function confirmDelete(string $kind, int $id): void
    {
        Gate::authorize('administer');
        abort_unless(in_array($kind, ['tag', 'field'], true), 404);
        $this->close();
        $this->deleting = $kind;
        $this->recordId = ($kind === 'tag' ? Tag::findOrFail($id) : CustomField::findOrFail($id))->id;
    }

    public function delete(): void
    {
        Gate::authorize('administer');
        abort_unless($this->deleting !== null && $this->recordId, 409);
        ($this->deleting === 'tag' ? Tag::findOrFail($this->recordId) : CustomField::findOrFail($this->recordId))->delete();
        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Entrée supprimée.'));
    }

    public function render(): View
    {
        Gate::authorize('administer');

        return view('livewire.metadata', [
            'tags' => Tag::withCount(['subnets', 'addresses', 'vlans'])->orderBy('name')->get(),
            'fields' => CustomField::orderBy('applies_to')->orderBy('position')->get(),
        ])->layout('components.layouts.app', ['title' => __('Étiquettes et champs')]);
    }
}
