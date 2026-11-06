@props(['alert', 'actions' => true])
<li class="alert-row" data-severity="{{ $alert->severity }}" wire:key="alert-{{ $alert->id }}">
    <span class="alert-mark" aria-hidden="true"></span>
    <div class="min-w-0">
        <p class="flex flex-wrap items-center gap-2">
            <span class="font-semibold">{{ __(\App\Models\Alert::TYPES[$alert->type] ?? $alert->type) }}</span>
            <span @class(['badge', 'tone-amber' => $alert->severity === 'critical', 'tone-blue' => $alert->severity !== 'critical'])>{{ __(\App\Models\Alert::SEVERITIES[$alert->severity] ?? $alert->severity) }}</span>
        </p>
        <p class="muted mt-0.5 text-sm">
            @if ($url = $alert->url())<a class="table-link" href="{{ $url }}" wire:navigate>{{ $alert->message() }}</a>@else{{ $alert->message() }}@endif
        </p>
        <small class="muted tabular-nums">
            {{ $alert->created_at->format(__('d/m/Y H:i')) }}@if ($alert->last_seen_at && ! $alert->last_seen_at->equalTo($alert->created_at)) · {{ __('vu le :date', ['date' => $alert->last_seen_at->format(__('d/m/Y H:i'))]) }}@endif
            @if ($alert->acknowledged_at) · {{ __('acquittée par :name', ['name' => $alert->acknowledgedBy?->name ?? __('Système')]) }}@endif
            @if ($alert->resolved_at) · {{ __('résolue le :date', ['date' => $alert->resolved_at->format(__('d/m/Y H:i'))]) }}@endif
        </small>
    </div>
    @if ($actions && ! $alert->acknowledged_at && ! $alert->resolved_at)
        @can('edit-inventory')
            <x-button variant="secondary" wire:click="acknowledge({{ $alert->id }})" wire:loading.attr="disabled" wire:target="acknowledge({{ $alert->id }})">{{ __('Acquitter') }}</x-button>
        @endcan
    @endif
</li>
