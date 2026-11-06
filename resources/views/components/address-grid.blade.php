@props(['subnet', 'cells', 'delegatedCount'])
<section class="card mb-5">
    <div class="section-heading">
        <div><h2>{{ __('Carte des adresses') }}</h2><p>{{ $subnet->name }} · <code>{{ $subnet->cidr }}</code></p></div>
        <div class="flex flex-wrap gap-2">
            @can('edit-inventory')
                <x-button icon="plus" variant="secondary" wire:click="openNextFree({{ $subnet->id }})" wire:loading.attr="disabled" wire:target="openNextFree">{{ __('Prochaine IP libre') }}</x-button>
            @endcan
            <a class="button button-secondary" href="{{ route('subnets', ['space' => $subnet->id]) }}" wire:navigate>{{ __('Voir le découpage') }}</a>
        </div>
    </div>
    @if ($subnet->gateway || $subnet->dns_servers || $subnet->domain || $subnet->dhcp_start)
        <dl class="network-facts">
            @if ($subnet->gateway)<div><dt>{{ __('Passerelle') }}</dt><dd><code>{{ $subnet->gateway }}</code></dd></div>@endif
            @if ($subnet->dns_servers)<div><dt>{{ __('Serveurs DNS') }}</dt><dd><code>{{ $subnet->dns_servers }}</code></dd></div>@endif
            @if ($subnet->domain)<div><dt>{{ __('Domaine DNS') }}</dt><dd><code>{{ $subnet->domain }}</code></dd></div>@endif
            @if ($subnet->dhcp_start)<div><dt>{{ __('Plage DHCP') }}</dt><dd><code>{{ $subnet->dhcp_start }} – {{ $subnet->dhcp_end }}</code></dd></div>@endif
        </dl>
    @endif
    <div class="p-5">
        @if ($cells)
            <div class="space-grid address-grid" aria-label="{{ __('Adresses du sous-réseau') }}">
                @foreach ($cells as ['address' => $ip, 'record' => $record, 'child' => $child])
                    @php($state = $child ? 'delegated' : ($record?->status ?? 'free'))
                    @php($label = ['free' => 'Libre', 'delegated' => 'Déléguée', 'used' => 'Attribuée', 'reserved' => 'Réservée', 'available' => 'Disponible, déjà inventoriée'][$state])
                    @php($short = str_contains($ip, ':') ? $ip : '.'.str($ip)->afterLast('.'))
                    @php($role = $ip === $subnet->gateway ? 'gateway' : ($subnet->inDhcpRange($ip) ? 'dhcp' : null))
                    @php($labelText = __($label).($role === 'gateway' ? ' · '.__('Passerelle') : ($role === 'dhcp' ? ' · '.__('Plage DHCP') : '')))
                    @if ($child)
                        <a class="space-cell" data-state="{{ $state }}" @if ($role) data-role="{{ $role }}" @endif title="{{ $ip }} · {{ $child->name }}" href="{{ route('addresses', ['subnet' => $child->id]) }}" wire:navigate>{{ $short }}</a>
                    @else
                        @can('edit-inventory')
                            <button type="button" class="space-cell" data-state="{{ $state }}" @if ($role) data-role="{{ $role }}" @endif title="{{ $ip }} · {{ $labelText }}" aria-label="{{ $ip }} · {{ $labelText }}"
                                wire:click="openAddress({{ $subnet->id }}, @js($ip))">{{ $short }}</button>
                        @else
                            <span class="space-cell" data-state="{{ $state }}" @if ($role) data-role="{{ $role }}" @endif title="{{ $ip }} · {{ $labelText }}" aria-label="{{ $ip }} · {{ $labelText }}">{{ $short }}</span>
                        @endcan
                    @endif
                @endforeach
            </div>
            <div class="space-legend mt-4">
                @foreach (['free' => 'Libre', 'used' => 'Attribuée', 'reserved' => 'Réservée', 'available' => 'Disponible, inventoriée', 'delegated' => 'Déléguée'] as $state => $label)
                    <span><i class="space-cell" data-state="{{ $state }}" aria-hidden="true"></i>{{ __($label) }}</span>
                @endforeach
                @if ($subnet->gateway)<span><i class="space-cell" data-state="free" data-role="gateway" aria-hidden="true"></i>{{ __('Passerelle') }}</span>@endif
                @if ($subnet->dhcp_start)<span><i class="space-cell" data-state="free" data-role="dhcp" aria-hidden="true"></i>{{ __('Plage DHCP') }}</span>@endif
            </div>
        @else
            <p class="muted text-sm">{{ __('Cette plage est trop grande pour être affichée adresse par adresse. Les lignes vertes ci-dessous regroupent les adresses libres.') }}</p>
        @endif
        @if ($delegatedCount)
            <p class="muted mt-3 text-sm">{{ trans_choice(':count sous-réseau délégué. Ses adresses sont gérées depuis son propre inventaire.|:count sous-réseaux délégués. Leurs adresses sont gérées depuis leur propre inventaire.', $delegatedCount) }}</p>
        @endif
    </div>
</section>
