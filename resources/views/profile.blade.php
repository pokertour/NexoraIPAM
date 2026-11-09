<x-layouts.app :title="__('Profil')">
    @php($local = $user->provider === 'local')
    @php($hiddenEmail = str_ends_with($user->email, '@accounts.invalid'))
    <div class="page-heading"><div><p class="eyebrow">{{ __('MON COMPTE') }}</p><h1 class="page-title mt-2">{{ __('Mon profil') }}</h1><p class="muted mt-2">{{ __('Vos informations, votre adresse e-mail et la sécurité de votre connexion.') }}</p></div></div>

    <section class="card profile-hero">
        <span class="profile-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
        <div class="min-w-0">
            <h2 class="profile-name">{{ $user->name }}</h2>
            <p class="profile-email">{{ $hiddenEmail ? __('Adresse e-mail non disponible') : $user->email }}</p>
            <div class="profile-badges">
                <span class="badge tone-violet">{{ __(\App\Livewire\Accounts::ROLES[$user->role] ?? $user->role) }}</span>
                <span @class(['badge', 'tone-blue' => $user->provider === 'ldap', 'tone-violet' => $user->provider === 'oidc'])>{{ __(\App\Livewire\Accounts::PROVIDERS[$user->provider] ?? $user->provider) }}</span>
                @if ($local)
                    <span @class(['badge', 'tone-cyan' => $user->two_factor, 'tone-amber' => ! $user->two_factor])>{{ $user->two_factor ? __('2FA activée') : __('2FA désactivée') }}</span>
                @endif
            </div>
        </div>
        <dl class="profile-meta">
            <div><dt>{{ __('Compte créé') }}</dt><dd>{{ $user->created_at?->isoFormat('LL') ?? '—' }}</dd></div>
            <div><dt>{{ __('Connexion précédente') }}</dt><dd>{{ $logins->where('action', 'auth.login')->skip(1)->first()?->created_at?->diffForHumans() ?? __('Aucune') }}</dd></div>
        </dl>
    </section>

    @unless ($local)
        <div class="callout callout-info mb-4" role="note"><x-icon name="info" class="size-4"/> {{ __('Ce compte est géré par :source : le nom, l’adresse e-mail et le mot de passe se modifient à la source.', ['source' => __(\App\Livewire\Accounts::PROVIDERS[$user->provider] ?? $user->provider)]) }}</div>
    @endunless

    <div class="profile-sections">
        @if ($local)
            <section class="card profile-section">
                <header><h2>{{ __('Nom affiché') }}</h2><p>{{ __('Visible dans le journal d’audit et la liste des utilisateurs.') }}</p></header>
                <form method="post" action="{{ route('profile.rename') }}" class="profile-inline-form">
                    @csrf
                    <x-field name="name" :label="__('Nom affiché')" :value="old('name', $user->name)" maxlength="100" required/>
                    <x-button type="submit" variant="secondary">{{ __('Enregistrer') }}</x-button>
                </form>
            </section>

            <section class="card profile-section">
                <header><h2>{{ __('Adresse e-mail') }}</h2><p>{{ __('Sert à vous connecter et à recevoir les alertes. La nouvelle adresse est confirmée par un lien avant d’être utilisée.') }}</p></header>
                <div class="grid gap-4">
                    <p class="profile-current"><x-icon name="check" class="size-4"/> <span>{{ __('Adresse actuelle') }}</span> <strong>{{ $user->email }}</strong></p>
                    @if ($user->pending_email)
                        <div class="callout callout-info profile-pending" role="status">
                            <x-icon name="info" class="size-4"/>
                            <span class="min-w-0 flex-1">{!! __('En attente de confirmation : :email. Ouvrez le lien reçu à cette adresse dans l’heure.', ['email' => '<strong>'.e($user->pending_email).'</strong>']) !!}</span>
                            <form method="post" action="{{ route('profile.email.cancel') }}">@csrf<x-button type="submit" variant="secondary">{{ __('Annuler') }}</x-button></form>
                        </div>
                    @endif
                    @error('email', 'email')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    @if ($smtpEnabled)
                        <form method="post" action="{{ route('profile.email') }}" class="grid gap-3 sm:grid-cols-2">
                            @csrf
                            <x-field name="email" :label="__('Nouvelle adresse e-mail')" type="email" :value="old('email')" autocomplete="email" maxlength="255" required/>
                            <div class="field">
                                <label for="email_password">{{ __('Mot de passe actuel') }}</label>
                                <input id="email_password" name="email_password" type="password" class="input" autocomplete="current-password" required>
                                @error('email_password', 'email')<small class="field-error" role="alert">{{ $message }}</small>@enderror
                            </div>
                            <x-button type="submit" class="justify-self-start">{{ $user->pending_email ? __('Envoyer un nouveau lien') : __('Envoyer le lien de confirmation') }}</x-button>
                        </form>
                    @else
                        <p class="muted text-sm">{{ __('L’envoi d’e-mails n’est pas configuré : demandez à un administrateur de modifier votre adresse.') }}</p>
                    @endif
                </div>
            </section>

            <section class="card profile-section">
                <header><h2>{{ __('Mot de passe') }}</h2><p>{{ __('Au moins 12 caractères. Choisissez un mot de passe que vous n’utilisez pas ailleurs.') }}</p></header>
                <x-password-form class="sm:max-w-md"/>
            </section>

            <section class="card profile-section">
                <header>
                    <h2>{{ __('Authentification à deux facteurs') }}</h2>
                    <p>{{ __('Un code à 6 chiffres d’une application d’authentification (TOTP) est demandé à chaque connexion.') }}</p>
                </header>
                <div class="grid gap-4">
                    <p class="profile-current">
                        <x-icon :name="$user->two_factor ? 'check' : 'info'" class="size-4"/>
                        <span>{{ __('État') }}</span>
                        <span @class(['badge', 'tone-cyan' => $user->two_factor, 'tone-amber' => ! $user->two_factor])>{{ $user->two_factor ? __('Activée') : __('Désactivée') }}</span>
                    </p>
                    @if ($recoveryCodes)
                        <div data-profile-secret class="profile-secret">
                            <h3>{{ __('Codes de secours') }}</h3>
                            <p>{{ __('Conservez ces codes dans un endroit sûr. Chacun permet une connexion et ne s’affiche qu’ici.') }}</p>
                            <ul class="profile-codes">
                                @foreach ($recoveryCodes as $code)
                                    <li><code>{{ $code }}</code></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($user->two_factor)
                        <form method="post" action="{{ route('profile.two-factor.disable') }}" class="grid gap-3 sm:grid-cols-2">
                            @csrf
                            <x-field name="disable_password" :label="__('Mot de passe actuel')" type="password" autocomplete="current-password" required/>
                            <x-field name="disable_code" :label="__('Code de l’application ou code de secours')" autocomplete="one-time-code" maxlength="20" required/>
                            <x-button type="submit" variant="secondary" class="justify-self-start">{{ __('Désactiver la 2FA') }}</x-button>
                        </form>
                    @elseif ($setupKey)
                        <div data-profile-secret class="profile-secret">
                            <h3>{{ __('Clé de configuration') }}</h3>
                            <p>{{ __('Ajoutez un compte dans votre application d’authentification et saisissez cette clé. Choisissez un code à 6 chiffres renouvelé toutes les 30 secondes.') }}</p>
                            <code class="profile-key select-all">{{ $setupKey }}</code>
                        </div>
                        <form method="post" action="{{ route('profile.two-factor.enable') }}" class="grid gap-3 sm:grid-cols-2">
                            @csrf
                            <x-field name="enable_password" :label="__('Mot de passe actuel')" type="password" autocomplete="current-password" required/>
                            <x-field name="setup_code" :label="__('Code de l’application')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required/>
                            <x-button type="submit" class="justify-self-start">{{ __('Activer la 2FA') }}</x-button>
                        </form>
                    @else
                        @error('setup_code')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        <form method="post" action="{{ route('profile.two-factor.setup') }}">
                            @csrf
                            <x-button type="submit" icon="key">{{ __('Configurer la 2FA') }}</x-button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        <section class="card profile-section">
            <header><h2>{{ __('Connexions récentes') }}</h2><p>{{ __('Si une connexion ne vient pas de vous, changez votre mot de passe et prévenez un administrateur.') }}</p></header>
            @if ($logins->isEmpty())
                <p class="muted text-sm">{{ __('Aucune connexion enregistrée.') }}</p>
            @else
                <ul class="profile-logins">
                    @foreach ($logins as $login)
                        <li>
                            <span @class(['badge', 'tone-cyan' => $login->action === 'auth.login', 'tone-amber' => $login->action !== 'auth.login'])>{{ $login->action === 'auth.login' ? __('Réussie') : __('Échouée') }}</span>
                            <time datetime="{{ $login->created_at->toIso8601String() }}" title="{{ $login->created_at->isoFormat('LLL') }}">{{ $login->created_at->diffForHumans() }}</time>
                            <code>{{ $login->ip_address ?? '—' }}</code>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.app>
