@props(['record', 'fields'])
@php($values = collect($fields)->filter(fn ($field) => filled($record->custom_values[$field->key] ?? null)))
@if ($record->tags->isNotEmpty())
    <div class="tag-list">@foreach ($record->tags as $tag)<x-tag-badge :tag="$tag"/>@endforeach</div>
@endif
@if ($values->isNotEmpty())
    <div class="meta-list">
        @foreach ($values as $field)
            @php($value = $record->custom_values[$field->key])
            <span>{{ $field->label }} · @if ($field->type === 'url')<a class="table-link" href="{{ $value }}" rel="noopener noreferrer" target="_blank"><b>{{ \Illuminate\Support\Str::limit(preg_replace('~^https?://~', '', $value), 32) }}</b></a>@else<b>{{ $value }}</b>@endif</span>
        @endforeach
    </div>
@endif
