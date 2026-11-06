@props(['icon', 'label', 'value', 'href', 'tone' => 'violet'])
<a class="card stat tone-card-{{ $tone }}" href="{{ $href }}" wire:navigate>
    <div class="stat-top">
        <span class="stat-icon tone-{{ $tone }}"><x-icon :name="$icon" class="size-[18px]"/></span>
        <x-icon name="arrow" class="stat-arrow size-4 -rotate-45"/>
    </div>
    <div>
        <p class="stat-value">{{ number_format($value, 0, ',', ' ') }}</p>
        <p class="stat-label mt-1.5">{{ $label }}</p>
    </div>
</a>
