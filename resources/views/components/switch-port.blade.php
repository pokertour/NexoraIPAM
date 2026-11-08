@props(['port', 'selected' => false])
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
<button type="button" class="switch-port" data-state="{{ $port->state() }}" data-speed="{{ $tier }}" data-media="{{ $port->media ?? 'rj45' }}"
    @if ($port->isTrunk()) data-trunk @endif @if ($port->neighbor_name) data-neighbor @endif @if ($selected) aria-pressed="true" @else aria-pressed="false" @endif
    wire:click="select({{ $port->if_index }})" wire:key="port-{{ $port->if_index }}"
    title="{{ implode(' · ', array_filter([$port->name, $port->alias, \App\Support\PortMedia::LABELS[$port->media ?? 'rj45'] ?? null, $status, $port->speedLabel(), $vlan, $port->isTrunk() ? __('Trunk') : null, $port->neighbor_name])) }}"
    aria-label="{{ implode(', ', array_filter([$port->name, $status, $port->speedLabel(), $vlan])) }}">
    <span class="switch-port-led" aria-hidden="true"></span>
    <span class="switch-port-jack" aria-hidden="true"></span>
    <span class="switch-port-label">{{ $port->shortName() }}</span>
</button>
