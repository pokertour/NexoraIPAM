@props(['variant' => 'primary', 'type' => 'button', 'icon' => null])
<button type="{{ $type }}" {{ $attributes->class(['button', 'button-'.$variant]) }}>
    @if ($icon)<x-icon :name="$icon" class="size-4"/>@endif
    {{ $slot }}
</button>
