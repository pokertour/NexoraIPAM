<div x-data="patchPlan" @scroll.window="hide()">
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
        <span class="count-pill">{{ trans_choice(':count port brassé|:count ports brassés', $patched) }}</span>
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
                <span><i class="patch-key" data-note></i>{{ __('Description') }}</span>
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
                    $deviceDestinations = $destinations->get($device->id, collect());
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
                        <span class="count-pill">{{ __(':patched / :total ports brassés', ['patched' => $deviceOutlets->count() + $deviceDestinations->count(), 'total' => $device->ports->count()]) }}</span>
                    </header>
                    <div class="patch-panel-scroll">
                        <div class="patch-panel">
                            @foreach ($groups[$device->id] as $group)
                                <div class="patch-group" @if ($group->count() > 1) data-rows="2" @endif>
                                    @foreach ($group as $port)
                                        @php
                                            $portOutlet = $deviceOutlets->get($port->name);
                                            $freeDestination = $portOutlet ? null : $deviceDestinations->get($port->name)?->label;
                                            $color = $port->pvid ? ($vlanColors[$port->pvid] ?? \App\Support\VlanColor::for($port->pvid)) : null;
                                            $vlanName = $port->pvid ? $vlans->get($port->pvid)?->first()?->name : null;
                                            $note = $portOutlet?->description;
                                            $state = ['up' => __('Connecté'), 'down' => __('Déconnecté'), 'disabled' => __('Désactivé')][$port->state()];
                                            $card = array_filter([
                                                'port' => $port->name,
                                                'alias' => $port->alias,
                                                'vlan' => $port->pvid ? trim('VLAN '.$port->pvid.' '.$vlanName) : null,
                                                'state' => $state.($port->isTrunk() ? ' · '.__('Trunk') : ''),
                                                'neighbor' => $port->neighbor_name,
                                                'outlet' => $portOutlet ? __('Prise :name', ['name' => $portOutlet->name]).($portOutlet->location ? ' · '.$portOutlet->location->name : '') : null,
                                                'destination' => $freeDestination,
                                                'note' => $note,
                                            ]);
                                            $title = implode(' · ', array_filter([$card['port'], $card['alias'] ?? null, $card['vlan'] ?? null, $card['state'], $card['neighbor'] ?? null, $card['outlet'] ?? null, $freeDestination ? '→ '.$freeDestination : null, $note]));
                                            $jack = new \Illuminate\View\ComponentAttributeBag(array_filter([
                                                'class' => 'patch-jack', 'data-state' => $port->state(), 'data-trunk' => $port->isTrunk(), 'data-uplink' => (bool) $port->neighbor_name,
                                                'data-vlan' => (bool) $color, 'data-note' => (bool) $note, 'style' => $color ? '--vlan: '.$color.'; --ink: '.\App\Support\VlanColor::ink($color) : null,
                                                'aria-label' => $title,
                                            ]));
                                        @endphp
                                        <div class="patch-port" wire:key="patch-{{ $device->id }}-{{ $port->if_index }}" data-vlans="{{ $port->pvid }}" data-card="{{ json_encode($card) }}"
                                            :class="{ 'is-dimmed': vlan !== null && $el.dataset.vlans !== String(vlan) }"
                                            @mouseenter="show($el)" @mouseleave="hide()" @focusin="show($el)" @focusout="hide()">
                                            <span @class(['patch-outlet', 'is-free' => $freeDestination, 'is-empty' => ! $portOutlet && ! $freeDestination])>{{ $portOutlet?->name ?? $freeDestination }}</span>
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
                <nav class="segmented justify-self-start" aria-label="{{ __('Brassé vers') }}">
                    <button type="button" @class(['active' => $mode === 'outlet']) wire:click="$set('mode', 'outlet')" aria-pressed="{{ $mode === 'outlet' ? 'true' : 'false' }}"><x-icon name="outlet" class="size-3.5"/> {{ __('Prise murale') }}</button>
                    <button type="button" @class(['active' => $mode === 'text']) wire:click="$set('mode', 'text')" aria-pressed="{{ $mode === 'text' ? 'true' : 'false' }}"><x-icon name="edit" class="size-3.5"/> {{ __('Autre destination') }}</button>
                </nav>
                @if ($mode === 'text')
                    <x-field name="destination" :label="__('Destination')" wire:model="destination" maxlength="{{ \App\Models\PortDestination::MAX_LENGTH }}" :placeholder="__('ex. Imprimante accueil, borne Wi-Fi, baie B panneau 2')"
                        :hint="__('Texte libre, pour un port qui ne va pas sur une prise murale. Laissez vide pour libérer le port.')" wire:key="destination-input" autofocus/>
                @else
                    <x-field name="outlet" :label="__('Prise murale')" wire:model.live="outlet" :hint="__('Seules les prises libres sont proposées.')" wire:key="outlet-select">
                        <option value="">{{ __('Aucune, port libre') }}</option>
                        @foreach ($outletChoices as $choice)<option value="{{ $choice->id }}">{{ $choice->name }}@if ($choice->location) · {{ $choice->location->name }}@endif</option>@endforeach
                    </x-field>
                    @if ($outlet !== '')
                        <x-field name="note" :label="__('Description de la prise')" wire:model="note" maxlength="255" wire:key="outlet-note-{{ $outlet }}"
                            :placeholder="__('ex. Brassée prise C8, baie bureau système')" :hint="__('La même description que dans la liste des prises murales.')"/>
                    @endif
                @endif
                <div class="dialog-actions">
                    <a class="link-button mr-auto" href="{{ route('devices.show', ['device' => $editing->id, 'port' => $editedPort->if_index]) }}" wire:navigate>{{ __('Voir le port') }}</a>
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif
    <div class="patch-card" x-show="card" x-cloak role="tooltip" :style="cardStyle" wire:ignore>
        <template x-if="card">
            <div>
                <p class="patch-card-title"><span x-text="card.port"></span><span class="muted" x-show="card.alias" x-text="' · ' + card.alias"></span></p>
                <p class="patch-card-meta"><span x-text="card.vlan ?? @js(__('Sans VLAN natif'))"></span> · <span x-text="card.state"></span></p>
                <p x-show="card.neighbor" class="patch-card-meta"><span>{{ __('Voisin LLDP') }} :</span> <span x-text="card.neighbor"></span></p>
                <p x-show="card.outlet" class="patch-card-outlet"><x-icon name="outlet" class="size-3.5"/> <span x-text="card.outlet"></span></p>
                <p x-show="card.destination" class="patch-card-outlet"><x-icon name="link" class="size-3.5"/> <span x-text="card.destination"></span></p>
                <p x-show="card.note" class="patch-card-note" x-text="card.note"></p>
                <p x-show="! card.outlet && ! card.destination" class="patch-card-meta muted">{{ __('Port libre') }}</p>
            </div>
        </template>
    </div>
</div>
