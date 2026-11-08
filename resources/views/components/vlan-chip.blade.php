@props(['number', 'color' => null, 'name' => null])
<span {{ $attributes->class('vlan-chip') }} style="--vlan: {{ $color ?? \App\Support\VlanColor::for((int) $number) }}" @if ($name) title="{{ $name }}" @endif>{{ $number }}@if ($name)<span class="vlan-chip-name">{{ $name }}</span>@endif</span>
