<x-layouts.app :title="__('Vue d’ensemble')">
    @php($scope = $location ? ['location' => $location->id] : [])
    <div class="dashboard-filter">
        <div>
            <p class="eyebrow">{{ __('ESPACE DE TRAVAIL') }}</p>
            <h1 class="page-title mt-2">{{ __('Vue d’ensemble') }}</h1>
        </div>
        @if ($locations->isNotEmpty())
            <form method="get" action="{{ route('dashboard') }}" class="dashboard-filter-form">
                <x-select name="location" aria-label="{{ __('Emplacement du tableau de bord') }}">
                    <x-location-options :locations="$locations" :selected="$location?->id" :placeholder="__('Tous les emplacements')"/>
                </x-select>
                <x-button type="submit" variant="secondary">{{ __('Appliquer') }}</x-button>
            </form>
        @endif
    </div>

    <div class="stat-grid dashboard-reveal mt-6">
        <x-stat-card icon="subnets" :label="__('Sous-réseaux')" :value="$networkCount" :href="route('subnets', $scope)"/>
        <x-stat-card icon="addresses" :label="__('IP inventoriées')" :value="$statuses->sum()" :href="route('addresses', $scope)" tone="blue"/>
        <x-stat-card icon="vlans" label="VLAN" :value="$vlanCount" :href="route('vlans', $scope)" tone="cyan"/>
        <x-stat-card icon="reserved" :label="__('IP réservées')" :value="$statuses['reserved']" :href="route('addresses', $scope + ['status' => 'reserved'])" tone="amber"/>
    </div>

    @if ($alerts->isNotEmpty())
        <section class="card dashboard-reveal mt-4">
            <div class="section-heading">
                <div><h2>{{ __('Alertes actives') }}</h2><p>{{ __('Anomalies à examiner sur le réseau.') }}</p></div>
                <a class="button button-secondary" href="{{ route('alerts') }}" wire:navigate>{{ __('Voir les alertes') }} <x-icon name="arrow" class="size-4"/></a>
            </div>
            <ul class="alert-list">@foreach ($alerts as $alert)<x-alert-row :alert="$alert" :actions="false"/>@endforeach</ul>
        </section>
    @endif

    <div class="dashboard-grid dashboard-reveal mt-4">
        <section class="card">
            <div class="section-heading">
                <div><h2>{{ __('Nouveaux hôtes') }}</h2><p>{{ trans_choice('Adresses apparues pour la première fois depuis :count jour.|Adresses apparues pour la première fois depuis :count jours.', \App\Support\DashboardInsights::DAYS) }}</p></div>
            </div>
            @if ($newHosts->isEmpty())
                <x-empty-state icon="radar" :title="__('Aucun nouvel hôte')" :text="__('Les adresses découvertes par ICMP, SNMP ou DHCP apparaîtront ici.')"/>
            @else
                <ul class="host-list">
                    @foreach ($newHosts as $host)
                        @php($address = $host['address'])
                        <li>
                            <a class="table-link font-mono font-semibold" href="{{ route('addresses', ['subnet' => $address->subnet_id, 'search' => $address->address]) }}" wire:navigate>{{ $address->address }}</a>
                            <span class="min-w-0">
                                <span class="block truncate text-sm">{{ $address->hostname ?: ($address->resolved_hostname ?: __('Nom inconnu')) }}@if ($host['new_mac']) <span class="badge tone-amber">{{ __('MAC inconnue') }}</span>@endif</span>
                                <small class="muted block truncate">
                                    @if ($host['mac'])<span class="font-mono">{{ $host['mac'] }}</span>@endif
                                    @if ($host['vendor']) · {{ $host['vendor'] }}@endif
                                    @if ($host['port']) · {{ $host['port']->device->name }} {{ $host['port']->port->name }}@endif
                                </small>
                            </span>
                            <small class="muted whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($host['first_seen'])->diffForHumans() }}</small>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card">
            <div class="section-heading">
                <div><h2>{{ __('Équipements') }}</h2><p>{{ __('Switchs et routeurs interrogés en SNMP.') }}</p></div>
                <a class="button button-secondary" href="{{ route('devices') }}" wire:navigate>{{ __('Voir') }} <x-icon name="arrow" class="size-4"/></a>
            </div>
            <dl class="ping-summary ping-summary-pair">
                <a class="is-link" href="{{ route('devices') }}" wire:navigate><dt><span class="badge tone-cyan">{{ __('En ligne') }}</span></dt><dd>{{ $devices['online'] }} / {{ $devices['total'] }}</dd></a>
                <a class="is-link" href="{{ route('alerts') }}" wire:navigate><dt><span class="badge tone-amber">{{ __('Injoignable') }}</span></dt><dd>{{ $devices['unreachable']->count() }}</dd></a>
            </dl>
            @foreach ($devices['unreachable'] as $device)
                <p class="device-down"><span class="port-dot" data-state="disabled"></span><a class="table-link font-semibold" href="{{ route('devices.show', $device) }}" wire:navigate>{{ $device->name }}</a> <span class="muted text-xs">{{ $device->poll_error }}</span></p>
            @endforeach
            <div class="section-heading border-t border-[var(--line)]"><div><h2>{{ __('Ports les plus chargés') }}</h2></div></div>
            @if ($busyPorts->isEmpty())
                <p class="muted px-5 pb-5 text-sm">{{ __('Le débit apparaît à partir de la deuxième interrogation des équipements.') }}</p>
            @else
                <div class="usage-list pt-1">
                    @foreach ($busyPorts as $busy)
                        <a class="usage-row is-link" href="{{ route('devices.show', ['device' => $busy->network_device_id, 'port' => $busy->if_index]) }}" wire:navigate>
                            <div class="min-w-0"><p class="truncate font-semibold">{{ $busy->device->name }}</p><code>{{ $busy->name }}</code></div>
                            <x-meter :value="$busy->utilization() ?? 0" :label="__('Utilisation du lien')" :tone="($busy->utilization() ?? 0) >= 80 ? 'amber' : 'cyan'"/>
                            <span class="text-right text-sm font-semibold tabular-nums">{{ number_format($busy->utilization() ?? 0, 1, ',', ' ') }} %</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <div class="dashboard-grid dashboard-reveal mt-4">
        <section class="card">
            <div class="section-heading"><div><h2>{{ __('Occupation IPv4') }}</h2><p>{{ __('Adresses attribuées et réservées sur la capacité utilisable.') }}</p></div></div>
            <div class="capacity-summary">
                <div class="capacity-ring" style="--usage: {{ $ipv4['percentage'] }}%" aria-hidden="true">
                    <span>{{ number_format($ipv4['percentage'], 1, ',', ' ') }}<small>%</small></span>
                </div>
                <div class="capacity-copy">
                    <p class="font-semibold">{{ number_format($ipv4['assigned'], 0, ',', ' ') }} / {{ number_format($ipv4['capacity'], 0, ',', ' ') }} IPv4</p>
                    <p class="muted mt-1 text-sm">{{ $ipv4['capacity'] ? __('Plages IPv4 du périmètre') : __('Ajoutez un sous-réseau IPv4 pour suivre son occupation.') }}</p>
                </div>
            </div>
            <div class="usage-list">
                @foreach ($busiest as $network)
                    <a class="usage-row is-link" href="{{ route('addresses', ['subnet' => $network['subnet']->id]) }}" wire:navigate>
                        <div><p class="font-semibold">{{ $network['subnet']->name }}</p><code>{{ $network['subnet']->cidr }}</code></div>
                        <x-meter :value="$network['percentage']" :label="__('Occupation de :name', ['name' => $network['subnet']->name])" :tone="$network['percentage'] >= 80 ? 'amber' : 'violet'"/>
                        <span class="text-right text-sm font-semibold tabular-nums">{{ $network['percentage'] }} %</span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="section-heading"><div><h2>{{ __('État des adresses') }}</h2><p>{{ __('Répartition de la capacité IPv4 utilisable du périmètre.') }}</p></div></div>
            <div class="usage-list" data-ipv4-address-states>
                @foreach (\App\Models\IpAddress::STATUSES as $key => $label)
                    @php($tone = ['used' => 'violet', 'reserved' => 'amber', 'available' => 'cyan'][$key])
                    @php($target = $key === 'available' ? route('subnets', $scope) : route('addresses', $scope + ['status' => $key]))
                    <a class="status-row is-link" href="{{ $target }}" wire:navigate title="{{ $key === 'available' ? __('Voir les plages libres des sous-réseaux') : __('Voir ces adresses') }}">
                        <span class="badge tone-{{ $tone }}">{{ __($label) }}</span>
                        <x-meter :value="$ipv4['capacity'] ? min(100, 100 * $ipv4States[$key] / $ipv4['capacity']) : 0" :label="__($label)" :tone="$tone"/>
                        <span class="text-right text-sm font-semibold tabular-nums">{{ number_format($ipv4States[$key], 0, ',', ' ') }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>

    <section class="card dashboard-reveal mt-4">
        <div class="section-heading">
            <div><h2>{{ __('Dernier ping par adresse') }}</h2><p>{{ __('Résultats enregistrés dans le périmètre sélectionné.') }}</p></div>
            <a class="button button-secondary" href="{{ route('addresses', $scope) }}" wire:navigate>{{ __('Voir les adresses') }}</a>
        </div>
        <dl class="ping-summary">
            @foreach (['responding' => ['Répondent', 'cyan'], 'unresponsive' => ['Sans réponse', 'amber'], 'unprobed' => ['Jamais sondées', 'blue']] as $key => [$label, $tone])
                <a class="is-link" href="{{ route('addresses', $scope + ['ping' => $key]) }}" wire:navigate><dt><span class="badge tone-{{ $tone }}">{{ __($label) }}</span></dt><dd>{{ number_format($pingCounts[$key], 0, ',', ' ') }}</dd></a>
            @endforeach
        </dl>
    </section>

    @if ($macChanges->isNotEmpty() || $recentChanges->isNotEmpty())
        <div class="dashboard-grid dashboard-reveal mt-4">
            <section class="card">
                <div class="section-heading"><div><h2>{{ __('Changements de MAC récents') }}</h2><p>{{ __('Une adresse IP utilisée par un autre appareil peut signaler un conflit.') }}</p></div></div>
                @if ($macChanges->isEmpty())
                    <p class="muted px-5 pb-5 text-sm">{{ __('Aucun changement de MAC ces derniers jours.') }}</p>
                @else
                    <ul class="alert-list">@foreach ($macChanges as $alert)<x-alert-row :alert="$alert" :actions="false"/>@endforeach</ul>
                @endif
            </section>
            @if ($recentChanges->isNotEmpty())
                <section class="card">
                    <div class="section-heading">
                        <div><h2>{{ __('Dernières modifications') }}</h2><p>{{ __('Extrait du journal d’audit.') }}</p></div>
                        <a class="button button-secondary" href="{{ route('audit') }}" wire:navigate>{{ __('Journal d’audit') }} <x-icon name="arrow" class="size-4"/></a>
                    </div>
                    <ol class="timeline mx-5 mb-5">
                        @foreach ($recentChanges as $entry)
                            <li>
                                <div class="flex flex-wrap items-center gap-2"><x-audit-action :action="$entry->action"/><span class="text-sm font-semibold">{{ $entry->subject_label }}</span></div>
                                <small class="muted">{{ $entry->actor ?? __('Système') }} · {{ $entry->created_at->diffForHumans() }}</small>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif
        </div>
    @endif

    <section class="card dashboard-reveal mt-4">
        <div class="section-heading">
            <div><h2>{{ __('Dernières découvertes') }}</h2><p>{{ __('Réponses ICMP observées lors des derniers scans terminés.') }}</p></div>
            <a class="button button-secondary" href="{{ route('subnets', $scope) }}" wire:navigate>{{ __('Voir les réseaux') }} <x-icon name="arrow" class="size-4"/></a>
        </div>
        @if ($scans->isEmpty())
            <x-empty-state icon="radar" :title="__('Aucun scan terminé')" :text="__('Les résultats de découverte des sous-réseaux apparaîtront ici.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>{{ __('Réseau') }}</th><th>{{ __('Emplacement') }}</th><th>{{ __('Hôtes répondants') }}</th><th>{{ __('Dernier résultat') }}</th></tr></thead>
                <tbody>
                    @foreach ($scans as $subnet)
                        <tr>
                            <td class="font-semibold">{{ $subnet->name }}<br><code>{{ $subnet->cidr }}</code></td>
                            <td>{{ $subnet->location?->path() ?? __('Sans emplacement') }}</td>
                            <td>{{ $subnet->scan_responded }} / {{ $subnet->scan_targets }}</td>
                            <td>{{ $subnet->scanned_at->format(__('d/m/Y H:i')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</x-layouts.app>
