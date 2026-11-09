@props(['device'])
@if (($device->role ?? 'other') !== 'other')
    <span {{ $attributes->class('role-badge') }} data-role="{{ $device->role }}">{{ __(\App\Models\NetworkDevice::ROLES[$device->role]['label'] ?? $device->role) }}</span>
@endif
