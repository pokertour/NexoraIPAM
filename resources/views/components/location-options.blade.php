@props(['locations', 'selected' => null, 'placeholder' => __('Sans emplacement')])
<option value="">{{ $placeholder }}</option>
@foreach ($locations as $location)
    <option value="{{ $location->id }}" @selected((string) $selected === (string) $location->id)>{{ $location->path() }}</option>
@endforeach
