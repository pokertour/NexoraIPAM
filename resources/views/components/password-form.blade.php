<form method="post" action="{{ route('password.update') }}" {{ $attributes->class('grid gap-3') }}>
    @csrf
    <x-field name="current_password" :label="__('Mot de passe actuel')" type="password" autocomplete="current-password" required/>
    <x-field name="password" :label="__('Nouveau mot de passe')" type="password" autocomplete="new-password" :hint="__('Au moins 12 caractères.')" required/>
    <x-field name="password_confirmation" :label="__('Confirmer le nouveau mot de passe')" type="password" autocomplete="new-password" required/>
    <x-button type="submit" class="justify-self-start">{{ __('Enregistrer le mot de passe') }}</x-button>
</form>
