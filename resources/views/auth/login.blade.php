<x-layouts.app :title="__('Connexion')">
    <div class="login-layout">
        <div class="login-visual">
            <x-logo class="w-52"/>
            <div class="login-message">
                <span class="eyebrow"><span class="pulse-dot"></span> {{ __('GESTION D’INFRASTRUCTURE') }}</span>
                <h2>{{ __('Votre réseau,') }}<br><span>{{ __('en clair.') }}</span></h2>
                <p>{{ __('Un espace unique pour cartographier vos sous-réseaux, suivre vos adresses IP et organiser vos VLAN.') }}</p>
            </div>
            <div class="text-sm text-slate-400">{{ __('NexoraIPAM · Gestion des adresses IP') }}</div>
        </div>
        <div class="login-panel">
            <div class="w-full max-w-sm">
                <x-logo class="mb-10 block w-44 lg:hidden"/>
                <span class="eyebrow">{{ __('ESPACE DE TRAVAIL') }}</span>
                <h1 class="page-title mt-3">{{ __('Connexion') }}</h1>
                <p class="muted mt-2">{{ __('Accédez à votre inventaire réseau.') }}</p>
                <form class="mt-8 grid gap-5" method="post" action="{{ route('login.submit') }}">
                    @csrf
                    @if ($ldap)
                        <x-field name="provider" :label="__('Méthode de connexion')" id="provider">
                            <option value="local" @selected(old('provider') !== 'ldap')>{{ __('Compte interne') }}</option>
                            <option value="ldap" @selected(old('provider') === 'ldap')>{{ __('Annuaire LDAP') }}</option>
                        </x-field>
                    @else
                        <input type="hidden" name="provider" value="local">
                    @endif
                    <x-field name="email" :label="__('Adresse e-mail')" type="email" autocomplete="username" :value="old('email')" :placeholder="__('vous@exemple.fr')" required/>
                    <x-field name="password" :label="__('Mot de passe')" type="password" autocomplete="current-password" required/>
                    <x-button type="submit" class="mt-2 justify-center">{{ __('Se connecter') }} <x-icon name="arrow" class="size-4"/></x-button>
                </form>
                @if ($oidc)
                    <a class="button button-secondary mt-4 w-full justify-center" href="{{ route('oidc.start') }}">{{ __('Connexion avec OpenID Connect') }}</a>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
