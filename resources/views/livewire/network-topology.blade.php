<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('INFRASTRUCTURE') }}</p><h1 class="page-title mt-2">{{ __('Topologie') }}</h1><p class="muted mt-2">{{ __('Liaisons entre équipements déduites des voisins LLDP, ou des adresses MAC que chaque switch apprend de l’autre.') }}</p></div>
        <a class="button button-secondary" href="{{ route('racks') }}" wire:navigate><x-icon name="rack" class="size-4"/> {{ __('Baies') }}</a>
    </div>

    @if ($nodes->isEmpty())
        <section class="card"><x-empty-state icon="topology" :title="__('Aucun équipement')" :text="__('Ajoutez et interrogez au moins deux switchs pour voir leurs liaisons.')"/></section>
    @else
        <section class="card topology-card">
            <div class="topology-legend">
                <span><i data-source="lldp"></i>LLDP</span>
                <span><i data-source="mac"></i>{{ __('Adresses MAC') }}</span>
                <span class="muted">{{ trans_choice(':count liaison détectée|:count liaisons détectées', $links->count()) }}</span>
            </div>
            <svg class="topology" viewBox="0 0 {{ \App\Livewire\NetworkTopology::WIDTH }} {{ \App\Livewire\NetworkTopology::HEIGHT }}" role="img" aria-label="{{ __('Topologie du réseau') }}">
                <defs>
                    <radialGradient id="node-glow"><stop offset="0" stop-color="#7c3aed" stop-opacity=".35"/><stop offset="1" stop-color="#7c3aed" stop-opacity="0"/></radialGradient>
                </defs>
                @foreach ($links as $link)
                    @php([$a, $b] = [$nodes[$link['a']->id], $nodes[$link['b']->id]])
                    <g class="topology-link" data-source="{{ $link['source'] }}">
                        <line x1="{{ $a['x'] }}" y1="{{ $a['y'] }}" x2="{{ $b['x'] }}" y2="{{ $b['y'] }}"/>
                        <text x="{{ (int) round($a['x'] + ($b['x'] - $a['x']) * .22) }}" y="{{ (int) round($a['y'] + ($b['y'] - $a['y']) * .22) - 6 }}">{{ $link['a_port']->name }}</text>
                        @if ($link['b_port'])<text x="{{ (int) round($b['x'] + ($a['x'] - $b['x']) * .22) }}" y="{{ (int) round($b['y'] + ($a['y'] - $b['y']) * .22) - 6 }}">{{ $link['b_port']->name }}</text>@endif
                    </g>
                @endforeach
                @foreach ($nodes as $node)
                    @php($device = $node['device'])
                    <a href="{{ route('devices.show', $device) }}" wire:navigate class="topology-node" data-state="{{ $device->poll_status === 'failed' ? 'down' : ($device->polled_at ? 'up' : 'unknown') }}">
                        <circle cx="{{ $node['x'] }}" cy="{{ $node['y'] }}" r="46" fill="url(#node-glow)"/>
                        <rect x="{{ $node['x'] - 70 }}" y="{{ $node['y'] - 22 }}" width="140" height="44" rx="10"/>
                        <circle class="topology-led" cx="{{ $node['x'] - 56 }}" cy="{{ $node['y'] - 8 }}" r="4"/>
                        <text class="topology-name" x="{{ $node['x'] - 46 }}" y="{{ $node['y'] - 4 }}">{{ \Illuminate\Support\Str::limit($device->name, 16) }}</text>
                        <text class="topology-host" x="{{ $node['x'] - 46 }}" y="{{ $node['y'] + 12 }}">{{ $device->host }}</text>
                    </a>
                @endforeach
            </svg>
        </section>

        @if ($links->isNotEmpty())
            <section class="card mt-4">
                <div class="table-scroll"><table>
                    <thead><tr><th>{{ __('Équipement') }}</th><th>{{ __('Port') }}</th><th>{{ __('Relié à') }}</th><th>{{ __('Port') }}</th><th>{{ __('Source') }}</th></tr></thead>
                    <tbody>
                        @foreach ($links as $link)
                            <tr>
                                <td><a class="table-link font-semibold" href="{{ route('devices.show', $link['a']) }}" wire:navigate>{{ $link['a']->name }}</a></td>
                                <td><a class="table-link font-mono text-xs" href="{{ route('devices.show', ['device' => $link['a']->id, 'port' => $link['a_port']->if_index]) }}" wire:navigate>{{ $link['a_port']->name }}</a></td>
                                <td><a class="table-link font-semibold" href="{{ route('devices.show', $link['b']) }}" wire:navigate>{{ $link['b']->name }}</a></td>
                                <td>@if ($link['b_port'])<a class="table-link font-mono text-xs" href="{{ route('devices.show', ['device' => $link['b']->id, 'port' => $link['b_port']->if_index]) }}" wire:navigate>{{ $link['b_port']->name }}</a>@else — @endif</td>
                                <td><span class="link-source" data-source="{{ $link['source'] }}">{{ $link['source'] === 'lldp' ? 'LLDP' : __('Adresses MAC') }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </section>
        @endif
    @endif
</div>
