<details class="account-menu" x-data @keydown.escape.stop="$el.open = false" @click.outside="$el.open = false">
    <summary class="button button-secondary" aria-label="{{ __('Mon compte') }}">
        <x-icon name="accounts" class="size-4"/>
        <span class="hidden sm:inline">{{ __('Mon compte') }}</span>
    </summary>
    <div class="account-panel card">
        <p class="px-3 pb-2 text-sm font-semibold">{{ auth()->user()->name }}</p>
        <a href="{{ route('profile') }}" class="nav-link">
            <x-icon name="accounts" class="size-4"/> {{ __('Mon profil') }}
        </a>
        <a href="{{ route('about') }}" wire:navigate class="nav-link">
            <x-icon name="info" class="size-4"/> {{ __('À propos') }}
        </a>
    </div>
</details>
