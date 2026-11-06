<x-layouts.app title="Vue d’ensemble">
    @php($scope = $location ? ['location' => $location->id] : [])
    <div class="dashboard-filter">
        <h1 class="page-title">Vue d’ensemble</h1>
        @if ($locations->isNotEmpty())
            <form method="get" action="{{ route('dashboard') }}" class="dashboard-filter-form">
                <x-select name="location" aria-label="Emplacement du tableau de bord">
                    <x-location-options :locations="$locations" :selected="$location?->id" placeholder="Tous les emplacements"/>
                </x-select>
                <x-button type="submit" variant="secondary">Appliquer</x-button>
            </form>
        @endif
    </div>

    <div class="stat-grid dashboard-reveal mt-4">
        <x-stat-card icon="subnets" label="Sous-réseaux" :value="$networkCount" :href="route('subnets', $scope)"/>
        <x-stat-card icon="addresses" label="IP inventoriées" :value="$statuses->sum()" :href="route('addresses', $scope)" tone="blue"/>
        <x-stat-card icon="vlans" label="VLAN" :value="$vlanCount" :href="route('vlans', $scope)" tone="cyan"/>
        <x-stat-card icon="reserved" label="IP réservées" :value="$statuses['reserved']" :href="route('addresses', $scope)" tone="amber"/>
    </div>

    <div class="dashboard-grid dashboard-reveal mt-4">
        <section class="card">
            <div class="section-heading"><div><h2>Occupation IPv4</h2><p>Adresses attribuées et réservées sur la capacité utilisable.</p></div></div>
            <div class="capacity-summary">
                <div class="capacity-ring" style="--usage: {{ $ipv4['percentage'] }}%" aria-hidden="true">
                    <span>{{ number_format($ipv4['percentage'], 1, ',', ' ') }}<small>%</small></span>
                </div>
                <div class="capacity-copy">
                    <p class="font-semibold">{{ number_format($ipv4['assigned'], 0, ',', ' ') }} / {{ number_format($ipv4['capacity'], 0, ',', ' ') }} IPv4</p>
                    <p class="muted mt-1 text-sm">{{ $ipv4['capacity'] ? 'Plages IPv4 du périmètre' : 'Ajoutez un sous-réseau IPv4 pour suivre son occupation.' }}</p>
                </div>
            </div>
            <div class="usage-list">
                @foreach ($busiest as $network)
                    <div class="usage-row">
                        <div><p class="font-semibold">{{ $network['subnet']->name }}</p><code>{{ $network['subnet']->cidr }}</code></div>
                        <x-meter :value="$network['percentage']" :label="'Occupation de '.$network['subnet']->name" :tone="$network['percentage'] >= 80 ? 'amber' : 'violet'"/>
                        <span class="text-right text-sm font-semibold tabular-nums">{{ $network['percentage'] }} %</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="section-heading"><div><h2>État des adresses</h2><p>Répartition de la capacité IPv4 utilisable du périmètre.</p></div></div>
            <div class="usage-list" data-ipv4-address-states>
                @foreach (\App\Models\IpAddress::STATUSES as $key => $label)
                    @php($tone = ['used' => 'violet', 'reserved' => 'amber', 'available' => 'cyan'][$key])
                    <div class="status-row">
                        <span class="badge tone-{{ $tone }}">{{ $label }}</span>
                        <x-meter :value="$ipv4['capacity'] ? min(100, 100 * $ipv4States[$key] / $ipv4['capacity']) : 0" :label="$label" :tone="$tone"/>
                        <span class="text-right text-sm font-semibold tabular-nums">{{ number_format($ipv4States[$key], 0, ',', ' ') }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="card dashboard-reveal mt-4">
        <div class="section-heading">
            <div><h2>Dernier ping par adresse</h2><p>Résultats enregistrés dans le périmètre sélectionné.</p></div>
            <a class="button button-secondary" href="{{ route('addresses', $scope) }}" wire:navigate>Voir les adresses</a>
        </div>
        <dl class="ping-summary">
            @foreach (['responding' => ['Répondent', 'cyan'], 'unresponsive' => ['Sans réponse', 'amber'], 'unprobed' => ['Jamais sondées', 'blue']] as $key => [$label, $tone])
                <div><dt><span class="badge tone-{{ $tone }}">{{ $label }}</span></dt><dd>{{ number_format($pingCounts[$key], 0, ',', ' ') }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section class="card dashboard-reveal mt-4">
        <div class="section-heading">
            <div><h2>Dernières découvertes</h2><p>Réponses ICMP observées lors des derniers scans terminés.</p></div>
            <a class="button button-secondary" href="{{ route('subnets', $scope) }}" wire:navigate>Voir les réseaux <x-icon name="arrow" class="size-4"/></a>
        </div>
        @if ($scans->isEmpty())
            <x-empty-state icon="radar" title="Aucun scan terminé" text="Les résultats de découverte des sous-réseaux apparaîtront ici."/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>Réseau</th><th>Emplacement</th><th>Hôtes répondants</th><th>Dernier résultat</th></tr></thead>
                <tbody>
                    @foreach ($scans as $subnet)
                        <tr>
                            <td class="font-semibold">{{ $subnet->name }}<br><code>{{ $subnet->cidr }}</code></td>
                            <td>{{ $subnet->location?->path() ?? 'Sans emplacement' }}</td>
                            <td>{{ $subnet->scan_responded }} / {{ $subnet->scan_targets }}</td>
                            <td>{{ $subnet->scanned_at->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</x-layouts.app>
