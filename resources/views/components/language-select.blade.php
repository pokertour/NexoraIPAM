@php($locale = app()->getLocale())
@php($languages = ['fr' => ['Français', 'France'], 'en' => ['English', 'United Kingdom']])
<details class="locale-menu" x-data @keydown.escape.stop="$el.open = false; $el.querySelector('summary').focus()" @click.outside="$el.open = false">
    <summary class="locale-trigger" title="{{ __('Langue') }}" aria-label="{{ __('Langue') }} · {{ $languages[$locale][0] ?? $locale }}">
        <x-icon name="globe" class="size-4"/>
        <span class="locale-code">{{ strtoupper($locale) }}</span>
        <x-icon name="chevron" class="locale-chevron size-3"/>
    </summary>
    <form method="post" action="{{ route('locale.update') }}" id="navbar-locale" class="locale-panel card">
        @csrf
        <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
        <p class="locale-title">{{ __('Langue') }}</p>
        @foreach ($languages as $code => [$name, $region])
            <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" class="locale-option" aria-current="{{ $locale === $code ? 'true' : 'false' }}">
                <span class="locale-badge">{{ strtoupper($code) }}</span>
                <span class="min-w-0"><span class="block font-semibold">{{ $name }}</span><small class="muted block">{{ $region }}</small></span>
                @if ($locale === $code)<x-icon name="check" class="locale-check size-4"/>@endif
            </button>
        @endforeach
    </form>
</details>
