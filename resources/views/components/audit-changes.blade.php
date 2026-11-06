@props(['changes'])
@if ($changes)
    <dl class="audit-changes">
        @foreach ($changes as $field => $change)
            @php([$old, $new] = is_array($change) && array_is_list($change) && count($change) === 2 ? $change : [null, $change])
            <div>
                <dt><code>{{ $field }}</code></dt>
                <dd>
                    @if ($old !== null && $old !== '')<del>{{ is_scalar($old) ? (is_bool($old) ? ($old ? 'true' : 'false') : $old) : json_encode($old, JSON_UNESCAPED_UNICODE) }}</del><span class="audit-arrow" aria-hidden="true">→</span>@endif
                    <ins>{{ $new === null || $new === '' ? '∅' : (is_scalar($new) ? (is_bool($new) ? ($new ? 'true' : 'false') : $new) : json_encode($new, JSON_UNESCAPED_UNICODE)) }}</ins>
                </dd>
            </div>
        @endforeach
    </dl>
@endif
