@props(['name', 'label', 'type' => 'text', 'hint' => null, 'id' => null])
@php($id ??= str_replace('.', '-', $name))
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    @if ($slot->isNotEmpty())
        <x-select :name="$name" :id="$id" {{ $attributes }}>{{ $slot }}</x-select>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" {{ $attributes->class('input') }}>
    @endif
    @if ($hint)<small>{{ $hint }}</small>@endif
    @error($name)<small class="field-error" role="alert">{{ $message }}</small>@enderror
</div>
