@props(['title' => __('Vue d’ensemble'), 'standalone' => false])
@php($workspace = auth()->check() && ! $standalone)
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
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <div @class(['workspace' => $workspace, 'guest-workspace' => ! $workspace]) x-data="{ menu: false }" @keydown.escape.window="menu = false">
        @if ($workspace)
            <aside class="sidebar" :class="{ 'sidebar-open': menu }">
                <a href="{{ route('dashboard') }}" wire:navigate class="sidebar-brand" aria-label="{{ __('NexoraIPAM, accueil') }}"><x-logo/></a>
                <nav aria-label="{{ __('Navigation principale') }}">
                    <p class="nav-label">{{ __('Espace réseau') }}</p>
                    <x-nav-link name="dashboard" icon="dashboard">{{ __('Vue d’ensemble') }}</x-nav-link>
                    <x-nav-link name="locations" icon="locations">{{ __('Emplacements') }}</x-nav-link>
                    <x-nav-link name="subnets" icon="subnets">{{ __('Sous-réseaux') }}</x-nav-link>
                    <x-nav-link name="addresses" icon="addresses">{{ __('Adresses IP') }}</x-nav-link>
                    <x-nav-link name="vlans" icon="vlans">VLAN</x-nav-link>
                    <x-nav-link name="devices" icon="switch">{{ __('Équipements') }}</x-nav-link>
                    <x-nav-link name="alerts" icon="bell">{{ __('Alertes') }}</x-nav-link>
                    <x-nav-link name="tools" icon="tools">{{ __('Outils') }}</x-nav-link>
                    <x-nav-link name="about" icon="info">{{ __('À propos') }}</x-nav-link>
                    @can('administer')
                        <p class="nav-label mt-6">{{ __('Administration') }}</p>
                        <x-nav-link name="accounts" icon="accounts">{{ __('Utilisateurs') }}</x-nav-link>
                        <x-nav-link name="metadata" icon="tag">{{ __('Étiquettes et champs') }}</x-nav-link>
                        <x-nav-link name="audit" icon="audit">{{ __('Journal d’audit') }}</x-nav-link>
                        <x-nav-link name="preferences" icon="preferences">{{ __('Paramètres') }}</x-nav-link>
                    @endcan
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
                    @php($activeAlerts = \App\Models\Alert::active()->count())
                    <a href="{{ route('alerts') }}" wire:navigate class="icon-button bell-button" aria-label="{{ trans_choice(':count alerte active|:count alertes actives', $activeAlerts) }}" title="{{ trans_choice(':count alerte active|:count alertes actives', $activeAlerts) }}">
                        <x-icon name="bell" class="size-[18px]"/>
                        @if ($activeAlerts)<span class="bell-count">{{ $activeAlerts > 99 ? '99+' : $activeAlerts }}</span>@endif
                    </a>
                    <span class="topbar-status" title="NexoraIPAM {{ config('app.version') }}"><span class="pulse-dot"></span><span class="font-mono">{{ config('app.version') }}</span></span>
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
