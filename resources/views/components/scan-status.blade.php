@props(['subnet'])
@if ($subnet->scanPending())
    <span class="badge tone-blue"><span class="scan-dot"></span> {{ $subnet->scan_status === 'queued' ? __('En attente') : __('En cours') }}</span>
@elseif (in_array($subnet->scan_status, ['failed', 'queued', 'running'], true))
    <span class="badge tone-amber">{{ __('Échec ou délai dépassé') }}</span>
@elseif ($subnet->scanned_at)
    <span class="badge tone-cyan" title="{{ $subnet->scanned_at->format(__('d/m/Y H:i')) }}">{{ __(':responded/:total répondent', ['responded' => $subnet->scan_responded, 'total' => $subnet->scan_targets]) }}</span>
@else
    <span class="muted">—</span>
@endif
