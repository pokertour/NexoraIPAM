@props(['title' => 'Vue d’ensemble', 'standalone' => false])
@php($workspace = auth()->check() && ! $standalone)
<!doctype html>
<html lang="fr">
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
                document.documentElement.dataset.theme = ['dark', 'light'].includes(preference)
                    ? preference : (cookie ?? system);
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
                <a href="{{ route('dashboard') }}" wire:navigate class="sidebar-brand" aria-label="NexoraIPAM, accueil"><x-logo/></a>
                <nav aria-label="Navigation principale">
                    <p class="nav-label">Espace réseau</p>
                    <x-nav-link name="dashboard" icon="dashboard">Vue d’ensemble</x-nav-link>
                    <x-nav-link name="locations" icon="locations">Emplacements</x-nav-link>
                    <x-nav-link name="subnets" icon="subnets">Sous-réseaux</x-nav-link>
                    <x-nav-link name="addresses" icon="addresses">Adresses IP</x-nav-link>
                    <x-nav-link name="vlans" icon="vlans">VLAN</x-nav-link>
                    <x-nav-link name="tools" icon="preferences">Outils</x-nav-link>
                    <x-nav-link name="about" icon="info">À propos</x-nav-link>
                    @can('administer')
                        <p class="nav-label mt-7">Administration</p>
                        <x-nav-link name="accounts" icon="accounts">Utilisateurs</x-nav-link>
                        <x-nav-link name="preferences" icon="preferences">Paramètres</x-nav-link>
                    @endcan
                </nav>
                <div class="sidebar-footer">
                    <a href="{{ route('profile') }}" class="sidebar-account" aria-label="Ouvrir mon profil">
                        <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                            <p class="muted text-xs">{{ \App\Livewire\Accounts::ROLES[auth()->user()->role] ?? auth()->user()->role }}</p>
                        </div>
                    </a>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="icon-button" title="Se déconnecter" aria-label="Se déconnecter"><x-icon name="logout" class="size-4"/></button>
                    </form>
                </div>
            </aside>
            <button type="button" x-cloak x-show="menu" @click="menu = false" class="mobile-backdrop" aria-label="Fermer le menu"></button>
            <header class="topbar">
                <div class="flex items-center gap-3">
                    <button type="button" class="icon-button mobile-menu" @click="menu = !menu" :aria-expanded="menu" aria-label="Menu"><x-icon name="menu"/></button>
                    <span class="muted hidden sm:inline">Espace de travail</span>
                    <span class="muted hidden sm:inline" aria-hidden="true">/</span>
                    <span class="font-semibold">{{ $title }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <x-account-menu/>
                    <x-theme-switch/>
                </div>
            </header>
        @else
            <div class="guest-theme"><x-theme-switch/></div>
        @endif
        <main id="main" @class(['main-content' => $workspace])>
            {{ $slot }}
            <footer class="app-footer">
                Copyright {{ now()->year }} · pokertour ·
                <a href="https://github.com/{{ config('app.repository') }}/blob/HEAD/LICENSE">GNU AGPL v3</a>
            </footer>
        </main>
    </div>
    <x-toasts/>
    <x-modal id="request-recovery" title="Actualiser la page" :automatic="false">
        <p class="muted mt-3 text-sm" data-recovery-message></p>
        <div class="dialog-actions mt-6">
            <form method="dialog"><x-button type="submit" variant="secondary">Annuler</x-button></form>
            <x-button data-reload-page>Actualiser</x-button>
        </div>
    </x-modal>
    @livewireScripts
</body>
</html>
