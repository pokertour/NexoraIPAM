@props(['port', 'selected' => false, 'colors' => [], 'outlet' => null])
@php
    $tier = match (true) {
        ! $port->speed_mbps => 'none',
        $port->speed_mbps >= 10000 => '10g',
        $port->speed_mbps >= 1000 => '1g',
        $port->speed_mbps >= 100 => '100m',
        default => 'low',
    };
    $status = ['up' => __('Connecté'), 'down' => __('Déconnecté'), 'disabled' => __('Désactivé')][$port->state()];
    $vlan = $port->pvid ? 'VLAN '.$port->pvid : null;
@endphp
<button type="button" class="switch-port" data-state="{{ $port->state() }}" data-speed="{{ $tier }}" data-media="{{ $port->connector() ?? 'rj45' }}"
    @if ($port->isTrunk()) data-trunk @endif @if ($port->wan) data-wan @endif @if ($port->neighbor_name) data-neighbor @endif @if ($selected) aria-pressed="true" @else aria-pressed="false" @endif
    wire:click="select({{ $port->if_index }})" wire:key="port-{{ $port->if_index }}"
    data-vlans="{{ implode(' ', array_unique(array_filter([$port->pvid, ...($port->untagged_vlans ?? []), ...($port->tagged_vlans ?? [])]))) }}"
    :class="{ 'is-dimmed': vlan !== null && ! $el.dataset.vlans.split(' ').includes(String(vlan)) }"
    title="{{ implode(' · ', array_filter([$port->name, $port->alias, \App\Support\PortMedia::LABELS[$port->connector() ?? 'rj45'] ?? null, $status, $port->speedLabel(), $vlan, $port->isTrunk() ? __('Trunk') : null, $port->neighbor_name, $outlet ? __('Prise :name', ['name' => $outlet]) : null, $port->wan ? 'WAN'.($port->wan_label ? ' · '.$port->wan_label : '') : null])) }}"
    aria-label="{{ implode(', ', array_filter([$port->name, $status, $port->speedLabel(), $vlan])) }}">
    <span class="switch-port-led" aria-hidden="true"></span>
    <span class="switch-port-jack" aria-hidden="true"></span>
    <span class="switch-port-label">{{ $port->shortName() }}</span>
    @if ($port->pvid)<span class="switch-port-vlan" style="--vlan: {{ $colors[$port->pvid] ?? \App\Support\VlanColor::for($port->pvid) }}" aria-hidden="true"></span>@endif
</button>
