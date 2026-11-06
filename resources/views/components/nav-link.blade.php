@props(['name', 'icon'])
<a href="{{ route($name) }}" wire:navigate @class(['nav-link', 'active' => request()->routeIs($name)]) @if (request()->routeIs($name)) aria-current="page" @endif>
    <x-icon :name="$icon" class="size-[18px]"/>
    {{ $slot }}
</a>
