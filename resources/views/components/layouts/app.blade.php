@props(['title' => __('Vue d’ensemble'), 'standalone' => false])
@php
    $workspace = auth()->check() && ! $standalone;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · NexoraIPAM</title>
    <link rel="icon" href="{{ asset('brand/nexora-icon-light.svg') }}" type="image/svg+xml">
    <script>
        window.NexoraTheme ??= {
            apply() {
                let preference;
                try { preference = localStorage.getItem('nexora-theme'); } catch {}
                const cookie = document.cookie.match(/(?:^|; )nexora-theme=(dark|light)(?:;|$)/)?.[1];
                const system = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                const theme = ['dark', 'light'].includes(preference)
                    ? preference : (cookie ?? system);
                if (document.documentElement.dataset.theme !== theme) {
                    document.documentElement.dataset.theme = theme;
                }
            },
            toggle() {
                const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                document.documentElement.dataset.theme = theme;
                try { localStorage.setItem('nexora-theme', theme); } catch {}

                document.cookie = 'nexora-theme=' + theme + '; Path=/; Max-Age=31536000; SameSite=Lax';
            },
        };
        window.NexoraTheme.apply();
        window.NexoraSidebar ??= {
            apply() {
                let narrow = false;
                try { narrow = localStorage.getItem('nexora-sidebar') === 'narrow'; } catch {}
                if (narrow) document.documentElement.dataset.sidebar = 'narrow';
                else delete document.documentElement.dataset.sidebar;
            },
            toggle() {
                const narrow = document.documentElement.dataset.sidebar !== 'narrow';
                try { localStorage.setItem('nexora-sidebar', narrow ? 'narrow' : 'wide'); } catch {}
                this.apply();
            },
        };
        window.NexoraSidebar.apply();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <div @class(['workspace' => $workspace, 'guest-workspace' => ! $workspace]) x-data="{ menu: false }" @keydown.escape.window="menu = false">
        @if ($workspace)
            @php
                $activeAlerts = \App\Models\Alert::active()->count();
            @endphp
            <aside class="sidebar" :class="{ 'sidebar-open': menu }">
                <div class="sidebar-top">
                    <a href="{{ route('dashboard') }}" wire:navigate class="sidebar-brand" aria-label="{{ __('NexoraIPAM, accueil') }}"><x-logo/><span class="sidebar-mark" aria-hidden="true"><img class="brand-light" src="{{ asset('brand/nexora-icon-light.svg') }}" alt=""><img class="brand-dark" src="{{ asset('brand/nexora-icon-dark.svg') }}" alt=""></span></a>
                    <button type="button" class="icon-button sidebar-collapse" onclick="window.NexoraSidebar.toggle()" aria-label="{{ __('Réduire ou agrandir le menu') }}" title="{{ __('Réduire ou agrandir le menu') }}"><x-icon name="chevron" class="size-4"/></button>
                </div>
                <nav aria-label="{{ __('Navigation principale') }}">
                    @foreach (\App\Support\Navigation::visibleGroups() as $group)
                        @php
                            $groupActive = collect($group['items'])->contains(fn (array $item): bool => request()->routeIs(...collect($item['routes'])->flatMap(fn (string $route): array => [$route, $route.'.*'])->all()));
                        @endphp
                        <div class="nav-group" x-data="{ open: $persist({{ $group['folded'] ? 'false' : 'true' }}).as('nexora-nav-{{ $group['key'] }}') }">
                            @if ($group['label'])
                                <button type="button" class="nav-label nav-group-toggle" @click="open = ! open" :aria-expanded="(open || {{ $groupActive ? 'true' : 'false' }}).toString()" aria-controls="nav-group-{{ $group['key'] }}" @if ($groupActive) disabled @endif>
                                    <span>{{ __($group['label']) }}</span>
                                    @unless ($groupActive)<x-icon name="chevron" class="nav-group-chevron size-3"/>@endunless
                                </button>
                            @endif
                            <div class="nav-group-items" id="nav-group-{{ $group['key'] }}" x-show="open || {{ $groupActive ? 'true' : 'false' }}" @if ($group['folded'] && ! $groupActive) x-cloak @endif>
                                @foreach ($group['items'] as $item)
                                    <x-nav-link :name="$item['route']" :icon="$item['icon']" :routes="$item['routes']" :badge="$item['route'] === 'alerts' ? $activeAlerts : null">{{ __($item['label']) }}</x-nav-link>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>
                <div class="sidebar-footer">
                    <a href="{{ route('profile') }}" class="sidebar-account" aria-label="{{ __('Ouvrir mon profil') }}">
                        <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                            <p class="muted text-xs">{{ __(\App\Livewire\Accounts::ROLES[auth()->user()->role] ?? auth()->user()->role) }}</p>
                        </div>
                    </a>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="icon-button" title="{{ __('Se déconnecter') }}" aria-label="{{ __('Se déconnecter') }}"><x-icon name="logout" class="size-4"/></button>
                    </form>
                </div>
            </aside>
            <button type="button" x-cloak x-show="menu" @click="menu = false" class="mobile-backdrop" aria-label="{{ __('Fermer le menu') }}"></button>
            <header class="topbar gap-2">
                <div class="breadcrumb">
                    <button type="button" class="icon-button mobile-menu" @click="menu = !menu" :aria-expanded="menu" aria-label="Menu"><x-icon name="menu"/></button>
                    <span class="muted hidden sm:inline">{{ __('Espace de travail') }}</span>
                    <span class="breadcrumb-sep hidden sm:inline-block" aria-hidden="true"></span>
                    <span class="truncate font-semibold">{{ $title }}</span>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <livewire:global-search/>
                    <a href="{{ route('alerts') }}" wire:navigate class="icon-button bell-button" aria-label="{{ trans_choice(':count alerte active|:count alertes actives', $activeAlerts) }}" title="{{ trans_choice(':count alerte active|:count alertes actives', $activeAlerts) }}">
                        <x-icon name="bell" class="size-[18px]"/>
                        @if ($activeAlerts)<span class="bell-count">{{ $activeAlerts > 99 ? '99+' : $activeAlerts }}</span>@endif
                    </a>
                    <a href="{{ route('about') }}" wire:navigate class="topbar-status" title="{{ __('À propos de NexoraIPAM :version', ['version' => config('app.version')]) }}"><span class="pulse-dot"></span><span class="font-mono">{{ config('app.version') }}</span></a>
                    <x-language-select/>
                    <x-account-menu/>
                    <x-theme-switch/>
                </div>
            </header>
        @else
            <div class="guest-theme flex items-center gap-2">
                <x-language-select/>
                <x-theme-switch/>
            </div>
        @endif
        <main id="main" @class(['main-content' => $workspace])>
            {{ $slot }}
            <footer class="app-footer">
                Copyright {{ now()->year }} · pokertour ·
                <a href="https://github.com/{{ config('app.repository') }}/blob/HEAD/LICENSE">NexoraIPAM Source Available License v1.0</a>
            </footer>
        </main>
    </div>
    <x-toasts/>
    <x-modal id="request-recovery" :title="__('Actualiser la page')" :automatic="false">
        <span hidden data-recovery-copy
            data-expired="{{ __('Votre session a expiré. Actualisez la page pour reprendre.') }}"
            data-empty="{{ __('Le serveur a renvoyé une réponse vide. Actualisez la page pour reprendre.') }}"
            data-failed="{{ __('L’action a échoué. Réessayez dans un instant.') }}"></span>
        <p class="muted mt-3 text-sm" data-recovery-message></p>
        <div class="dialog-actions mt-6">
            <form method="dialog"><x-button type="submit" variant="secondary">{{ __('Annuler') }}</x-button></form>
            <x-button data-reload-page>{{ __('Actualiser') }}</x-button>
        </div>
    </x-modal>
    @livewireScripts
</body>
</html>
