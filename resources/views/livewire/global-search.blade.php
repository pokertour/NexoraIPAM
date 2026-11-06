<div x-data="{
        active: -1,
        open() { const dialog = document.getElementById('global-search'); if (!dialog.open) dialog.showModal(); this.$nextTick(() => this.$refs.input.focus()); },
        items() { return [...this.$root.querySelectorAll('[data-search-result]')]; },
        move(step) { const items = this.items(); if (!items.length) return; this.active = (this.active + step + items.length) % items.length; items[this.active].scrollIntoView({ block: 'nearest' }); },
        go() { const item = this.items()[Math.max(this.active, 0)]; if (item) item.click(); },
    }"
    x-on:keydown.window.prevent.ctrl.k="open()" x-on:keydown.window.prevent.meta.k="open()"
    x-on:keydown.window.slash="if (!['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) && !$event.target.isContentEditable) { $event.preventDefault(); open(); }">
    <button type="button" class="search-trigger" x-on:click="open()" aria-haspopup="dialog" aria-controls="global-search">
        <x-icon name="search" class="size-4"/>
        <span class="hidden md:inline">{{ __('Rechercher…') }}</span>
        <kbd class="hidden md:inline-flex">Ctrl K</kbd>
    </button>

    <dialog id="global-search" class="dialog dialog-search" aria-label="{{ __('Recherche globale') }}" wire:ignore.self
        x-on:click.self="$el.close()" x-on:close="$wire.set('query', ''); active = -1">
        <div class="search-box">
            <x-icon name="search" class="size-5"/>
            <input x-ref="input" type="search" wire:model.live.debounce.200ms="query" maxlength="100" autocomplete="off" spellcheck="false"
                placeholder="{{ __('IP, sous-réseau, nom d’hôte, MAC, VLAN, équipement…') }}" aria-label="{{ __('Rechercher dans NexoraIPAM') }}"
                x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)" x-on:keydown.enter.prevent="go()" x-on:input="active = -1">
            <span wire:loading wire:target="query" class="scan-dot text-[var(--highlight)]" aria-hidden="true"></span>
            <kbd>Esc</kbd>
        </div>
        <div class="search-results" role="listbox" aria-label="{{ __('Résultats') }}">
            @if (mb_strlen(trim($query)) < 2)
                <p class="search-hint">{{ __('Saisissez au moins deux caractères. Une adresse IP affiche aussi les sous-réseaux qui la contiennent, une MAC le port de switch où elle est branchée.') }}</p>
            @elseif ($groups === [])
                <p class="search-hint">{{ __('Aucun résultat pour « :query ».', ['query' => trim($query)]) }}</p>
            @else
                @foreach ($groups as $group => $items)
                    <section wire:key="search-group-{{ $loop->index }}">
                        <h3>{{ __($group) }}</h3>
                        @foreach ($items as $item)
                            <a href="{{ $item['url'] }}" wire:navigate data-search-result role="option" x-on:click="document.getElementById('global-search').close()"
                                :class="{ 'active': items().indexOf($el) === active }" :aria-selected="items().indexOf($el) === active">
                                <span class="stat-icon tone-violet"><x-icon :name="$item['icon']" class="size-4"/></span>
                                <span class="min-w-0"><span class="block truncate font-semibold">{{ $item['label'] }}</span>@if ($item['detail'])<small class="muted block truncate">{{ $item['detail'] }}</small>@endif</span>
                                <x-icon name="arrow" class="search-arrow size-4"/>
                            </a>
                        @endforeach
                    </section>
                @endforeach
            @endif
        </div>
    </dialog>
</div>
