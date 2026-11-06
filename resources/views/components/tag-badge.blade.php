@props(['tag'])
<span {{ $attributes->class(['tag-badge']) }} data-color="{{ $tag->color }}" @if ($tag->description) title="{{ $tag->description }}" @endif>{{ $tag->name }}</span>
