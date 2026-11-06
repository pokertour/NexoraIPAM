@props(['icon', 'title', 'text'])
<div class="empty-state">
    <span class="stat-icon tone-violet"><x-icon :name="$icon"/></span>
    <h3 class="font-semibold">{{ $title }}</h3>
    <p class="muted text-sm">{{ $text }}</p>
    {{ $slot }}
</div>
