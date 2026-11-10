<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('INFRASTRUCTURE') }}</p><h1 class="page-title mt-2">{{ __('Topologie') }}</h1><p class="muted mt-2">{{ __('Liaisons entre équipements déduites des voisins LLDP, ou des adresses MAC que chaque switch apprend de l’autre.') }}</p></div>
        <a class="button button-secondary" href="{{ route('racks') }}" wire:navigate><x-icon name="rack" class="size-4"/> {{ __('Baies') }}</a>
    </div>

    @if ($locations->isNotEmpty())
        <div class="table-toolbar card mb-4">
            <x-select class="location-filter" name="location" wire:model.live="location" aria-label="{{ __('Filtrer par emplacement') }}">
                <x-location-options :locations="$locations" :placeholder="__('Tous les emplacements')"/>
            </x-select>
            <span class="count-pill ml-auto">{{ trans_choice(':count équipement|:count équipements', $nodes->count()) }}</span>
        </div>
    @endif

    @if ($nodes->isEmpty())
        <section class="card"><x-empty-state icon="topology" :title="__('Aucun équipement')" :text="__('Ajoutez et interrogez au moins deux switchs pour voir leurs liaisons.')"/></section>
    @else
        @php
            $neighbours = [];
            foreach ($links as $link) {
                $neighbours[$link['a']->id][] = $link['b']->id;
                $neighbours[$link['b']->id][] = $link['a']->id;
            }
            $viewer = [
                'width' => $width,
                'height' => $height,
                'nodes' => $nodes->map(fn (array $node): array => ['id' => $node['device']->id, 'name' => $node['device']->name, 'host' => (string) $node['device']->host, 'x' => $node['x'], 'y' => $node['y'], 'url' => route('devices.show', $node['device'])])->values()->all(),
                'neighbours' => array_map(fn (array $ids): array => array_values(array_unique($ids)), $neighbours),
            ];
        @endphp
        <section class="card topology-card" x-data="topologyView(@js($viewer))" wire:key="topology-{{ md5(json_encode($viewer).$location) }}" @keydown.escape.window="clear()">
            <div class="topology-legend">
                <span><i data-source="lldp"></i>LLDP</span>
                <span><i data-source="mac"></i>{{ __('Adresses MAC') }}</span>
                <span><i data-source="manual"></i>{{ __('Manuelle') }}</span>
                @if ($internet)<span><i data-source="wan"></i>WAN</span>@endif
                <span class="muted">{{ trans_choice(':count liaison détectée|:count liaisons détectées', $links->count()) }}</span>
                @if (! $internet)
                    <span class="topology-hint">{{ __('Marquez le port de sortie de votre routeur ou pare-feu comme WAN pour afficher Internet.') }}</span>
                @endif
            </div>
            <div class="topology-toolbar">
                <label class="search topology-search" :class="{ 'is-missing': missing }">
                    <x-icon name="search" class="size-4"/>
                    <input type="search" list="topology-devices" x-model="query" @change="find()" @keydown.enter.prevent="find()" @input="query === '' && clear()"
                        placeholder="{{ __('Trouver un équipement…') }}" maxlength="100" aria-label="{{ __('Trouver un équipement dans la topologie') }}">
                </label>
                <datalist id="topology-devices">@foreach ($nodes as $node)<option value="{{ $node['device']->name }}"></option>@endforeach</datalist>
                <span class="topology-missing" x-show="missing" x-cloak role="status">{{ __('Aucun équipement trouvé.') }}</span>
                <span class="topology-hint">{{ __('Ctrl + molette pour zoomer, glisser pour se déplacer.') }}</span>
                <div class="topology-zoom" role="group" aria-label="{{ __('Zoom') }}">
                    <button type="button" class="icon-button" @click="zoom(1 / 1.25)" aria-label="{{ __('Dézoomer') }}" title="{{ __('Dézoomer') }}"><span aria-hidden="true">−</span></button>
                    <button type="button" class="topology-percent" @click="actualSize()" title="{{ __('Taille réelle') }}" x-text="percent">100 %</button>
                    <button type="button" class="icon-button" @click="zoom(1.25)" aria-label="{{ __('Zoomer') }}" title="{{ __('Zoomer') }}"><x-icon name="plus" class="size-4"/></button>
                    <button type="button" class="button button-secondary topology-fit" @click="fit()">{{ __('Tout afficher') }}</button>
                </div>
            </div>
            <div class="topology-viewport" x-ref="viewport" wire:ignore style="--natural-height: {{ $height }}px"
                @wheel="wheel($event)" @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up($event)" @pointercancel="up($event)"
                @click.self="clear()">
            <svg class="topology" x-ref="svg" viewBox="0 0 {{ $width }} {{ $height }}" :class="{ 'has-focus': focused !== null, 'is-far': far }" role="img" aria-label="{{ __('Topologie du réseau') }}" @click="$event.target === $el && clear()">
                <defs>
                    <radialGradient id="node-glow"><stop offset="0" stop-color="#7c3aed" stop-opacity=".35"/><stop offset="1" stop-color="#7c3aed" stop-opacity="0"/></radialGradient>
                    <radialGradient id="internet-glow"><stop offset="0" stop-color="#0ea5e9" stop-opacity=".3"/><stop offset="1" stop-color="#0ea5e9" stop-opacity="0"/></radialGradient>
                </defs>
                <g id="topology-graph">
                @if ($isolatedY)
                    <line class="topology-separator" x1="30" y1="{{ $isolatedY }}" x2="{{ $width - 30 }}" y2="{{ $isolatedY }}"/>
                    <text class="topology-group" x="30" y="{{ $isolatedY + 18 }}">{{ __('Sans liaison connue') }}</text>
                @endif
                @foreach ($wans as $wan)
                    <g class="topology-link" data-source="wan" :class="{ 'is-lit': litLink({{ $wan['port']->network_device_id }}, 0) }">
                        <path d="M{{ $wan['from'] }} {{ $internet['y'] + 22 }} C{{ $wan['from'] }} {{ $internet['y'] + 70 }}, {{ $wan['x'] }} {{ $wan['y'] - 80 }}, {{ $wan['x'] }} {{ $wan['y'] - 22 }}"/>
                        <text class="topology-wan-label" data-side="{{ $wan['side'] }}" x="{{ $wan['x'] + ($wan['side'] === 'right' ? 10 : -10) }}" y="{{ $wan['y'] - 48 }}">{{ \Illuminate\Support\Str::limit($wan['port']->wan_label ?: $wan['port']->name, 26) }}</text>
                    </g>
                @endforeach
                @foreach ($links as $link)
                    @php
                        [$a, $b] = [$nodes[$link['a']->id], $nodes[$link['b']->id]];
                    @endphp
                    <g class="topology-link" data-source="{{ $link['source'] }}" :class="{ 'is-lit': litLink({{ $link['a']->id }}, {{ $link['b']->id }}) }">
                        <line x1="{{ $a['x'] }}" y1="{{ $a['y'] }}" x2="{{ $b['x'] }}" y2="{{ $b['y'] }}"/>
                        @if ($link['a_port'])<text x="{{ (int) round($a['x'] + ($b['x'] - $a['x']) * .3) }}" y="{{ (int) round($a['y'] + ($b['y'] - $a['y']) * .3) - 6 }}">{{ $link['a_port']->name }}</text>@endif
                        @if ($link['b_port'])<text x="{{ (int) round($b['x'] + ($a['x'] - $b['x']) * .3) }}" y="{{ (int) round($b['y'] + ($a['y'] - $b['y']) * .3) - 6 }}">{{ $link['b_port']->name }}</text>@endif
                    </g>
                @endforeach
                @if ($internet)
                    <g class="topology-internet" aria-label="Internet">
                        <circle cx="{{ $internet['x'] }}" cy="{{ $internet['y'] }}" r="60" fill="url(#internet-glow)"/>
                        <path d="M{{ $internet['x'] - 42 }} {{ $internet['y'] + 20 }} h84 a18 18 0 0 0 2 -36 a24 24 0 0 0 -44 -10 a18 18 0 0 0 -32 10 a14 14 0 0 0 -10 36 z"/>
                        <text x="{{ $internet['x'] }}" y="{{ $internet['y'] + 8 }}">Internet</text>
                    </g>
                @endif
                @foreach ($nodes as $node)
                    @php
                        $device = $node['device'];
                        $detail = $device->role !== 'other' ? __(\App\Models\NetworkDevice::ROLES[$device->role]['short']).' · '.$device->host : $device->host;
                    @endphp
                    <a href="{{ route('devices.show', $device) }}" class="topology-node" data-node="{{ $device->id }}" data-role="{{ $device->role }}" data-state="{{ $device->poll_status === 'failed' ? 'down' : ($device->polled_at ? 'up' : 'unknown') }}"
                        @click="select($event, {{ $device->id }})" :class="{ 'is-lit': lit({{ $device->id }}), 'is-focused': focused === {{ $device->id }} }">
                        <title>{{ $device->name }} · {{ $detail }}</title>
                        <circle cx="{{ $node['x'] }}" cy="{{ $node['y'] }}" r="50" fill="url(#node-glow)"/>
                        <rect x="{{ $node['x'] - 85 }}" y="{{ $node['y'] - 22 }}" width="170" height="44" rx="10"/>
                        <circle class="topology-led" cx="{{ $node['x'] - 71 }}" cy="{{ $node['y'] - 8 }}" r="4"/>
                        <text class="topology-name" x="{{ $node['x'] - 61 }}" y="{{ $node['y'] - 4 }}">{{ \Illuminate\Support\Str::limit($device->name, 16, '…') }}</text>
                        <text class="topology-host" x="{{ $node['x'] - 61 }}" y="{{ $node['y'] + 12 }}">{{ \Illuminate\Support\Str::limit($detail, 21, '…') }}</text>
                    </a>
                @endforeach
                </g>
            </svg>
            <div class="topology-focus" x-show="focused !== null" x-cloak x-transition.opacity role="status">
                <template x-if="focused !== null">
                    <div>
                        <p class="topology-focus-name" x-text="node(focused)?.name"></p>
                        <p class="muted text-xs" x-text="node(focused)?.host"></p>
                        <p class="muted text-xs mt-1" x-text="(neighbours[focused] ?? []).length === 0 ? @js(__('Aucune liaison connue')) : @js(__('Voisins directs')) + ' : ' + (neighbours[focused] ?? []).length"></p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" class="button button-primary topology-open" @click="open()">{{ __('Ouvrir la fiche') }}</button>
                            <button type="button" class="icon-button" @click="clear()" aria-label="{{ __('Fermer') }}"><x-icon name="close" class="size-4"/></button>
                        </div>
                    </div>
                </template>
            </div>
            <svg class="topology-minimap" viewBox="0 0 {{ $width }} {{ $height }}" x-show="cropped" x-cloak @click.stop="minimap($event)" @pointerdown.stop aria-hidden="true">
                <use href="#topology-graph"/>
                <rect class="topology-lens" :x="lens.x" :y="lens.y" :width="lens.width" :height="lens.height"/>
            </svg>
            </div>
        </section>

        @if ($links->isNotEmpty())
            <section class="card mt-4">
                <div class="table-scroll"><table>
                    <thead><tr><th>{{ __('Équipement') }}</th><th>{{ __('Port') }}</th><th>{{ __('Relié à') }}</th><th>{{ __('Port') }}</th><th>{{ __('Type') }}</th><th>{{ __('Source') }}</th>@can('edit-inventory')<th><span class="sr-only">{{ __('Actions') }}</span></th>@endcan</tr></thead>
                    <tbody>
                        @foreach ($links as $link)
                            <tr>
                                <td><a class="table-link font-semibold" href="{{ route('devices.show', $link['a']) }}" wire:navigate>{{ $link['a']->name }}</a></td>
                                <td>@if ($link['a_port'])<a class="table-link font-mono text-xs" href="{{ route('devices.show', ['device' => $link['a']->id, 'port' => $link['a_port']->if_index]) }}" wire:navigate>{{ $link['a_port']->name }}</a>@else — @endif</td>
                                <td><a class="table-link font-semibold" href="{{ route('devices.show', $link['b']) }}" wire:navigate>{{ $link['b']->name }}</a></td>
                                <td>@if ($link['b_port'])<a class="table-link font-mono text-xs" href="{{ route('devices.show', ['device' => $link['b']->id, 'port' => $link['b_port']->if_index]) }}" wire:navigate>{{ $link['b_port']->name }}</a>@else — @endif</td>
                                <td>
                                    @if ($link['cable'])
                                        <span class="cable-medium" data-medium="{{ $link['cable']->medium }}">{{ __(\App\Models\CableLink::MEDIA[$link['cable']->medium] ?? $link['cable']->medium) }}</span>
                                        @if ($link['cable']->label)<small class="muted block">{{ $link['cable']->label }}</small>@endif
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="link-source" data-source="{{ $link['source'] }}">{{ ['lldp' => 'LLDP', 'mac' => __('Adresses MAC'), 'manual' => __('Manuelle')][$link['source']] }}</span>
                                    @if ($link['confirmed'])<span class="link-confirmed" title="{{ __('Liaison documentée confirmée par la détection') }}"><x-icon name="check" class="size-3"/></span>@endif
                                </td>
                                @can('edit-inventory')
                                    <td class="text-right">
                                        <button type="button" class="icon-button" aria-label="{{ __('Type de liaison entre :a et :b', ['a' => $link['a']->name, 'b' => $link['b']->name]) }}" title="{{ __('Type de liaison') }}"
                                            wire:click="editMedium({{ $link['a']->id }}, @js($link['a_port']?->name ?? $link['cable']?->a_port), {{ $link['b']->id }}, @js($link['b_port']?->name ?? $link['cable']?->b_port), {{ $link['cable']?->id ?? 'null' }})"><x-icon name="edit" class="size-4"/></button>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </section>
        @endif
    @endif

    @if ($ends)
        @php
            [$aName, $bName] = [\App\Models\NetworkDevice::find($ends['a'])?->name, \App\Models\NetworkDevice::find($ends['b'])?->name];
        @endphp
        <x-modal id="link-medium" :title="__('Type de liaison')" :description="($aName.($ends['a_port'] ? ' '.$ends['a_port'] : '')).' ↔ '.($bName.($ends['b_port'] ? ' '.$ends['b_port'] : ''))">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="form.medium" label="Type" wire:model="form.medium">
                        @foreach (\App\Models\CableLink::MEDIA as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                    </x-field>
                    <x-field name="form.label" :label="__('Repère')" wire:model="form.label" maxlength="100" :placeholder="__('ex. fibre F12')"/>
                </div>
                <div class="dialog-actions">
                    @if ($cableId)
                        <x-button variant="secondary" class="danger-text mr-auto" wire:click="forget" wire:loading.attr="disabled" wire:target="forget">{{ __('Supprimer la liaison documentée') }}</x-button>
                    @endif
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
