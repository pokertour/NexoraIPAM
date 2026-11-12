@props(['id', 'title', 'description' => null, 'automatic' => true, 'wide' => false])
<dialog id="{{ $id }}" @class(["dialog", "dialog-wide" => $wide]) aria-labelledby="{{ $id }}-title"
    @if ($automatic) wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.close()" @click.self="$wire.close()" @endif>
    <div class="dialog-content">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="{{ $id }}-title" class="text-xl font-semibold">{{ $title }}</h2>
                @if ($description)<p class="muted mt-1 text-sm">{{ $description }}</p>@endif
            </div>
            @if ($automatic)
                <button type="button" class="icon-button" aria-label="{{ __('Fermer') }}" wire:click="close"><x-icon name="close"/></button>
            @else
                <form method="dialog">
                    <button type="submit" class="icon-button" aria-label="{{ __('Fermer') }}"><x-icon name="close"/></button>
                </form>
            @endif
        </div>
        {{ $slot }}
    </div>
    {{-- Open select lists live here, out of the content Livewire refreshes. --}}
    <div class="select-layer" data-select-layer wire:ignore></div>
</dialog>
