@props(['current'])
@php
    $tabs = \App\Support\Navigation::tabsOf($current) ?? [];
@endphp
@if (count($tabs) > 1)
    <nav {{ $attributes->class('page-tabs print-hidden') }} aria-label="{{ __('Sections de la page') }}">
        @foreach ($tabs as $tab)
            <a href="{{ route($tab['route']) }}" wire:navigate @class(['page-tab', 'active' => $tab['route'] === $current]) @if ($tab['route'] === $current) aria-current="page" @endif>{{ __($tab['label']) }}</a>
        @endforeach
    </nav>
@endif
