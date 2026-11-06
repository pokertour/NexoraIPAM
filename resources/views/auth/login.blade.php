<x-layouts.app title="Connexion">
    <div class="login-layout">
        <div class="login-visual">
            <x-logo class="w-52"/>
            <div class="login-message">
                <span class="eyebrow"><span class="pulse-dot"></span> GESTION D’INFRASTRUCTURE</span>
                <h2>Votre réseau,<br><span>en clair.</span></h2>
                <p>Un espace unique pour cartographier vos sous-réseaux, suivre vos adresses IP et organiser vos VLAN.</p>
            </div>
            <div class="text-sm text-slate-400">NexoraIPAM · Gestion IP open source</div>
        </div>
        <div class="login-panel">
            <div class="w-full max-w-sm">
                <x-logo class="mb-10 block w-44 lg:hidden"/>
                <span class="eyebrow">ESPACE DE TRAVAIL</span>
                <h1 class="page-title mt-3">Connexion</h1>
                <p class="muted mt-2">Accédez à votre inventaire réseau.</p>
                <form class="mt-8 grid gap-5" method="post" action="{{ route('login.submit') }}">
                    @csrf
                    @if ($ldap)
                        <x-field name="provider" label="Méthode de connexion" id="provider">
                            <option value="local" @selected(old('provider') !== 'ldap')>Compte interne</option>
                            <option value="ldap" @selected(old('provider') === 'ldap')>Annuaire LDAP</option>
                        </x-field>
                    @else
                        <input type="hidden" name="provider" value="local">
                    @endif
                    <x-field name="email" label="Adresse e-mail" type="email" autocomplete="username" :value="old('email')" placeholder="vous@exemple.fr" required/>
                    <x-field name="password" label="Mot de passe" type="password" autocomplete="current-password" required/>
                    <x-button type="submit" class="mt-2 justify-center">Se connecter <x-icon name="arrow" class="size-4"/></x-button>
                </form>
                @if ($oidc)
                    <a class="button button-secondary mt-4 w-full justify-center" href="{{ route('oidc.start') }}">Connexion avec OpenID Connect</a>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
