<div x-data="{ vlan: null }">
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('INFRASTRUCTURE') }}</p><h1 class="page-title mt-2">{{ __('Plan de brassage') }}</h1><p class="muted mt-2">{{ __('Les ports de chaque switch, colorés selon leur VLAN natif, avec la prise murale brassée sur chacun.') }}</p></div>
        <div class="flex flex-wrap gap-2 print-hidden">
            <x-button variant="secondary" icon="print" x-on:click="window.print()">{{ __('Imprimer') }}</x-button>
        </div>
    </div>
    <x-page-tabs current="patch-plan"/>

    <div class="table-toolbar card mb-4 print-hidden">
        @if ($locations->isNotEmpty())
            <x-select class="location-filter" name="location" wire:model.live="location" aria-label="{{ __('Filtrer par emplacement') }}">
                <x-location-options :locations="$locations" :placeholder="__('Tous les emplacements')"/>
            </x-select>
        @endif
        <span class="count-pill ml-auto">{{ trans_choice(':count équipement|:count équipements', $devices->count()) }}</span>
        <span class="count-pill">{{ trans_choice(':count prise brassée|:count prises brassées', $patched) }}</span>
    </div>

    @if ($devices->isEmpty())
        <section class="card"><x-empty-state icon="patch" :title="__('Aucun port relevé')" :text="__('Ajoutez un switch dans Équipements : ses ports apparaîtront ici après la première interrogation SNMP.')"/></section>
    @else
        <div class="patch-legend card mb-4" role="group" aria-label="{{ __('Légende') }}">
            @if ($vlanColors !== [])
                <span class="muted text-xs">{{ __('VLAN natif') }}</span>
                @foreach ($vlanColors as $number => $color)
                    <button type="button" class="vlan-chip" style="--vlan: {{ $color }}" x-on:click="vlan = vlan === {{ $number }} ? null : {{ $number }}" :aria-pressed="vlan === {{ $number }}" title="{{ __('Mettre en évidence les ports du VLAN :vlan', ['vlan' => $number]) }}">{{ $number }}@if ($name = $vlans->get($number)?->first()?->name)<span class="vlan-chip-name">{{ $name }}</span>@endif</button>
                @endforeach
            @endif
            <span class="patch-legend-keys">
                <span><i class="patch-key" data-trunk></i>{{ __('Trunk') }}</span>
                <span><i class="patch-key" data-uplink></i>{{ __('Voisin LLDP') }}</span>
                <span><i class="patch-key" data-state="up"></i>{{ __('Connecté') }}</span>
                <span><i class="patch-key" data-state="down"></i>{{ __('Déconnecté') }}</span>
                <span><i class="patch-key" data-state="disabled"></i>{{ __('Désactivé') }}</span>
            </span>
        </div>

        @php
            $canEdit = Gate::allows('edit-inventory');
        @endphp
        <div class="patch-devices">
            @foreach ($devices as $device)
                @php
                    $deviceOutlets = $outlets->get($device->id, collect());
                @endphp
                <section class="card patch-device" wire:key="patch-device-{{ $device->id }}">
                    <header class="patch-device-head">
                        <div class="min-w-0">
                            <h2 class="truncate"><a class="table-link" href="{{ route('devices.show', $device) }}" wire:navigate>{{ $device->name }}</a></h2>
                            <p class="muted">
                                {{ collect([
                                    $device->location?->path() ?? __('Sans emplacement'),
                                    $device->rackItem?->rack ? $device->rackItem->rack->name.' · U'.$device->rackItem->position : null,
                                    __(\App\Models\NetworkDevice::ROLES[$device->role]['short'] ?? ''),
                                ])->filter()->implode(' · ') }}
                            </p>
                        </div>
                        <span class="count-pill">{{ __(':patched / :total ports brassés', ['patched' => $deviceOutlets->count(), 'total' => $device->ports->count()]) }}</span>
                    </header>
                    <div class="patch-panel-scroll">
                        <div class="patch-panel">
                            @foreach ($groups[$device->id] as $group)
                                <div class="patch-group" @if ($group->count() > 1) data-rows="2" @endif>
                                    @foreach ($group as $port)
                                        @php
                                            $outlet = $deviceOutlets->get($port->name);
                                            $color = $port->pvid ? ($vlanColors[$port->pvid] ?? \App\Support\VlanColor::for($port->pvid)) : null;
                                            $vlanName = $port->pvid ? $vlans->get($port->pvid)?->first()?->name : null;
                                            $title = implode(' · ', array_filter([
                                                $port->name, $port->alias,
                                                $port->pvid ? trim('VLAN '.$port->pvid.' '.$vlanName) : null,
                                                $port->isTrunk() ? __('Trunk') : null,
                                                ['up' => __('Connecté'), 'down' => __('Déconnecté'), 'disabled' => __('Désactivé')][$port->state()],
                                                $port->neighbor_name,
                                                $outlet ? __('Prise :name', ['name' => $outlet->name]) : null,
                                            ]));
                                            $jack = new \Illuminate\View\ComponentAttributeBag(array_filter([
                                                'class' => 'patch-jack', 'data-state' => $port->state(), 'data-trunk' => $port->isTrunk(), 'data-uplink' => (bool) $port->neighbor_name,
                                                'data-vlan' => (bool) $color, 'style' => $color ? '--vlan: '.$color.'; --ink: '.\App\Support\VlanColor::ink($color) : null,
                                                'title' => $title, 'aria-label' => $title,
                                            ]));
                                        @endphp
                                        <div class="patch-port" wire:key="patch-{{ $device->id }}-{{ $port->if_index }}" data-vlans="{{ $port->pvid }}"
                                            :class="{ 'is-dimmed': vlan !== null && $el.dataset.vlans !== String(vlan) }">
                                            <span @class(['patch-outlet', 'is-empty' => ! $outlet])>{{ $outlet?->name }}</span>
                                            @if ($canEdit)
                                                <button type="button" {{ $jack }} wire:click="patch({{ $device->id }}, {{ $port->if_index }})">{{ $port->shortName() }}</button>
                                            @else
                                                <a {{ $jack }} href="{{ route('devices.show', ['device' => $device->id, 'port' => $port->if_index]) }}" wire:navigate>{{ $port->shortName() }}</a>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    @if ($editedPort)
        <x-modal id="patch-port" :title="__('Brassage de :port', ['port' => $editedPort->name])" :description="$editing->name.($editedPort->alias ? ' · '.$editedPort->alias : '')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <x-field name="outlet" :label="__('Prise murale')" wire:model="outlet" :hint="__('Seules les prises libres sont proposées.')">
                    <option value="">{{ __('Aucune, port libre') }}</option>
                    @foreach ($outletChoices as $choice)<option value="{{ $choice->id }}">{{ $choice->name }}@if ($choice->location) · {{ $choice->location->name }}@endif</option>@endforeach
                </x-field>
                <div class="dialog-actions">
                    <a class="link-button mr-auto" href="{{ route('devices.show', ['device' => $editing->id, 'port' => $editedPort->if_index]) }}" wire:navigate>{{ __('Voir le port') }}</a>
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
