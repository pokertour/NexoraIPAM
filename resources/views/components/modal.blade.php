@props(['id', 'title', 'description' => null, 'automatic' => true])
<dialog id="{{ $id }}" class="dialog" aria-labelledby="{{ $id }}-title"
    @if ($automatic) wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.close()" @click.self="$wire.close()" @endif>
    <div class="dialog-content">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="{{ $id }}-title" class="text-xl font-semibold">{{ $title }}</h2>
                @if ($description)<p class="muted mt-1 text-sm">{{ $description }}</p>@endif
            </div>
            @if ($automatic)
                <button type="button" class="icon-button" aria-label="Fermer" wire:click="close"><x-icon name="close"/></button>
            @else
                <form method="dialog">
                    <button type="submit" class="icon-button" aria-label="Fermer"><x-icon name="close"/></button>
                </form>
            @endif
        </div>
        {{ $slot }}
    </div>
</dialog>
