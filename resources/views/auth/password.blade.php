@php($initial = auth()->user()->must_change_password)
<x-layouts.app :title="__('Mot de passe')" :standalone="$initial">
    <div @class(['password-stage', 'password-stage-initial' => $initial])>
        <div class="w-full max-w-xl">
            @if ($initial)<x-logo class="mx-auto mb-8 block w-44"/>@endif
            <p class="eyebrow">{{ __('MON COMPTE') }}</p>
            <h1 class="page-title mt-2">{{ __('Changer le mot de passe') }}</h1>
            <p class="muted mt-2">{{ auth()->user()->must_change_password ? __('Choisissez un mot de passe personnel avant d’accéder à l’espace de travail.') : __('Mettez à jour le mot de passe de votre compte interne.') }}</p>
            <x-password-form class="card mt-5 p-4"/>
        </div>
        @if ($initial)
            <form method="post" action="{{ route('logout') }}" class="mt-5 text-center">
                @csrf
                <x-button type="submit" variant="secondary">{{ __('Se déconnecter') }}</x-button>
            </form>
        @endif
    </div>
</x-layouts.app>
