<button type="button" class="icon-button" aria-label="{{ __('Changer le thème') }}" title="{{ __('Changer le thème') }}" x-data
    @click="window.NexoraTheme.toggle()">
    <x-icon name="moon" class="theme-moon size-[18px]"/>
    <x-icon name="sun" class="theme-sun size-[18px]"/>
</button>
