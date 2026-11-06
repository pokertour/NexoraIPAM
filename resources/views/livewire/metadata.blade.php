<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('ADMINISTRATION') }}</p><h1 class="page-title mt-2">{{ __('Étiquettes et champs') }}</h1><p class="muted mt-2">{{ __('Classez l’inventaire avec des étiquettes et ajoutez vos propres champs aux sous-réseaux, adresses et VLAN.') }}</p></div>
    </div>

    <div class="settings-grid">
        <section class="card">
            <div class="section-heading">
                <div><h2>{{ __('Étiquettes') }}</h2><p>{{ __('Propriétaire, environnement, application…') }}</p></div>
                <x-button icon="plus" wire:click="open('tag')">{{ __('Ajouter') }}</x-button>
            </div>
            @if ($tags->isEmpty())
                <x-empty-state icon="tag" :title="__('Aucune étiquette')" :text="__('Créez une étiquette pour regrouper des éléments de l’inventaire.')"/>
            @else
                <div class="table-scroll"><table>
                    <thead><tr><th>{{ __('Étiquette') }}</th><th>{{ __('Utilisation') }}</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
                    <tbody>
                        @foreach ($tags as $tag)
                            <tr wire:key="tag-{{ $tag->id }}">
                                <td><x-tag-badge :tag="$tag"/>@if ($tag->description)<small class="muted block">{{ $tag->description }}</small>@endif</td>
                                <td class="muted tabular-nums">{{ $tag->subnets_count + $tag->addresses_count + $tag->vlans_count }}</td>
                                <td><div class="flex justify-end gap-1">
                                    <button class="icon-button" type="button" aria-label="{{ __('Modifier :name', ['name' => $tag->name]) }}" wire:click="open('tag', {{ $tag->id }})"><x-icon name="edit" class="size-4"/></button>
                                    <button class="icon-button danger-text" type="button" aria-label="{{ __('Supprimer :name', ['name' => $tag->name]) }}" wire:click="confirmDelete('tag', {{ $tag->id }})"><x-icon name="trash" class="size-4"/></button>
                                </div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>

        <section class="card">
            <div class="section-heading">
                <div><h2>{{ __('Champs personnalisés') }}</h2><p>{{ __('Informations supplémentaires saisies dans les formulaires.') }}</p></div>
                <x-button icon="plus" wire:click="open('field')">{{ __('Ajouter') }}</x-button>
            </div>
            @if ($fields->isEmpty())
                <x-empty-state icon="preferences" :title="__('Aucun champ')" :text="__('Ajoutez un champ pour compléter les fiches de l’inventaire.')"/>
            @else
                <div class="table-scroll"><table>
                    <thead><tr><th>{{ __('Champ') }}</th><th>{{ __('Appliqué à') }}</th><th>Type</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
                    <tbody>
                        @foreach ($fields as $field)
                            <tr wire:key="field-{{ $field->id }}">
                                <td><span class="font-semibold">{{ $field->label }}</span>@if ($field->required) <span class="muted">*</span>@endif<small class="muted block font-mono">{{ $field->key }}</small></td>
                                <td>{{ __(\App\Models\CustomField::TARGETS[$field->applies_to]) }}</td>
                                <td><span class="badge tone-blue">{{ __(\App\Models\CustomField::TYPES[$field->type]) }}</span>@if ($field->options)<small class="muted block">{{ implode(', ', $field->options) }}</small>@endif</td>
                                <td><div class="flex justify-end gap-1">
                                    <button class="icon-button" type="button" aria-label="{{ __('Modifier :name', ['name' => $field->label]) }}" wire:click="open('field', {{ $field->id }})"><x-icon name="edit" class="size-4"/></button>
                                    <button class="icon-button danger-text" type="button" aria-label="{{ __('Supprimer :name', ['name' => $field->label]) }}" wire:click="confirmDelete('field', {{ $field->id }})"><x-icon name="trash" class="size-4"/></button>
                                </div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    </div>

    @if ($editing === 'tag')
        <x-modal id="tag-edit" :title="__($recordId ? 'Modifier l’étiquette' : 'Ajouter une étiquette')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <x-field name="form.name" :label="__('Nom')" wire:model="form.name" maxlength="50" required/>
                <div class="field">
                    <span>{{ __('Couleur') }}</span>
                    <div class="color-picker" role="radiogroup" aria-label="{{ __('Couleur') }}">
                        @foreach (\App\Models\Tag::COLORS as $value => $label)
                            <label class="color-swatch" data-color="{{ $value }}" title="{{ __($label) }}">
                                <input type="radio" wire:model="form.color" value="{{ $value }}" class="sr-only">
                                <span aria-hidden="true"></span><span class="sr-only">{{ __($label) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('form.color')<small class="field-error" role="alert">{{ $message }}</small>@enderror
                </div>
                <x-field name="form.description" label="Description" wire:model="form.description" maxlength="255"/>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($editing === 'field')
        <x-modal id="field-edit" :title="__($recordId ? 'Modifier le champ' : 'Ajouter un champ')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <x-field name="form.label" :label="__('Libellé')" wire:model="form.label" maxlength="100" required/>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="form.applies_to" :label="__('Appliqué à')" wire:model="form.applies_to" :disabled="$recordId !== null">
                        @foreach (\App\Models\CustomField::TARGETS as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                    </x-field>
                    <x-field name="form.type" label="Type" wire:model.live="form.type">
                        @foreach (\App\Models\CustomField::TYPES as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                    </x-field>
                </div>
                @if (($form['type'] ?? null) === 'select')
                    <x-field name="form.options" :label="__('Choix possibles')" wire:model="form.options" :hint="__('Séparez les choix par des virgules.')" maxlength="2000"/>
                @endif
                <label class="checkbox"><input type="checkbox" wire:model="form.required"> {{ __('Champ obligatoire') }}</label>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($deleting)
        <x-modal id="metadata-delete" :title="__('Supprimer cette entrée ?')" :description="$deleting === 'tag' ? __('L’étiquette sera retirée de tous les éléments.') : __('Les valeurs déjà saisies ne seront plus affichées.')">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                <x-button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">{{ __('Supprimer') }}</x-button>
            </div>
        </x-modal>
    @endif
</div>
