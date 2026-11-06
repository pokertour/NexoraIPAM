<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('ADMINISTRATION') }}</p><h1 class="page-title mt-2">{{ __('Journal d’audit') }}</h1><p class="muted mt-2">{{ __('Toutes les modifications de l’inventaire, des comptes et des paramètres, avec leur auteur.') }}</p></div>
    </div>
    <section class="card">
        <div class="table-toolbar">
            <label class="search">
                <x-icon name="search" class="size-4"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Rechercher…') }}" maxlength="100" aria-label="{{ __('Rechercher dans le journal') }}">
            </label>
            <x-select class="location-filter" name="action" wire:model.live="action" aria-label="{{ __('Filtrer par action') }}">
                <option value="">{{ __('Toutes les actions') }}</option>
                @foreach (\App\Models\AuditLog::ACTIONS as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
            </x-select>
            <x-select class="location-filter" name="subject" wire:model.live="subject" aria-label="{{ __('Filtrer par type d’objet') }}">
                <option value="">{{ __('Tous les objets') }}</option>
                @foreach (\App\Models\AuditLog::SUBJECTS as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
            </x-select>
            <x-select class="location-filter" name="user" wire:model.live="user" aria-label="{{ __('Filtrer par auteur') }}">
                <option value="">{{ __('Tous les auteurs') }}</option>
                <option value="system">{{ __('Système') }}</option>
                @foreach ($users as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </x-select>
            <span class="count-pill ml-auto">{{ $entries->total() }} {{ $entries->total() === 1 ? __('entrée') : __('entrées') }}</span>
        </div>
        @if ($entries->isEmpty())
            <x-empty-state icon="audit" :title="__('Aucun événement')" :text="__('Les actions enregistrées apparaîtront ici.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Auteur') }}</th><th>{{ __('Action') }}</th><th>{{ __('Objet') }}</th><th>{{ __('Détails') }}</th></tr></thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr wire:key="audit-{{ $entry->id }}">
                            <td class="whitespace-nowrap tabular-nums">{{ $entry->created_at->format(__('d/m/Y H:i')) }}<small class="muted block">{{ $entry->created_at->format('s') }}s</small></td>
                            <td>{{ $entry->actor ?? __('Système') }}@if ($entry->ip_address)<small class="muted block font-mono">{{ $entry->ip_address }}</small>@endif</td>
                            <td><x-audit-action :action="$entry->action"/></td>
                            <td>
                                @if ($entry->subject_type)<small class="muted block">{{ __(\App\Models\AuditLog::SUBJECTS[$entry->subject_type] ?? $entry->subject_type) }}</small>@endif
                                <span class="font-semibold">{{ $entry->subject_label ?? '—' }}</span>
                            </td>
                            <td class="min-w-64"><x-audit-changes :changes="$entry->changes"/></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if ($entries->hasPages())<div class="pagination">{{ $entries->links() }}</div>@endif
        @endif
    </section>
</div>
