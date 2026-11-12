<div>
    @if ($device->pollPending())
        <span wire:key="device-detail-poll" wire:poll.4s hidden></span>
    @endif
    <div class="page-heading">
        <div class="min-w-0">
            <p class="eyebrow"><a href="{{ route('devices') }}" wire:navigate class="hover:underline">{{ __('ÉQUIPEMENTS') }}</a></p>
            <h1 class="page-title mt-2 flex flex-wrap items-center gap-3">{{ $device->name }} <x-device-status :device="$device"/> <x-device-role :device="$device"/></h1>
            <p class="muted mt-2"><code>{{ $device->host }}:{{ $device->port }}</code> · {{ \App\Models\NetworkDevice::VERSIONS[$device->snmp_version] }}@if ($device->location) · {{ $device->location->path() }}@endif</p>
        </div>
        @can('administer')
            <div class="flex flex-wrap gap-2">
                <x-button variant="secondary" icon="edit" wire:click="open({{ $device->id }})">{{ __('Modifier') }}</x-button>
                <x-button icon="radar" wire:click="poll" wire:loading.attr="disabled" wire:target="poll" :disabled="$device->pollPending()">{{ __('Interroger maintenant') }}</x-button>
            </div>
        @endcan
    </div>

    @if ($device->poll_status === 'failed' && $device->poll_error)
        <div class="callout callout-danger mb-4" role="status"><x-icon name="info" class="size-4"/> {{ $device->poll_error }}</div>
    @endif
    @if ($device->api_driver && $device->api_error)
        <div class="callout callout-warning mb-4" role="status"><x-icon name="info" class="size-4"/> {{ __('VLAN lus en SNMP : :error', ['error' => $device->api_error]) }}</div>
    @endif

    <div class="stat-grid mb-4">
        <div class="card stat tone-card-cyan"><div class="stat-top"><span class="stat-icon tone-cyan"><x-icon name="switch" class="size-[18px]"/></span></div><div><p class="stat-value">{{ $summary['up'] }}<span class="stat-unit">/ {{ $summary['physical'] }}</span></p><p class="stat-label mt-1.5">{{ __('Ports actifs') }}</p></div></div>
        <div class="card stat tone-card-violet"><div class="stat-top"><span class="stat-icon tone-violet"><x-icon name="vlans" class="size-[18px]"/></span></div><div><p class="stat-value">{{ $summary['trunks'] }}</p><p class="stat-label mt-1.5">{{ __('Ports trunk') }}</p></div></div>
        <div class="card stat tone-card-blue"><div class="stat-top"><span class="stat-icon tone-blue"><x-icon name="addresses" class="size-[18px]"/></span></div><div><p class="stat-value">{{ number_format($summary['macs'], 0, ',', ' ') }}</p><p class="stat-label mt-1.5">{{ __('Adresses MAC apprises') }}</p></div></div>
        <div class="card stat tone-card-amber"><div class="stat-top"><span class="stat-icon tone-amber"><x-icon name="history" class="size-[18px]"/></span></div><div><p class="stat-value text-[1.35rem]">{{ $device->sys_uptime !== null ? \Carbon\CarbonInterval::seconds(intdiv($device->sys_uptime, 100))->cascade()->forHumans(['short' => true, 'parts' => 2]) : '—' }}</p><p class="stat-label mt-1.5">{{ __('Disponibilité') }}@if ($device->polled_at) · {{ $device->polled_at->diffForHumans() }}@endif</p></div></div>
    </div>

    <section class="card mb-4" x-data="{ vlan: null }">
        <div class="section-heading">
            <div>
                <h2>{{ $device->sys_name ?: $device->name }}</h2>
                <p class="truncate" title="{{ $device->sys_descr }}">{{ $device->sys_descr ? \Illuminate\Support\Str::before($device->sys_descr, "\n") : __('Interrogez l’équipement pour afficher sa façade.') }}</p>
            </div>
            <div class="faceplate-legend" aria-hidden="true">
                <span><i class="media-icon" data-media="rj45"></i>RJ45</span><span><i class="media-icon" data-media="sfp"></i>SFP</span><span><i class="media-icon" data-media="qsfp"></i>QSFP</span>
                <span><i data-state="up" data-speed="10g"></i>10G+</span><span><i data-state="up" data-speed="1g"></i>1G</span><span><i data-state="up" data-speed="100m"></i>100M</span>
                <span><i data-state="down"></i>{{ __('Déconnecté') }}</span><span><i data-state="disabled"></i>{{ __('Désactivé') }}</span>
                <span><i data-state="down" data-trunk></i>{{ __('Trunk') }}</span>
            </div>
        </div>
        @if ($vlanColors !== [])
            <div class="vlan-legend" role="group" aria-label="{{ __('VLAN présents sur l’équipement') }}">
                <span class="muted text-xs">{{ __('VLAN') }}</span>
                @foreach ($vlanColors as $number => $color)
                    <button type="button" class="vlan-chip" style="--vlan: {{ $color }}" x-on:click="vlan = vlan === {{ $number }} ? null : {{ $number }}" :aria-pressed="vlan === {{ $number }}" title="{{ __('Mettre en évidence les ports du VLAN :vlan', ['vlan' => $number]) }}">{{ $number }}@if ($name = $vlans->get($number)?->first()?->name)<span class="vlan-chip-name">{{ $name }}</span>@endif</button>
                @endforeach
            </div>
        @endif
        @if ($groups->isEmpty())
            <x-empty-state icon="switch" :title="__('Aucun port physique')" :text="__('Les ports apparaîtront après la première interrogation réussie.')"/>
        @else
            <div class="faceplate-wrap">
                <div class="faceplate" role="group" aria-label="{{ __('Façade de :name', ['name' => $device->name]) }}">
                    <div class="faceplate-brand"><span class="pulse-dot"></span><span>{{ \Illuminate\Support\Str::limit($device->sys_name ?: $device->name, 18) }}</span></div>
                    @foreach ($groups as $prefix => $group)
                        <div class="faceplate-group" @if ($group->count() > 1) data-rows="2" @endif>
                            @foreach ($group as $item)
                                <x-switch-port :port="$item" :selected="$port?->is($item)" :colors="$vlanColors" :outlet="$outlets[$item->name]?->name ?? null"/>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    @if ($port)
        <section class="card port-panel mb-4" wire:key="panel-{{ $port->if_index }}">
            <div class="section-heading">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="port-chip" data-state="{{ $port->state() }}">{{ $port->shortName() }}</span>
                    <div class="min-w-0"><h2 class="truncate">{{ $port->name }}</h2><p class="truncate">{{ $port->alias ?: ($port->descr !== $port->name ? $port->descr : __('Sans description')) }}</p></div>
                </div>
                <button type="button" class="icon-button" aria-label="{{ __('Fermer') }}" wire:click="select({{ $port->if_index }})"><x-icon name="close"/></button>
            </div>
            <div class="port-grid">
                <dl class="port-facts">
                    <div><dt>{{ __('État') }}</dt><dd><span @class(['badge', 'tone-cyan' => $port->state() === 'up', 'tone-amber' => $port->state() === 'disabled', 'tone-blue' => $port->state() === 'down'])>{{ ['up' => __('Connecté'), 'down' => __('Déconnecté'), 'disabled' => __('Désactivé')][$port->state()] }}</span></dd></div>
                    <div><dt>{{ __('Vitesse') }}</dt><dd>{{ $port->speedLabel() ?? '—' }}</dd></div>
                    @if ($port->physical)
                        <div><dt>{{ __('Connecteur') }}</dt><dd>
                            @can('administer')
                                <nav class="segmented segmented-sm" aria-label="{{ __('Type de connecteur') }}">
                                    <button type="button" @class(['active' => $port->media_override === null]) wire:click="setMedia({{ $port->if_index }}, '')" aria-current="{{ $port->media_override === null ? 'true' : 'false' }}" title="{{ __('Détection automatique') }}">{{ __('Auto') }}@if ($port->media) · {{ \App\Support\PortMedia::LABELS[$port->media] }}@endif</button>
                                    @foreach (\App\Support\PortMedia::LABELS as $value => $label)
                                        <button type="button" @class(['active' => $port->media_override === $value]) wire:click="setMedia({{ $port->if_index }}, '{{ $value }}')" aria-current="{{ $port->media_override === $value ? 'true' : 'false' }}">{{ $label }}</button>
                                    @endforeach
                                </nav>
                            @else
                                {{ $port->connector() ? \App\Support\PortMedia::LABELS[$port->connector()] : '—' }}@if ($port->media_override) <small class="muted">({{ __('défini manuellement') }})</small>@endif
                            @endcan
                        </dd></div>
                    @endif
                    <div><dt>{{ __('VLAN natif') }}</dt><dd>@if ($port->pvid)<x-vlan-chip :number="$port->pvid" :color="$vlanColors[$port->pvid] ?? null" :name="$vlans->get($port->pvid)?->first()?->name"/>@else — @endif</dd></div>
                    @if ($port->untagged_vlans && $port->untagged_vlans !== [$port->pvid])
                        <div><dt>{{ __('VLAN non taggés') }}</dt><dd><span class="vlan-list">@foreach ($port->untagged_vlans as $vlan)<x-vlan-chip :number="$vlan" :color="$vlanColors[$vlan] ?? null" :title="$vlans->get($vlan)?->first()?->name"/>@endforeach</span></dd></div>
                    @endif
                    <div><dt>{{ __('VLAN taggés') }}</dt><dd>@if ($port->isTrunk())<span class="vlan-list">@foreach ($port->tagged_vlans as $vlan)<x-vlan-chip :number="$vlan" :color="$vlanColors[$vlan] ?? null" :title="$vlans->get($vlan)?->first()?->name"/>@endforeach</span>@else — @endif</dd></div>
                    <div><dt>WAN</dt><dd class="flex flex-wrap items-center gap-2">
                        @if ($port->wan)
                            <span class="wan-badge"><x-icon name="globe" class="size-3.5"/> {{ $port->wan_label ?: __('Sortie Internet') }}</span>
                            @if ($port->wan_address)<code class="text-xs">{{ $port->wan_address }}</code>@endif
                            @if ($port->wan_reference)<small class="muted">{{ $port->wan_reference }}</small>@endif
                        @else
                            <span class="muted">—</span>
                        @endif
                        @can('edit-inventory')
                            <button type="button" class="icon-button" aria-label="{{ __('Définir la sortie WAN de :port', ['port' => $port->name]) }}" title="{{ __('Sortie WAN') }}" wire:click="openWan({{ $port->if_index }})"><x-icon name="edit" class="size-3.5"/></button>
                        @endcan
                    </dd></div>
                    @if ($port->physical)
                        <div><dt>{{ __('Brassage') }}</dt><dd>
                            @can('edit-inventory')
                                <div class="port-patch">
                                    <x-select name="outlet" wire:model.live="outlet" class="port-outlet" aria-label="{{ __('Prise murale brassée sur ce port') }}" wire:key="outlet-{{ $port->if_index }}">
                                        <option value="">{{ __('Aucune prise murale') }}</option>
                                        @foreach ($outletChoices as $choice)<option value="{{ $choice->id }}">{{ $choice->name }}@if ($choice->location) · {{ $choice->location->name }}@endif</option>@endforeach
                                    </x-select>
                                    <input type="text" class="input port-destination" wire:model.blur="destination" @keydown.enter.prevent="$el.blur()" maxlength="{{ \App\Models\PortDestination::MAX_LENGTH }}" wire:key="destination-{{ $port->if_index }}"
                                        placeholder="{{ __('ou autre destination : imprimante, borne Wi-Fi…') }}" aria-label="{{ __('Autre destination de ce port, en texte libre') }}">
                                    @error('destination')<p class="field-error">{{ $message }}</p>@enderror
                                    @if ($outlets[$port->name]?->description ?? null)<small class="muted">{{ __('Prise :name', ['name' => $outlets[$port->name]->name]) }} · {{ $outlets[$port->name]->description }}</small>@endif
                                </div>
                            @else
                                <x-port-patch :outlet="$outlets[$port->name] ?? null" :destination="$destinations[$port->name] ?? null"/>
                            @endcan
                        </dd></div>
                    @endif
                    <div><dt>{{ __('Voisin LLDP') }}</dt><dd>@if ($port->neighbor_name)<span class="font-semibold">{{ $port->neighbor_name }}</span>@if ($port->neighbor_port) · <code>{{ $port->neighbor_port }}</code>@endif @else — @endif
                        @if ($port->physical)
                            @can('edit-inventory')
                                <button type="button" class="link-button mt-1 inline-flex items-center gap-1" wire:click="$dispatch('open-cable-editor', { from: 'device:{{ $device->id }}', fromPort: @js($port->name) })"><x-icon name="link" class="size-3.5"/> {{ __('Ajouter une liaison depuis ce port') }}</button>
                            @endcan
                        @endif
                    </dd></div>
                    <div><dt>{{ __('Erreurs entrée / sortie') }}</dt><dd class="tabular-nums">{{ number_format($port->in_errors ?? 0, 0, ',', ' ') }} / {{ number_format($port->out_errors ?? 0, 0, ',', ' ') }}</dd></div>
                </dl>
                <div class="traffic">
                    @php($utilization = $port->utilization())
                    <div class="traffic-row"><span class="traffic-dir">↓ {{ __('Entrant') }}</span><strong class="tabular-nums">{{ \App\Models\DevicePort::rate($port->in_bps) }}</strong></div>
                    <div class="traffic-row"><span class="traffic-dir">↑ {{ __('Sortant') }}</span><strong class="tabular-nums">{{ \App\Models\DevicePort::rate($port->out_bps) }}</strong></div>
                    @if ($utilization !== null)
                        <x-meter :value="$utilization" :label="__('Utilisation du lien')" :tone="$utilization >= 80 ? 'amber' : 'cyan'" class="mt-2 block"/>
                        <small class="muted">{{ __('Utilisation du lien : :value %', ['value' => number_format($utilization, 1, ',', ' ')]) }}</small>
                    @else
                        <small class="muted">{{ __('Le débit est calculé à partir de la deuxième interrogation.') }}</small>
                    @endif
                </div>
            </div>
            <div class="border-t border-[var(--line)]">
                <h3 class="history-title px-5 pt-4">{{ trans_choice(':count adresse MAC apprise|:count adresses MAC apprises', $macs->count()) }}</h3>
                @if ($macs->isEmpty())
                    <p class="muted px-5 pb-5 text-sm">{{ __('Aucune adresse MAC apprise sur ce port.') }}</p>
                @else
                    <div class="table-scroll"><table>
                        <thead><tr><th>MAC</th><th>VLAN</th><th>{{ __('Adresse IP') }}</th><th>{{ __('Nom d’hôte') }}</th></tr></thead>
                        <tbody>
                            @foreach ($macs as $entry)
                                @php($known = $inventory->get($entry->mac_address, collect()))
                                <tr wire:key="mac-{{ $entry->id }}">
                                    <td><a class="table-link font-mono text-xs" href="{{ route('tools', ['mac' => $entry->mac_address]) }}" wire:navigate>{{ $entry->mac_address }}</a></td>
                                    <td>@if ($entry->vlan)<code>{{ $entry->vlan }}</code>@else — @endif</td>
                                    <td>
                                        @forelse ($known as $address)
                                            <a class="table-link font-mono text-xs block" href="{{ route('addresses', ['subnet' => $address->subnet_id, 'search' => $address->address]) }}" wire:navigate>{{ $address->address }}</a>
                                        @empty
                                            @if ($entry->ip_address)<code>{{ $entry->ip_address }}</code>@else <span class="muted">—</span>@endif
                                        @endforelse
                                    </td>
                                    <td>{{ $known->map(fn ($address) => $address->hostname ?: $address->resolved_hostname)->filter()->implode(', ') ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </div>
        </section>
    @endif

    <section class="card">
        <div class="table-toolbar">
            <label class="search">
                <x-icon name="search" class="size-4"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Port, VLAN, voisin ou MAC…') }}" maxlength="100" aria-label="{{ __('Rechercher un port') }}">
            </label>
            <label class="checkbox"><input type="checkbox" wire:model.live="logical"> {{ __('Afficher les interfaces logiques') }}</label>
            <span class="count-pill ml-auto">{{ $listed->count() }} {{ __('ports') }}</span>
        </div>
        @if ($listed->isEmpty())
            <x-empty-state icon="switch" :title="__('Aucun port')" :text="__('Aucun port ne correspond à ces critères.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>{{ __('Port') }}</th><th>{{ __('État') }}</th><th>{{ __('Vitesse') }}</th><th>{{ __('Connecteur') }}</th><th>VLAN</th><th>{{ __('Entrant') }}</th><th>{{ __('Sortant') }}</th><th>MAC</th><th>{{ __('Voisin LLDP') }}</th><th>{{ __('Brassage') }}</th></tr></thead>
                <tbody>
                    @foreach ($listed as $row)
                        <tr wire:key="row-{{ $row->if_index }}" @class(['row-selected' => $port?->is($row)])>
                            <td><button type="button" class="table-link font-semibold text-left" wire:click="select({{ $row->if_index }})">{{ $row->name }}</button>@if ($row->alias)<small class="muted block">{{ $row->alias }}</small>@endif</td>
                            <td><span class="port-dot" data-state="{{ $row->state() }}"></span> {{ ['up' => __('Connecté'), 'down' => __('Déconnecté'), 'disabled' => __('Désactivé')][$row->state()] }}</td>
                            <td class="whitespace-nowrap">{{ $row->speedLabel() ?? '—' }}</td>
                            <td>{{ $row->connector() ? \App\Support\PortMedia::LABELS[$row->connector()] : '—' }}</td>
                            <td>@if ($row->pvid)<x-vlan-chip :number="$row->pvid" :color="$vlanColors[$row->pvid] ?? null"/>@endif @if ($row->isTrunk())<span class="badge tone-violet ml-1" title="{{ implode(', ', $row->tagged_vlans) }}">{{ __('Trunk') }} · {{ count($row->tagged_vlans) }}</span>@endif @if (! $row->pvid && ! $row->isTrunk())—@endif</td>
                            <td class="whitespace-nowrap tabular-nums">{{ \App\Models\DevicePort::rate($row->in_bps) }}</td>
                            <td class="whitespace-nowrap tabular-nums">{{ \App\Models\DevicePort::rate($row->out_bps) }}</td>
                            <td class="tabular-nums">{{ $row->mac_count ?: '—' }}</td>
                            <td>{{ $row->neighbor_name ?? '—' }}@if ($row->neighbor_port)<small class="muted block font-mono">{{ $row->neighbor_port }}</small>@endif</td>
                            <td><x-port-patch :outlet="$outlets[$row->name] ?? null" :destination="$destinations[$row->name] ?? null"/></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    @if ($wanPort)
        <x-modal id="port-wan" :title="__('Sortie WAN')" :description="__('Un port WAN mène à Internet ou à un opérateur. La topologie le relie au nuage Internet.')">
            <form wire:submit="saveWan" class="mt-6 grid gap-4">
                <label class="checkbox"><input type="checkbox" wire:model.live="wan.enabled"> {{ __('Ce port est une sortie WAN') }}</label>
                @if ($wan['enabled'] ?? false)
                    <x-field name="wan.label" :label="__('Lien')" wire:model="wan.label" maxlength="100" :placeholder="__('ex. Orange Fibre 1 Gb/s')"/>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="wan.address" :label="__('Adresse IP publique')" wire:model="wan.address" maxlength="45" placeholder="203.0.113.10"/>
                        <x-field name="wan.reference" :label="__('Référence du circuit')" wire:model="wan.reference" maxlength="100"/>
                    </div>
                @endif
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="closeWan">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="saveWan">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($editing)
        @include('livewire.partials.device-form')
    @endif
</div>
