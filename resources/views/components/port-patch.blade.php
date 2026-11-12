@props(['outlet' => null, 'destination' => null])
@if ($outlet)
    <span class="outlet-name"><x-icon name="outlet" class="size-3.5"/> {{ $outlet->name }}</span>
@elseif ($destination)
    <span class="port-destination-name" title="{{ __('Autre destination') }}"><x-icon name="link" class="size-3.5"/> {{ $destination }}</span>
@else
    —
@endif
