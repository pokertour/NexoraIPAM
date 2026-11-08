<x-layouts.app :title="__('Profil')">
    <div class="page-heading"><div><p class="eyebrow">{{ __('MON COMPTE') }}</p><h1 class="page-title mt-2">{{ __('Mon profil') }}</h1></div></div>
    <div class="settings-grid">
        <section class="card settings-card">
            <h2>{{ __('Informations du compte') }}</h2>
            <dl class="result-list border-t-0 pt-0">
                <div><dt class="muted">{{ __('Adresse e-mail') }}</dt><dd>{{ str_ends_with($user->email, '@accounts.invalid') ? __('Non disponible') : $user->email }}</dd></div>
                <div><dt class="muted">{{ __('Source du compte') }}</dt><dd>{{ ['local' => __('Compte interne'), 'ldap' => __('Annuaire LDAP'), 'oidc' => 'OpenID Connect'][$user->provider] ?? $user->provider }}</dd></div>
                <div><dt class="muted">{{ __('Rôle') }}</dt><dd>{{ __(\App\Livewire\Accounts::ROLES[$user->role]) }}</dd></div>
            </dl>
            @if ($user->provider === 'local')
                <form method="post" action="{{ route('profile.rename') }}" class="grid gap-3">
                    @csrf
                    <x-field name="name" :label="__('Nom affiché')" :value="old('name', $user->name)" maxlength="100" required/>
                    <x-button type="submit" class="justify-self-start">{{ __('Enregistrer le nom') }}</x-button>
                </form>
            @else
                <dl><dt class="muted">{{ __('Nom affiché') }}</dt><dd>{{ $user->name }}</dd></dl>
            @endif
        </section>

        @if ($user->provider === 'local')
            <section class="card settings-card">
                <h2>{{ __('Sécurité') }}</h2>
                <details @if ($errors->hasAny(['current_password', 'password', 'password_confirmation'])) open @endif>
                    <summary class="button button-secondary cursor-pointer">
                        <x-icon name="key" class="size-4"/> {{ __('Changer mon mot de passe') }}
                    </summary>
                    <x-password-form class="mt-3"/>
                </details>

                <div class="border-t border-[var(--line)] pt-3">
                    <h3 class="font-semibold">{{ __('Authentification à deux facteurs') }}</h3>
                    <p>{{ $user->two_factor ? __('Activée') : __('Désactivée') }} · {{ __('Application d’authentification TOTP') }}</p>
                </div>
                @if ($recoveryCodes)
                    <div data-profile-secret class="rounded-lg border border-[var(--line)] bg-[var(--surface-hover)] p-3">
                        <h3 class="font-semibold">{{ __('Codes de secours') }}</h3>
                        <p>{{ __('Conservez ces codes dans un endroit sûr. Chacun permet une connexion et ne s’affiche qu’ici.') }}</p>
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
                        <x-field name="disable_password" :label="__('Mot de passe actuel')" type="password" autocomplete="current-password" required/>
                        <x-field name="disable_code" :label="__('Code de l’application ou code de secours')" autocomplete="one-time-code" maxlength="20" required/>
                        <x-button type="submit" variant="secondary" class="justify-self-start">{{ __('Désactiver la 2FA') }}</x-button>
                    </form>
                @elseif ($setupKey)
                    <div data-profile-secret>
                        <p>{{ __('Ajoutez un compte dans votre application d’authentification et saisissez cette clé. Choisissez un code à 6 chiffres renouvelé toutes les 30 secondes.') }}</p>
                        <code class="mt-2 block select-all">{{ $setupKey }}</code>
                    </div>
                    <form method="post" action="{{ route('profile.two-factor.enable') }}" class="grid gap-3">
                        @csrf
                        <x-field name="enable_password" :label="__('Mot de passe actuel')" type="password" autocomplete="current-password" required/>
                        <x-field name="setup_code" :label="__('Code de l’application')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required/>
                        <x-button type="submit" class="justify-self-start">{{ __('Activer la 2FA') }}</x-button>
                    </form>
                @else
                    @error('setup_code')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <form method="post" action="{{ route('profile.two-factor.setup') }}">
                        @csrf
                        <x-button type="submit">{{ __('Configurer la 2FA') }}</x-button>
                    </form>
                @endif
            </section>
        @endif

        <section class="card settings-card api-tokens">
            <div>
                <h2>{{ __('Jetons d’API') }}</h2>
                <p>{{ __('Accès à l’API REST pour vos scripts et outils d’automatisation. Un jeton agit avec les droits de votre compte.') }}</p>
            </div>
            @if ($plainToken)
                <div data-profile-secret class="token-reveal" x-data="{ copied: false }">
                    <p class="text-sm font-semibold">{{ __('Copiez ce jeton maintenant : il ne sera plus affiché.') }}</p>
                    <div class="token-value">
                        <code class="select-all" x-ref="token">{{ $plainToken }}</code>
                        <button type="button" class="icon-button" x-on:click="navigator.clipboard?.writeText($refs.token.textContent); copied = true" :aria-label="copied ? @js(__('Copié')) : @js(__('Copier le jeton'))"><x-icon name="copy" class="size-4" x-show="! copied"/><x-icon name="check" class="size-4" x-show="copied" x-cloak/></button>
                    </div>
                    <pre class="token-example"><code>curl -H "Authorization: Bearer {{ $plainToken }}" {{ url('/api/v1/me') }}</code></pre>
                </div>
            @endif
            @if ($tokens->isNotEmpty())
                <ul class="token-list">
                    @foreach ($tokens as $token)
                        <li>
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{ $token->name }}</span>
                                <small class="muted block">
                                    {{ in_array('write', $token->abilities, true) ? __('Lecture et écriture') : __('Lecture seule') }}
                                    · {{ $token->last_used_at ? __('utilisé :date', ['date' => $token->last_used_at->diffForHumans()]) : __('jamais utilisé') }}
                                    @if ($token->expires_at) · {{ $token->expires_at->isPast() ? __('expiré') : __('expire le :date', ['date' => $token->expires_at->format(__('d/m/Y'))]) }}@endif
                                </small>
                            </span>
                            <form method="post" action="{{ route('profile.tokens.destroy', $token->id) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="icon-button danger-text" aria-label="{{ __('Révoquer :name', ['name' => $token->name]) }}" title="{{ __('Révoquer') }}"><x-icon name="trash" class="size-4"/></button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form method="post" action="{{ route('profile.tokens.store') }}" class="grid gap-3">
                @csrf
                <div class="grid gap-3 sm:grid-cols-[1fr_9rem]">
                    <x-field name="token_name" :label="__('Nom du jeton')" :value="old('token_name')" :placeholder="__('Ansible production')" maxlength="100" required/>
                    <x-field name="token_days" :label="__('Expire dans (jours)')" type="number" min="1" max="3650" :value="old('token_days')" :placeholder="__('Jamais')"/>
                </div>
                <label class="checkbox"><input type="checkbox" name="token_write" value="1" @checked(old('token_write'))> {{ __('Autoriser l’écriture (selon les droits de votre rôle)') }}</label>
                <x-button type="submit" icon="key" class="justify-self-start">{{ __('Créer un jeton') }}</x-button>
            </form>
            <p class="muted text-xs">{{ __('Documentation et exemples dans le README, section REST API.') }}</p>
        </section>
    </div>
</x-layouts.app>
