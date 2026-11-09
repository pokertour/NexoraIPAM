<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('INFRASTRUCTURE') }}</p><h1 class="page-title mt-2">{{ __('Prises murales') }}</h1><p class="muted mt-2">{{ __('Repérez les prises des bureaux et le port de switch sur lequel chacune est brassée.') }}</p></div>
        @can('edit-inventory')
            <div class="flex flex-wrap gap-2">
                <x-button variant="secondary" icon="plus" wire:click="open('outlet')">{{ __('Ajouter une prise') }}</x-button>
                <x-button icon="outlet" wire:click="open('batch')">{{ __('Créer une série') }}</x-button>
            </div>
        @endcan
    </div>

    <section class="card">
        <div class="table-toolbar">
            <label class="search">
                <x-icon name="search" class="size-4"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Prise, équipement, port…') }}" maxlength="100" aria-label="{{ __('Rechercher une prise') }}">
            </label>
            @if ($locations->isNotEmpty())
                <x-select class="location-filter" name="location" wire:model.live="location" aria-label="{{ __('Filtrer par emplacement') }}">
                    <x-location-options :locations="$locations" :placeholder="__('Tous les emplacements')"/>
                </x-select>
            @endif
            <nav class="segmented" aria-label="{{ __('État des prises') }}">
                @foreach (\App\Livewire\WallOutlets::STATES as $value => $label)
                    <button type="button" @class(['active' => $state === $value]) wire:click="$set('state', '{{ $value }}')" aria-current="{{ $state === $value ? 'true' : 'false' }}">
                        {{ __($label) }} <span class="count-pill">{{ $counts[$value] }}</span>
                    </button>
                @endforeach
            </nav>
        </div>

        @can('edit-inventory')
            @if ($selected !== [])
                <div class="bulk-bar" role="region" aria-label="{{ __('Actions sur la sélection') }}">
                    <span>{{ trans_choice(':count prise sélectionnée|:count prises sélectionnées', count($selected)) }}</span>
                    <x-button variant="secondary" wire:click="unassignSelected">{{ __('Débrasser') }}</x-button>
                    <x-button variant="danger" icon="trash" wire:click="confirmDelete">{{ __('Supprimer') }}</x-button>
                    <button type="button" class="link-button" wire:click="$set('selected', [])">{{ __('Désélectionner') }}</button>
                </div>
            @endif
        @endcan

        @if ($outlets->isEmpty())
            <x-empty-state icon="outlet" :title="__('Aucune prise murale')" :text="__('Créez une série, par exemple A1 à A24, puis brassez chaque prise sur un port de switch.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr>
                    @can('edit-inventory')<th class="w-8"><span class="sr-only">{{ __('Sélection') }}</span></th>@endcan
                    <th>{{ __('Prise') }}</th><th>{{ __('Emplacement') }}</th><th>{{ __('Brassée sur') }}</th><th>{{ __('Description') }}</th>
                    @can('edit-inventory')<th class="text-right">{{ __('Actions') }}</th>@endcan
                </tr></thead>
                <tbody>
                    @foreach ($outlets as $outlet)
                        @php($port = $outlet->devicePort())
                        <tr wire:key="outlet-{{ $outlet->id }}">
                            @can('edit-inventory')
                                <td><input type="checkbox" class="row-check" value="{{ $outlet->id }}" wire:model.live="selected" aria-label="{{ __('Sélectionner :name', ['name' => $outlet->name]) }}"></td>
                            @endcan
                            <td><span class="outlet-name"><x-icon name="outlet" class="size-3.5"/> {{ $outlet->name }}</span></td>
                            <td class="muted">{{ $outlet->location?->path() ?? '—' }}</td>
                            <td>
                                @if ($outlet->device)
                                    <span class="outlet-port">
                                        <i class="port-dot" data-state="{{ $port?->state() ?? 'unknown' }}" title="{{ $port ? ['up' => __('Connecté'), 'down' => __('Déconnecté'), 'disabled' => __('Désactivé')][$port->state()] : __('Port non relevé') }}"></i>
                                        <a class="table-link font-semibold" href="{{ route('devices.show', $outlet->device) }}" wire:navigate>{{ $outlet->device->name }}</a>
                                        <a class="table-link font-mono text-xs" href="{{ route('devices.show', ['device' => $outlet->device->id] + ($port ? ['port' => $port->if_index] : [])) }}" wire:navigate>{{ $outlet->port }}</a>
                                        @if ($port?->pvid)<x-vlan-chip :number="$port->pvid"/>@endif
                                    </span>
                                @else
                                    <span class="badge">{{ __('Libre') }}</span>
                                @endif
                            </td>
                            <td class="muted">{{ $outlet->description ?? '' }}</td>
                            @can('edit-inventory')
                                <td class="text-right whitespace-nowrap">
                                    <button type="button" class="icon-button" aria-label="{{ __('Modifier :name', ['name' => $outlet->name]) }}" wire:click="open('outlet', {{ $outlet->id }})"><x-icon name="edit" class="size-4"/></button>
                                    <button type="button" class="icon-button danger-text" aria-label="{{ __('Supprimer :name', ['name' => $outlet->name]) }}" wire:click="confirmDelete({{ $outlet->id }})"><x-icon name="trash" class="size-4"/></button>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if ($outlets->hasPages())<div class="pagination">{{ $outlets->links() }}</div>@endif
        @endif
    </section>

    @if ($editing === 'batch')
        <x-modal id="outlet-batch" :title="__('Créer une série de prises')" :description="__('Les prises qui existent déjà à cet emplacement sont ignorées.')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <x-field name="form.location_id" :label="__('Emplacement')" wire:model="form.location_id">
                    <x-location-options :locations="$locations"/>
                </x-field>
                <div class="grid gap-4 grid-cols-2 sm:grid-cols-4">
                    <x-field name="form.prefix" :label="__('Préfixe')" wire:model.live.debounce.250ms="form.prefix" maxlength="20" placeholder="A"/>
                    <x-field name="form.start" :label="__('De')" type="number" min="0" max="99999" wire:model.live.debounce.250ms="form.start" required/>
                    <x-field name="form.end" :label="__('À')" type="number" min="0" max="99999" wire:model.live.debounce.250ms="form.end" required/>
                    <x-field name="form.suffix" :label="__('Suffixe')" wire:model.live.debounce.250ms="form.suffix" maxlength="20"/>
                </div>
                <x-field name="form.width" :label="__('Chiffres')" wire:model.live="form.width" :hint="__('Complète les numéros par des zéros : A01 au lieu de A1.')">
                    <option value="0">{{ __('Sans zéros (A1)') }}</option>
                    @foreach ([2, 3, 4] as $width)<option value="{{ $width }}">{{ $width }} ({{ 'A'.str_pad('1', $width, '0', STR_PAD_LEFT) }})</option>@endforeach
                </x-field>
                <x-field name="form.description" label="Description" wire:model="form.description" maxlength="255" :placeholder="__('ex. Open space 2e étage')"/>
                <p class="outlet-preview" role="status">
                    @if ($preview)
                        {!! __(':count prises : de :first à :last', ['count' => '<strong>'.$preview['count'].'</strong>', 'first' => '<code>'.e($preview['first']).'</code>', 'last' => '<code>'.e($preview['last']).'</code>']) !!}
                    @else
                        <span class="muted">{{ __('Indiquez un début et une fin valides (:count prises au plus).', ['count' => \App\Models\WallOutlet::BATCH_LIMIT]) }}</span>
                    @endif
                </p>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Créer les prises') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($editing === 'outlet')
        <x-modal id="outlet-edit" :title="__($recordId ? 'Modifier la prise' : 'Ajouter une prise')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <div class="grid gap-4 sm:grid-cols-[10rem_minmax(0,1fr)]">
                    <x-field name="form.name" :label="__('Nom')" wire:model="form.name" maxlength="50" placeholder="A12" required/>
                    <x-field name="form.location_id" :label="__('Emplacement')" wire:model="form.location_id">
                        <x-location-options :locations="$locations"/>
                    </x-field>
                </div>
                <fieldset class="form-section">
                    <legend>{{ __('Brassage') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]">
                        <x-field name="form.network_device_id" :label="__('Équipement')" wire:model.live="form.network_device_id">
                            <option value="">{{ __('Aucun, prise libre') }}</option>
                            @foreach ($devices as $option)<option value="{{ $option->id }}">{{ $option->name }} · {{ $option->host }}</option>@endforeach
                        </x-field>
                        @if (filled($form['network_device_id'] ?? null))
                            <x-field name="form.port" :label="__('Port')" wire:model="form.port" wire:key="outlet-port-{{ $form['network_device_id'] }}">
                                <option value="">{{ __('Choisir un port') }}</option>
                                @foreach ($ports as $option)
                                    <option value="{{ $option->name }}">{{ $option->name }}@if ($taken->has($option->name)) · {{ __('prise :name', ['name' => $taken[$option->name]]) }}@endif</option>
                                @endforeach
                            </x-field>
                        @endif
                    </div>
                </fieldset>
                <x-field name="form.description" label="Description" wire:model="form.description" maxlength="255" :placeholder="__('ex. Bureau 204, poste fixe')"/>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($deleting)
        <x-modal id="outlet-delete" :title="$recordId ? __('Supprimer cette prise ?') : trans_choice('Supprimer :count prise ?|Supprimer :count prises ?', count($selected))" :description="__('Le brassage enregistré est perdu. Les équipements et leurs ports ne sont pas modifiés.')">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                <x-button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">{{ __('Supprimer') }}</x-button>
            </div>
        </x-modal>
    @endif
</div>
