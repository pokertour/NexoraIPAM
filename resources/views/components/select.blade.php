@props(['name', 'id' => null])
@php
    $model = $attributes->wire('model');
    $input = $attributes->whereDoesntStartWith('wire:model')->except('class');
    $key = hash('sha256', $slot->toHtml().'|'.$input->toHtml());
@endphp
<div {{ $attributes->only('class')->class('select-control') }} wire:key="select-{{ $name }}-{{ $key }}" wire:ignore x-data="nexoraSelect"
    @if ($model->value()) data-model="{{ $model->value() }}" @if ($model->hasModifier('live')) data-live @endif @endif>
    <select id="{{ $id ?? str_replace('.', '-', $name) }}" name="{{ $name }}" {{ $input->class('input') }}>{{ $slot }}</select>
</div>
