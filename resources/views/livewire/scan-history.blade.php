<div @if ($pending) wire:poll.5s @endif>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('SURVEILLANCE') }}</p><h1 class="page-title mt-2">{{ __('Historique des scans') }}</h1><p class="muted mt-2">{{ __('Découvertes ICMP, interrogations SNMP et imports DHCP, manuels ou planifiés, avec leur résultat.') }}</p></div>
    </div>
    <section class="card">
        <div class="table-toolbar">
            <label class="search">
                <x-icon name="search" class="size-4"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Rechercher…') }}" maxlength="100" aria-label="{{ __('Rechercher un scan') }}">
            </label>
            <nav class="segmented" aria-label="{{ __('Type de scan') }}">
                <button type="button" @class(['active' => $kind === '']) wire:click="$set('kind', '')" aria-current="{{ $kind === '' ? 'true' : 'false' }}">
                    {{ __('Tous') }} <span class="count-pill">{{ $counts->sum() }}</span>
                </button>
                @foreach (\App\Models\ScanRun::KINDS as $value => $label)
                    <button type="button" @class(['active' => $kind === $value]) wire:click="$set('kind', '{{ $value }}')" aria-current="{{ $kind === $value ? 'true' : 'false' }}">
                        {{ __($label) }} <span class="count-pill">{{ $counts[$value] ?? 0 }}</span>
                    </button>
                @endforeach
            </nav>
            <x-select class="location-filter" name="status" wire:model.live="status" aria-label="{{ __('Filtrer par statut') }}">
                <option value="">{{ __('Tous les statuts') }}</option>
                @foreach (\App\Models\ScanRun::STATUSES as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
            </x-select>
        </div>
        @if ($runs->isEmpty())
            <x-empty-state icon="history" :title="__('Aucun scan')" :text="__('Les découvertes, interrogations SNMP et imports DHCP apparaîtront ici dès leur mise en file d’attente.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Cible') }}</th><th>{{ __('Déclenchement') }}</th><th>{{ __('Statut') }}</th><th>{{ __('Durée') }}</th><th>{{ __('Résultat') }}</th></tr></thead>
                <tbody>
                    @foreach ($runs as $run)
                        <tr wire:key="scan-{{ $run->id }}">
                            <td class="whitespace-nowrap"><time datetime="{{ $run->created_at->toIso8601String() }}" title="{{ $run->created_at->isoFormat('LLL') }}">{{ $run->created_at->diffForHumans() }}</time></td>
                            <td><span @class(['badge', 'tone-cyan' => $run->kind === 'discovery', 'tone-violet' => $run->kind === 'snmp', 'tone-blue' => $run->kind === 'dhcp'])>{{ __(\App\Models\ScanRun::KINDS[$run->kind] ?? $run->kind) }}</span></td>
                            <td>
                                @if ($url = $run->subjectUrl())
                                    <a href="{{ $url }}" wire:navigate class="scan-target">{{ $run->subject_label }}</a>
                                @else
                                    <span class="font-semibold">{{ $run->subject_label ?? '—' }}</span>
                                @endif
                            </td>
                            <td>
                                {{ __(\App\Models\ScanRun::TRIGGERS[$run->trigger] ?? $run->trigger) }}
                                @if ($run->user)<small class="muted block">{{ $run->user->name }}</small>@endif
                            </td>
                            <td><span @class([
                                'badge',
                                'tone-cyan' => $run->status === 'completed',
                                'tone-amber' => $run->status === 'failed',
                                'tone-blue' => in_array($run->status, ['queued', 'running'], true),
                            ])>{{ __(\App\Models\ScanRun::STATUSES[$run->status] ?? $run->status) }}</span></td>
                            <td class="whitespace-nowrap">
                                @if (($duration = $run->durationInSeconds()) !== null)
                                    {{ match (true) {
                                        $duration < 60 => __(':seconds s', ['seconds' => $duration]),
                                        $duration % 60 === 0 => __(':minutes min', ['minutes' => intdiv($duration, 60)]),
                                        default => __(':minutes min :seconds s', ['minutes' => intdiv($duration, 60), 'seconds' => $duration % 60]),
                                    } }}
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($run->error)
                                    <span class="scan-error">{{ $run->error }}</span>
                                @elseif ($run->summary)
                                    <dl class="scan-summary">
                                        @foreach ($run->summary as $key => $value)
                                            <div><dt>{{ __(\App\Models\ScanRun::SUMMARY_LABELS[$key] ?? $key) }}</dt><dd>{{ $value }}</dd></div>
                                        @endforeach
                                    </dl>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if ($runs->hasPages())<div class="pagination">{{ $runs->links() }}</div>@endif
        @endif
    </section>
</div>
