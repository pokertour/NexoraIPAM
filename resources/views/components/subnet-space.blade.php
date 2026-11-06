@props(['subnet', 'map'])
<section class="card">
    <div class="section-heading">
        <div>
            <h2>Découpage de {{ $subnet->name }}</h2>
            <p><code>{{ $subnet->cidr }}</code> · {{ $subnet->location?->path() ?? 'Sans emplacement' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-secondary" href="{{ route('addresses', ['subnet' => $subnet->id]) }}" wire:navigate>Adresses du réseau</a>
            <a class="button button-secondary" href="{{ route('subnets') }}" wire:navigate>Tous les réseaux</a>
        </div>
    </div>
    <div class="px-5 pb-5">
        <p class="muted mb-4 text-sm">Les blocs verts sont libres. Les blocs rouges contiennent une adresse inventoriée ou chevauchent un autre réseau de cet emplacement.</p>
        @forelse ($map as $prefix => $blocks)
            <div class="mb-4">
                <h3 class="mb-2 text-sm font-semibold">/{{ $prefix }} · {{ count(array_filter($blocks, fn (array $block): bool => ! $block['occupied'])) }} / {{ count($blocks) }} libres</h3>
                <div class="space-grid">
                    @foreach ($blocks as $block)
                        @if ($block['occupied'])
                            <span class="space-cell" data-state="occupied" title="Occupé">{{ $block['cidr'] }}</span>
                        @else
                            @can('edit-inventory')
                                <button type="button" class="space-cell" data-state="free" title="Créer {{ $block['cidr'] }}"
                                    wire:click="openAllocation({{ $subnet->id }}, @js($block['cidr']))">{{ $block['cidr'] }}</button>
                            @else
                                <span class="space-cell" data-state="free" title="Libre">{{ $block['cidr'] }}</span>
                            @endcan
                        @endif
                    @endforeach
                </div>
            </div>
        @empty
            <p class="muted">Ce préfixe correspond déjà à une seule adresse.</p>
        @endforelse
    </div>
</section>
