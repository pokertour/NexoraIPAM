@props(['name', 'icon', 'routes' => null, 'badge' => null])
@php
    $active = request()->routeIs(...collect($routes ?? [$name])->flatMap(fn (string $route): array => [$route, $route.'.*'])->all());
    $label = trim((string) $slot);
@endphp
<a href="{{ route($name) }}" wire:navigate @class(['nav-link', 'active' => $active]) data-label="{{ $label }}" @if ($active) aria-current="page" @endif>
    <x-icon :name="$icon" class="size-[18px]"/>
    <span class="nav-text">{{ $slot }}</span>
    @if ($badge)<span class="nav-badge" aria-label="{{ trans_choice(':count alerte active|:count alertes actives', $badge) }}">{{ $badge > 99 ? '99+' : $badge }}</span>@endif
</a>
