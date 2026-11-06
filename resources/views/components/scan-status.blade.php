@props(['subnet'])
@if ($subnet->scanPending())
    <span class="badge tone-blue"><span class="scan-dot"></span> {{ $subnet->scan_status === 'queued' ? 'En attente' : 'En cours' }}</span>
@elseif (in_array($subnet->scan_status, ['failed', 'queued', 'running'], true))
    <span class="badge tone-amber">Échec ou délai dépassé</span>
@elseif ($subnet->scanned_at)
    <span class="badge tone-cyan" title="{{ $subnet->scanned_at->format('d/m/Y H:i') }}">{{ $subnet->scan_responded }}/{{ $subnet->scan_targets }} répondent</span>
@else
    <span class="muted">—</span>
@endif
