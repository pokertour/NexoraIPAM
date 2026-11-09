@props(['device', 'short' => false])
@if (($device->role ?? 'other') !== 'other')
    @php($role = \App\Models\NetworkDevice::ROLES[$device->role] ?? ['label' => $device->role, 'short' => $device->role])
    <span {{ $attributes->class('role-badge') }} data-role="{{ $device->role }}" title="{{ __($role['label']) }}">{{ __($short ? $role['short'] : $role['label']) }}</span>
@endif
