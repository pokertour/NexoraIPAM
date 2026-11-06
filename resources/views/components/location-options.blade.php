@props(['locations', 'selected' => null, 'placeholder' => 'Sans emplacement'])
<option value="">{{ $placeholder }}</option>
@foreach ($locations as $location)
    <option value="{{ $location->id }}" @selected((string) $selected === (string) $location->id)>{{ $location->path() }}</option>
@endforeach
