@props(['device'])
@if ($device->pollPending())
    <span class="badge tone-blue"><span class="scan-dot"></span> {{ $device->poll_status === 'queued' ? __('En attente') : __('En cours') }}</span>
@elseif ($device->poll_status === 'unstable')
    <span class="badge tone-violet" title="{{ __('La dernière interrogation a échoué ; une nouvelle tentative suit. :error', ['error' => $device->poll_error]) }}">{{ __('Échec ponctuel') }}</span>
@elseif ($device->poll_status === 'failed' || in_array($device->poll_status, ['queued', 'running'], true))
    <span class="badge tone-amber" @if ($device->poll_error) title="{{ $device->poll_error }}" @endif>{{ __('Injoignable') }}</span>
@elseif ($device->polled_at)
    <span class="badge tone-cyan" title="{{ $device->polled_at->format(__('d/m/Y H:i')) }}">{{ __('En ligne') }}</span>
@else
    <span class="badge tone-blue">{{ __('Jamais interrogé') }}</span>
@endif
