@php($locale = app()->getLocale())

<form method="post" action="{{ route('locale.update') }}" class="flex shrink-0 items-center gap-2"
    x-data x-on:change="if ($event.target.name === 'locale') $el.requestSubmit()">
    @csrf
    <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
    <label for="navbar-locale" class="sr-only">{{ __('Langue') }}</label>
    <x-select name="locale" id="navbar-locale" class="w-28">
        <option value="fr" lang="fr" @selected($locale === 'fr')>Français</option>
        <option value="en" lang="en" @selected($locale === 'en')>English</option>
    </x-select>
    <noscript><x-button type="submit" variant="secondary">{{ __('Appliquer') }}</x-button></noscript>
</form>
