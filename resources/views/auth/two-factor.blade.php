<x-layouts.app title="Vérification en deux étapes">
    <div class="password-stage-initial">
        <div class="w-full max-w-md">
            <x-logo class="mx-auto mb-6 block w-44"/>
            <div class="card settings-card">
                <div>
                    <h1 class="page-title">Vérification en deux étapes</h1>
                    <p>Saisissez le code de votre application d’authentification ou un code de secours.</p>
                </div>
                <form method="post" action="{{ route('two-factor.verify') }}" class="grid gap-3">
                    @csrf
                    <x-field name="code" label="Code de vérification" autocomplete="one-time-code" maxlength="20" autofocus required/>
                    <x-button type="submit" class="justify-self-start">Vérifier</x-button>
                </form>
                <a href="{{ route('login') }}" class="table-link">Revenir à la connexion</a>
            </div>
        </div>
    </div>
</x-layouts.app>
