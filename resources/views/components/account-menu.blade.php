<details class="account-menu" x-data @keydown.escape.stop="$el.open = false" @click.outside="$el.open = false">
    <summary class="button button-secondary">
        <x-icon name="accounts" class="size-4"/>
        <span class="hidden sm:inline">Mon compte</span>
    </summary>
    <div class="account-panel card">
        <p class="px-3 pb-2 text-sm font-semibold">{{ auth()->user()->name }}</p>
        <a href="{{ route('profile') }}" class="nav-link">
            <x-icon name="accounts" class="size-4"/> Mon profil
        </a>
    </div>
</details>
