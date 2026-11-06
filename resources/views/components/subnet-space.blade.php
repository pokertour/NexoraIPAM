@props(['subnet', 'map'])
<section class="card">
    <div class="section-heading">
        <div>
            <h2>{{ __('Découpage de :name', ['name' => $subnet->name]) }}</h2>
            <p><code>{{ $subnet->cidr }}</code> · {{ $subnet->location?->path() ?? __('Sans emplacement') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="button button-secondary" href="{{ route('addresses', ['subnet' => $subnet->id]) }}" wire:navigate>{{ __('Adresses du réseau') }}</a>
            <a class="button button-secondary" href="{{ route('subnets') }}" wire:navigate>{{ __('Tous les réseaux') }}</a>
        </div>
    </div>
    <div class="px-5 pb-5">
        <p class="muted mb-4 text-sm">{{ __('Les blocs verts sont libres. Les blocs rouges contiennent une adresse inventoriée ou chevauchent un autre réseau de cet emplacement.') }}</p>
        @forelse ($map as $prefix => $blocks)
            <div class="mb-4">
                <h3 class="mb-2 text-sm font-semibold">/{{ $prefix }} · {{ __(':free / :total libres', ['free' => count(array_filter($blocks, fn (array $block): bool => ! $block['occupied'])), 'total' => count($blocks)]) }}</h3>
                <div class="space-grid">
                    @foreach ($blocks as $block)
                        @if ($block['occupied'])
                            <span class="space-cell" data-state="occupied" title="{{ __('Occupé') }}">{{ $block['cidr'] }}</span>
                        @else
                            @can('edit-inventory')
                                <button type="button" class="space-cell" data-state="free" title="{{ __('Créer :cidr', ['cidr' => $block['cidr']]) }}"
                                    wire:click="openAllocation({{ $subnet->id }}, @js($block['cidr']))">{{ $block['cidr'] }}</button>
                            @else
                                <span class="space-cell" data-state="free" title="{{ __('Libre') }}">{{ $block['cidr'] }}</span>
                            @endcan
                        @endif
                    @endforeach
                </div>
            </div>
        @empty
            <p class="muted">{{ __('Ce préfixe correspond déjà à une seule adresse.') }}</p>
        @endforelse
    </div>
</section>
