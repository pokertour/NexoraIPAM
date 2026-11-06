@props(['icon', 'label', 'value', 'href', 'tone' => 'violet'])
<a class="card stat" href="{{ $href }}" wire:navigate>
    <span class="stat-icon tone-{{ $tone }}"><x-icon :name="$icon"/></span>
    <div>
        <p class="muted text-sm">{{ $label }}</p>
        <p class="text-xl font-semibold tabular-nums">{{ number_format($value, 0, ',', ' ') }}</p>
    </div>
    <x-icon name="arrow" class="muted ml-auto size-4"/>
</a>
