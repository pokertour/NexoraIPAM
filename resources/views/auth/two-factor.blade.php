<x-layouts.app :title="__('Vérification en deux étapes')">
    <div class="password-stage-initial">
        <div class="w-full max-w-md">
            <x-logo class="mx-auto mb-6 block w-44"/>
            <div class="card settings-card">
                <div>
                    <h1 class="page-title">{{ __('Vérification en deux étapes') }}</h1>
                    <p>{{ __('Saisissez le code de votre application d’authentification ou un code de secours.') }}</p>
                </div>
                <form method="post" action="{{ route('two-factor.verify') }}" class="grid gap-3">
                    @csrf
                    <x-field name="code" :label="__('Code de vérification')" autocomplete="one-time-code" maxlength="20" autofocus required/>
                    <x-button type="submit" class="justify-self-start">{{ __('Vérifier') }}</x-button>
                </form>
                <a href="{{ route('login') }}" class="table-link">{{ __('Revenir à la connexion') }}</a>
            </div>
        </div>
    </div>
</x-layouts.app>
