<x-layouts.app title="Profil">
    <div class="page-heading"><h1 class="page-title">Mon profil</h1></div>
    <div class="settings-grid">
        <section class="card settings-card">
            <h2>Informations du compte</h2>
            <dl class="grid gap-2">
                <div><dt class="muted">Adresse e-mail</dt><dd>{{ str_ends_with($user->email, '@accounts.invalid') ? 'Non disponible' : $user->email }}</dd></div>
                <div><dt class="muted">Source du compte</dt><dd>{{ ['local' => 'Compte interne', 'ldap' => 'Annuaire LDAP', 'oidc' => 'OpenID Connect'][$user->provider] ?? $user->provider }}</dd></div>
                <div><dt class="muted">Rôle</dt><dd>{{ \App\Livewire\Accounts::ROLES[$user->role] }}</dd></div>
            </dl>
            @if ($user->provider === 'local')
                <form method="post" action="{{ route('profile.rename') }}" class="grid gap-3">
                    @csrf
                    <x-field name="name" label="Nom affiché" :value="old('name', $user->name)" maxlength="100" required/>
                    <x-button type="submit" class="justify-self-start">Enregistrer le nom</x-button>
                </form>
            @else
                <dl><dt class="muted">Nom affiché</dt><dd>{{ $user->name }}</dd></dl>
            @endif
        </section>

        @if ($user->provider === 'local')
            <section class="card settings-card">
                <h2>Sécurité</h2>
                <details @if ($errors->hasAny(['current_password', 'password', 'password_confirmation'])) open @endif>
                    <summary class="button button-secondary cursor-pointer">
                        <x-icon name="key" class="size-4"/> Changer mon mot de passe
                    </summary>
                    <x-password-form class="mt-3"/>
                </details>

                <div class="border-t border-slate-500/20 pt-3">
                    <h3 class="font-semibold">Authentification à deux facteurs</h3>
                    <p>{{ $user->two_factor ? 'Activée' : 'Désactivée' }} · Application d’authentification TOTP</p>
                </div>
                @if ($recoveryCodes)
                    <div data-profile-secret class="rounded-lg border border-slate-500/20 p-3">
                        <h3 class="font-semibold">Codes de secours</h3>
                        <p>Conservez ces codes dans un endroit sûr. Chacun permet une connexion et ne s’affiche qu’ici.</p>
                        <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                            @foreach ($recoveryCodes as $code)
                                <li><code>{{ $code }}</code></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($user->two_factor)
                    <form method="post" action="{{ route('profile.two-factor.disable') }}" class="grid gap-3">
                        @csrf
                        <x-field name="disable_password" label="Mot de passe actuel" type="password" autocomplete="current-password" required/>
                        <x-field name="disable_code" label="Code de l’application ou code de secours" autocomplete="one-time-code" maxlength="20" required/>
                        <x-button type="submit" variant="secondary" class="justify-self-start">Désactiver la 2FA</x-button>
                    </form>
                @elseif ($setupKey)
                    <div data-profile-secret>
                        <p>Ajoutez un compte dans votre application d’authentification et saisissez cette clé. Choisissez un code à 6 chiffres renouvelé toutes les 30 secondes.</p>
                        <code class="mt-2 block select-all">{{ $setupKey }}</code>
                    </div>
                    <form method="post" action="{{ route('profile.two-factor.enable') }}" class="grid gap-3">
                        @csrf
                        <x-field name="enable_password" label="Mot de passe actuel" type="password" autocomplete="current-password" required/>
                        <x-field name="setup_code" label="Code de l’application" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required/>
                        <x-button type="submit" class="justify-self-start">Activer la 2FA</x-button>
                    </form>
                @else
                    @error('setup_code')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <form method="post" action="{{ route('profile.two-factor.setup') }}">
                        @csrf
                        <x-button type="submit">Configurer la 2FA</x-button>
                    </form>
                @endif
            </section>
        @endif
    </div>
</x-layouts.app>
