@props(['address'])
@php($responded = $address->lastPingResponded())
@if ($responded === null)
    <span class="muted">{{ __('Jamais sondée') }}</span>
@else
    <span @class(['badge', 'tone-cyan' => $responded, 'tone-amber' => ! $responded])>
        {{ $responded ? __('Up · Répond') : __('Down · Pas de réponse') }}
    </span>
    <small class="muted mt-1 block">{{ $address->last_probe_at?->format(__('d/m/Y H:i')) }}</small>
    @if (! $responded && $address->last_seen_at)
        <small class="muted block">{{ __('Dernière réponse') }} · {{ $address->last_seen_at->format(__('d/m/Y H:i')) }}</small>
    @endif
@endif
