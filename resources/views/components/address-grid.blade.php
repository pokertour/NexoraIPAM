@props(['subnet', 'cells', 'delegatedCount'])
<section class="card mb-5">
    <div class="section-heading">
        <div><h2>{{ __('Carte des adresses') }}</h2><p>{{ $subnet->name }} · <code>{{ $subnet->cidr }}</code></p></div>
        <a class="button button-secondary" href="{{ route('subnets', ['space' => $subnet->id]) }}" wire:navigate>{{ __('Voir le découpage') }}</a>
    </div>
    <div class="px-5 pb-5">
        @if ($cells)
            <div class="space-grid address-grid" aria-label="{{ __('Adresses du sous-réseau') }}">
                @foreach ($cells as ['address' => $ip, 'record' => $record, 'child' => $child])
                    @php($state = $child ? 'delegated' : ($record?->status ?? 'free'))
                    @php($label = ['free' => 'Libre', 'delegated' => 'Déléguée', 'used' => 'Attribuée', 'reserved' => 'Réservée', 'available' => 'Disponible, déjà inventoriée'][$state])
                    @php($short = str_contains($ip, ':') ? $ip : '.'.str($ip)->afterLast('.'))
                    @if ($child)
                        <a class="space-cell" data-state="{{ $state }}" title="{{ $ip }} · {{ $child->name }}" href="{{ route('addresses', ['subnet' => $child->id]) }}" wire:navigate>{{ $short }}</a>
                    @else
                        @can('edit-inventory')
                            <button type="button" class="space-cell" data-state="{{ $state }}" title="{{ $ip }} · {{ __($label) }}" aria-label="{{ $ip }} · {{ __($label) }}"
                                wire:click="openAddress({{ $subnet->id }}, @js($ip))">{{ $short }}</button>
                        @else
                            <span class="space-cell" data-state="{{ $state }}" title="{{ $ip }} · {{ __($label) }}" aria-label="{{ $ip }} · {{ __($label) }}">{{ $short }}</span>
                        @endcan
                    @endif
                @endforeach
            </div>
            <div class="space-legend mt-3">
                @foreach (['free' => 'Libre', 'used' => 'Attribuée', 'reserved' => 'Réservée', 'available' => 'Disponible, inventoriée', 'delegated' => 'Déléguée'] as $state => $label)
                    <span><i class="space-cell" data-state="{{ $state }}" aria-hidden="true"></i>{{ __($label) }}</span>
                @endforeach
            </div>
        @else
            <p class="muted text-sm">{{ __('Cette plage est trop grande pour être affichée adresse par adresse. Les lignes vertes ci-dessous regroupent les adresses libres.') }}</p>
        @endif
        @if ($delegatedCount)
            <p class="muted mt-3 text-sm">{{ trans_choice(':count sous-réseau délégué. Ses adresses sont gérées depuis son propre inventaire.|:count sous-réseaux délégués. Leurs adresses sont gérées depuis leur propre inventaire.', $delegatedCount) }}</p>
        @endif
    </div>
</section>
