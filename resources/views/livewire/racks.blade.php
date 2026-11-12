@php($unit = \App\Livewire\Racks::UNIT)
<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('INFRASTRUCTURE') }}</p><h1 class="page-title mt-2">{{ __('Baies') }}</h1><p class="muted mt-2">{{ __('Placez les équipements dans leurs baies. Les liaisons entre switchs sont déduites de LLDP et des adresses MAC.') }}</p></div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-secondary" href="{{ route('topology') }}" wire:navigate><x-icon name="topology" class="size-4"/> {{ __('Topologie') }}</a>
            @can('edit-inventory')<x-button icon="plus" wire:click="open('rack')">{{ __('Ajouter une baie') }}</x-button>@endcan
        </div>
    </div>

    <div x-data="rackRooms" :class="{ 'racks-compact': compact }">
    <div class="table-toolbar card mb-4">
        @if ($locations->isNotEmpty())
            <x-select class="location-filter" name="location" wire:model.live="location" aria-label="{{ __('Filtrer par emplacement') }}">
                <x-location-options :locations="$locations" :placeholder="__('Tous les emplacements')"/>
            </x-select>
        @endif
        <span class="count-pill ml-auto">{{ $racks->count() }} {{ $racks->count() === 1 ? __('baie') : __('baies') }}</span>
        <label class="switch-toggle" title="{{ __('Baies plus étroites, sans les listes de liaisons, pour en voir davantage à l’écran.') }}">
            <input type="checkbox" x-model="compact" @change="remember()"> <span>{{ __('Vue compacte') }}</span>
        </label>
    </div>

    @if ($racks->isEmpty())
        <section class="card"><x-empty-state icon="rack" :title="__('Aucune baie')" :text="__('Créez une baie puis placez-y vos équipements unité par unité.')"/></section>
    @else
        <div class="rack-rooms">
            @foreach ($rows as $row)
                <section class="rack-room" x-data="rackRoomNav" wire:key="room-{{ $row['location']?->id ?? 'none' }}">
                    <header class="rack-room-head">
                        <h2>{{ $row['location']?->path() ?? __('Sans emplacement') }}</h2>
                        <span class="muted">{{ trans_choice(':count baie|:count baies', $row['racks']->count()) }}@if ($row['cables'] !== []) · {{ trans_choice(':count liaison entre baies|:count liaisons entre baies', count($row['cables'])) }}@endif</span>
                    </header>
                    @if ($row['racks']->count() > 1)
                        <nav class="rack-room-nav" aria-label="{{ __('Baies de :location', ['location' => $row['location']?->name ?? __('Sans emplacement')]) }}" x-show="overflowing" x-cloak>
                            <button type="button" class="icon-button" @click="step(-1)" :disabled="atStart" aria-label="{{ __('Baies précédentes') }}"><x-icon name="chevron" class="size-4 rotate-90"/></button>
                            <div class="rack-room-chips">
                                @foreach ($row['racks'] as $rack)
                                    <button type="button" class="rack-chip" data-chip="{{ $rack->id }}" :class="{ 'is-visible': visible.includes('{{ $rack->id }}') }" @click="go({{ $rack->id }})">{{ $rack->name }}</button>
                                @endforeach
                            </div>
                            <button type="button" class="icon-button" @click="step(1)" :disabled="atEnd" aria-label="{{ __('Baies suivantes') }}"><x-icon name="chevron" class="size-4 -rotate-90"/></button>
                        </nav>
                        <div class="rack-room-topbar" x-show="overflowing" x-cloak aria-hidden="true"><div></div></div>
                    @endif
                    <div class="rack-room-scroll">
                        <div @class(['rack-row', 'has-cables' => $row['cables'] !== []]) x-data="rackRoom" data-cables="{{ json_encode($row['cables']) }}">
                            <svg class="rack-room-cables" data-room-cables wire:ignore aria-hidden="true"></svg>
                            @foreach ($row['racks'] as $rack)
                                @include('livewire.partials.rack-card', ['rack' => $rack])
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
    </div>

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
                <x-field name="form.sort_order" :label="__('Position dans la rangée')" type="number" min="0" max="999" wire:model="form.sort_order" :hint="__('Les baies d’un même emplacement sont alignées de gauche à droite dans cet ordre, comme dans la salle.')"/>
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
        <x-modal id="rack-delete" :title="__('Supprimer cette entrée ?')" :description="match ($deleting) { 'rack' => __('Les éléments placés dans la baie et leurs liaisons manuelles seront retirés. Les équipements restent dans l’inventaire.'), 'cable' => __('La liaison documentée sera supprimée. Une liaison détectée par LLDP ou les adresses MAC reste affichée.'), default => __('L’élément et ses liaisons manuelles seront retirés de la baie. Un équipement SNMP reste dans l’inventaire.') }">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                <x-button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">{{ __('Supprimer') }}</x-button>
            </div>
        </x-modal>
    @endif
</div>
