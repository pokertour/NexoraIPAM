@props(['value', 'label', 'tone' => 'violet'])
<meter {{ $attributes->class(['usage-meter', 'meter-'.$tone]) }} min="0" max="100" value="{{ $value }}" aria-label="{{ $label }}">{{ $value }} %</meter>
