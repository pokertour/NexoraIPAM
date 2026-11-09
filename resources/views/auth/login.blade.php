<x-layouts.app :title="__('Connexion')">
    <div class="login-layout">
        <div class="login-visual">
            <x-logo class="w-52"/>
            <div class="login-message">
                <span class="eyebrow"><span class="pulse-dot"></span> {{ __('GESTION D’INFRASTRUCTURE') }}</span>
                <h2>{{ __('Votre réseau,') }}<br><span>{{ __('en clair.') }}</span></h2>
                <p>{{ __('Un espace unique pour cartographier vos sous-réseaux, suivre vos adresses IP et organiser vos VLAN.') }}</p>
                <div class="login-chips" aria-hidden="true">
                    <span class="login-chip"><i></i>10.20.0.0/16</span>
                    <span class="login-chip"><i></i>VLAN 120</span>
                    <span class="login-chip"><i></i>2001:db8::/48</span>
                </div>
            </div>
            <div class="text-sm text-indigo-200/60">{{ __('NexoraIPAM · Gestion des adresses IP') }}</div>
        </div>
        <div class="login-panel">
            <div class="login-card">
                <x-logo class="mb-10 block w-44 lg:hidden"/>
                <span class="eyebrow">{{ __('ESPACE DE TRAVAIL') }}</span>
                <h1 class="page-title mt-3">{{ __('Connexion') }}</h1>
                <p class="muted mt-2">{{ __('Accédez à votre inventaire réseau.') }}</p>
                @php($formMethods = collect($methods)->whereIn('key', ['local', 'ldap'])->values())
                @php($ssoFirst = $methods[0]['key'] === 'oidc')
                @php($formShown = false)
                <div class="login-methods-stack mt-8">
                    @foreach ($methods as $method)
                        @if ($method['key'] === 'oidc')
                            @unless ($loop->first)<div class="login-divider"><span>{{ __('ou') }}</span></div>@endunless
                            <a @class(['button sso-button w-full justify-center', 'button-primary' => $loop->first, 'button-secondary' => ! $loop->first]) href="{{ route('oidc.start') }}">
                                <x-icon :name="$method['icon']" class="size-5"/> {{ $method['label'] }}
                            </a>
                        @elseif (! $formShown)
                            @php($formShown = true)
                            @unless ($loop->first)<div class="login-divider"><span>{{ __('ou') }}</span></div>@endunless
                            <form class="grid gap-5" method="post" action="{{ route('login.submit') }}">
                                @csrf
                                @if ($formMethods->count() > 1)
                                    <fieldset class="login-switch">
                                        <legend class="sr-only">{{ __('Méthode de connexion') }}</legend>
                                        @foreach ($formMethods as $choice)
                                            <label class="login-switch-option">
                                                <input type="radio" name="provider" value="{{ $choice['key'] }}" @checked(old('provider', $formMethods[0]['key']) === $choice['key'])>
                                                <span><x-icon :name="$choice['icon']" class="size-4"/> {{ $choice['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </fieldset>
                                @else
                                    <input type="hidden" name="provider" value="local">
                                @endif
                                <x-field name="email" :label="__('Adresse e-mail')" type="email" autocomplete="username" :value="old('email')" :placeholder="__('vous@exemple.fr')" required/>
                                <x-field name="password" :label="__('Mot de passe')" type="password" autocomplete="current-password" required/>
                                @error('provider')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                <x-button type="submit" :variant="$ssoFirst ? 'secondary' : 'primary'" class="mt-1 justify-center">{{ __('Se connecter') }} <x-icon name="arrow" class="size-4"/></x-button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
