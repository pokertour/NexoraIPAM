@php($unit = \App\Livewire\Racks::UNIT)
<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('INFRASTRUCTURE') }}</p><h1 class="page-title mt-2">{{ __('Baies') }}</h1><p class="muted mt-2">{{ __('Placez les équipements dans leurs baies. Les liaisons entre switchs sont déduites de LLDP et des adresses MAC.') }}</p></div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-secondary" href="{{ route('topology') }}" wire:navigate><x-icon name="topology" class="size-4"/> {{ __('Topologie') }}</a>
            @can('edit-inventory')<x-button icon="plus" wire:click="open('rack')">{{ __('Ajouter une baie') }}</x-button>@endcan
        </div>
    </div>

    @if ($locations->isNotEmpty())
        <div class="table-toolbar card mb-4">
            <x-select class="location-filter" name="location" wire:model.live="location" aria-label="{{ __('Filtrer par emplacement') }}">
                <x-location-options :locations="$locations" :placeholder="__('Tous les emplacements')"/>
            </x-select>
            <span class="count-pill ml-auto">{{ $racks->count() }} {{ $racks->count() === 1 ? __('baie') : __('baies') }}</span>
        </div>
    @endif

    @if ($racks->isEmpty())
        <section class="card"><x-empty-state icon="rack" :title="__('Aucune baie')" :text="__('Créez une baie puis placez-y vos équipements unité par unité.')"/></section>
    @else
        <div class="rack-grid">
            @foreach ($racks as $rack)
                @php($used = $rack->items->sum('height'))
                @php($rackLinks = \App\Livewire\Racks::rackLinks($rack, $links))
                <section class="card rack-card" wire:key="rack-{{ $rack->id }}">
                    <div class="section-heading">
                        <div class="min-w-0">
                            <h2 class="truncate">{{ $rack->name }}</h2>
                            <p>{{ $rack->location?->path() ?? __('Sans emplacement') }} · {{ __(':used U sur :total', ['used' => $used, 'total' => $rack->units]) }}</p>
                        </div>
                        @can('edit-inventory')
                            <div class="flex gap-1">
                                <button class="icon-button" type="button" aria-label="{{ __('Modifier :name', ['name' => $rack->name]) }}" wire:click="open('rack', {{ $rack->id }})"><x-icon name="edit" class="size-4"/></button>
                                <button class="icon-button danger-text" type="button" aria-label="{{ __('Supprimer :name', ['name' => $rack->name]) }}" wire:click="confirmDelete('rack', {{ $rack->id }})"><x-icon name="trash" class="size-4"/></button>
                            </div>
                        @endcan
                    </div>
                    <div class="rack-body" style="--u: {{ $unit }}px; --units: {{ $rack->units }}">
                        <ol class="rack-scale" aria-hidden="true">
                            @for ($u = $rack->units; $u >= 1; $u--)<li>{{ $u }}</li>@endfor
                        </ol>
                        <div class="rack-frame">
                            @can('edit-inventory')
                                @for ($u = $rack->units; $u >= 1; $u--)
                                    @if (! $rack->items->contains(fn ($item) => $item->position <= $u && $item->top() >= $u))
                                        <button type="button" class="rack-slot" style="top: {{ ($rack->units - $u) * $unit }}px" wire:click="open('item', null, {{ $rack->id }}, {{ $u }})" aria-label="{{ __('Ajouter un élément en U:unit', ['unit' => $u]) }}"><x-icon name="plus" class="size-3"/></button>
                                    @endif
                                @endfor
                            @endcan
                            @foreach ($rack->items as $item)
                                @php($device = $item->device)
                                @php($up = $device?->ports->where('oper_status', 1)->count())
                                <div class="rack-item" data-kind="{{ $item->kind }}" style="top: {{ ($rack->units - $item->top()) * $unit }}px; height: {{ $item->height * $unit }}px" wire:key="item-{{ $item->id }}">
                                    @if ($device)
                                        <span @class(['rack-led', 'is-down' => $device->poll_status === 'failed', 'is-unknown' => ! $device->polled_at])></span>
                                        <a href="{{ route('devices.show', $device) }}" wire:navigate class="rack-item-name">{{ $device->name }}</a>
                                        @if ($device->ports->isNotEmpty())
                                            <span class="rack-ports" aria-label="{{ __(':up ports actifs sur :total', ['up' => $up, 'total' => $device->ports->count()]) }}">
                                                @foreach ($device->ports->sortBy('if_index')->take(28) as $port)<i data-state="{{ $port->state() }}"></i>@endforeach
                                            </span>
                                        @endif
                                    @else
                                        <span class="rack-item-name">{{ $item->displayName() }}</span>
                                        <span class="muted rack-item-kind">{{ __(\App\Models\RackItem::KINDS[$item->kind]) }}</span>
                                    @endif
                                    @can('edit-inventory')
                                        <span class="rack-item-actions">
                                            <button class="icon-button" type="button" aria-label="{{ __('Modifier :name', ['name' => $item->displayName()]) }}" wire:click="open('item', {{ $item->id }})"><x-icon name="edit" class="size-3.5"/></button>
                                            <button class="icon-button danger-text" type="button" aria-label="{{ __('Retirer :name de la baie', ['name' => $item->displayName()]) }}" wire:click="confirmDelete('item', {{ $item->id }})"><x-icon name="trash" class="size-3.5"/></button>
                                        </span>
                                    @endcan
                                </div>
                            @endforeach
                        </div>
                        <svg class="rack-links" width="64" height="{{ $rack->units * $unit }}" viewBox="0 0 64 {{ $rack->units * $unit }}" aria-hidden="true">
                            @foreach ($rackLinks as $index => $link)
                                @php($reach = 14 + ($index % 3) * 14)
                                <path d="M0 {{ $link['from'] }} C{{ $reach }} {{ $link['from'] }}, {{ $reach }} {{ $link['to'] }}, 0 {{ $link['to'] }}" data-source="{{ $link['source'] }}"><title>{{ $link['label'] }}</title></path>
                            @endforeach
                        </svg>
                    </div>
                    @if ($rackLinks !== [])
                        <ul class="rack-link-list">
                            @foreach ($links as $link)
                                @if ($rack->items->contains('network_device_id', $link['a']->id) || $rack->items->contains('network_device_id', $link['b']->id))
                                    <li><span class="link-source" data-source="{{ $link['source'] }}">{{ strtoupper($link['source']) }}</span> {{ $link['a']->name }} <code>{{ $link['a_port']->name }}</code> ↔ {{ $link['b']->name }} @if ($link['b_port'])<code>{{ $link['b_port']->name }}</code>@endif</li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    @if ($editing === 'rack')
        <x-modal id="rack-edit" :title="__($recordId ? 'Modifier la baie' : 'Ajouter une baie')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <x-field name="form.name" :label="__('Nom')" wire:model="form.name" maxlength="100" required/>
                <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                    <x-field name="form.location_id" :label="__('Emplacement')" wire:model="form.location_id">
                        <x-location-options :locations="$locations"/>
                    </x-field>
                    <x-field name="form.units" :label="__('Hauteur (U)')" type="number" min="1" max="60" wire:model="form.units" required/>
                </div>
                <x-field name="form.description" label="Description" wire:model="form.description" maxlength="255"/>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($editing === 'item')
        <x-modal id="rack-item-edit" :title="__($recordId ? 'Modifier l’élément' : 'Ajouter un élément')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="form.rack_id" :label="__('Baie')" wire:model="form.rack_id">
                        @foreach ($allRacks as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                    </x-field>
                    <x-field name="form.kind" label="Type" wire:model.live="form.kind">
                        @foreach (\App\Models\RackItem::KINDS as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                    </x-field>
                </div>
                @if (($form['kind'] ?? 'device') === 'device')
                    <x-field name="form.network_device_id" :label="__('Équipement')" wire:model="form.network_device_id">
                        <option value="">{{ __('Choisir un équipement') }}</option>
                        @foreach ($devices as $option)<option value="{{ $option->id }}">{{ $option->name }} · {{ $option->host }}</option>@endforeach
                    </x-field>
                @else
                    <x-field name="form.label" :label="__('Libellé')" wire:model="form.label" maxlength="100" :placeholder="__('Panneau de brassage A')" required/>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="form.position" :label="__('Unité basse (U)')" type="number" min="1" max="60" wire:model="form.position" required :hint="__('U1 est en bas de la baie.')"/>
                    <x-field name="form.height" :label="__('Hauteur (U)')" type="number" min="1" max="20" wire:model="form.height" required/>
                </div>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($deleting)
        <x-modal id="rack-delete" :title="__('Supprimer cette entrée ?')" :description="$deleting === 'rack' ? __('Les éléments placés dans la baie seront retirés. Les équipements restent dans l’inventaire.') : __('L’élément sera retiré de la baie. Un équipement SNMP reste dans l’inventaire.')">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                <x-button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">{{ __('Supprimer') }}</x-button>
            </div>
        </x-modal>
    @endif
</div>
