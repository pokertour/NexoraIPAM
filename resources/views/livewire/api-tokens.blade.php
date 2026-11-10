<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('ADMINISTRATION') }}</p><h1 class="page-title mt-2">{{ __('Jetons d’API') }}</h1><p class="muted mt-2">{{ __('Créez les jetons des comptes autorisés à utiliser l’API, révoquez-les et suivez ce que chacun a modifié.') }}</p></div>
        <x-button icon="key" wire:click="open">{{ __('Créer un jeton') }}</x-button>
    </div>
    <x-page-tabs current="api-tokens"/>

    @if ($plainToken)
        <section class="card settings-card mb-4" data-profile-secret wire:key="plain-token">
            <div class="token-reveal" x-data="{ copied: false }">
                <p class="text-sm font-semibold">{{ __('Copiez ce jeton maintenant et transmettez-le à son utilisateur : il ne sera plus affiché.') }}</p>
                <div class="token-value">
                    <code class="select-all" x-ref="token">{{ $plainToken }}</code>
                    <button type="button" class="icon-button" x-on:click="navigator.clipboard?.writeText($refs.token.textContent); copied = true" :aria-label="copied ? @js(__('Copié')) : @js(__('Copier le jeton'))"><x-icon name="copy" class="size-4" x-show="! copied"/><x-icon name="check" class="size-4" x-show="copied" x-cloak/></button>
                </div>
                <pre class="token-example"><code>curl -H "Authorization: Bearer {{ $plainToken }}" {{ url('/api/v1/me') }}</code></pre>
            </div>
            <x-button variant="secondary" class="justify-self-start" wire:click="close">{{ __('J’ai copié le jeton') }}</x-button>
        </section>
    @endif

    <section class="card mb-4">
        @if ($tokens->isEmpty())
            <x-empty-state icon="key" :title="__('Aucun jeton')" :text="__('Créez un jeton pour qu’un script ou un outil d’automatisation accède à l’API.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>{{ __('Jeton') }}</th><th>{{ __('Compte') }}</th><th>{{ __('Droits') }}</th><th>{{ __('Dernière utilisation') }}</th><th>{{ __('Expiration') }}</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
                <tbody>
                    @foreach ($tokens as $item)
                        @php($owner = $item->tokenable)
                        @php($writes = in_array('write', $item->abilities, true))
                        <tr wire:key="token-{{ $item->id }}">
                            <td><span class="font-semibold">{{ $item->name }}</span><small class="muted block">{{ __('créé :date', ['date' => $item->created_at->format(__('d/m/Y'))]) }}</small></td>
                            <td>{{ $owner?->name ?? '—' }}<small class="muted block">{{ $owner ? __($roles[$owner->role] ?? $owner->role) : '' }}@if ($owner && ! $owner->active) · {{ __('Désactivé') }}@endif</small></td>
                            <td><span @class(['badge', 'tone-amber' => $writes, 'tone-blue' => ! $writes])>{{ $writes ? __('Lecture et écriture') : __('Lecture seule') }}</span></td>
                            <td class="whitespace-nowrap">{{ $item->last_used_at?->diffForHumans() ?? __('jamais utilisé') }}</td>
                            <td class="whitespace-nowrap">
                                @if (! $item->expires_at) <span class="muted">{{ __('Jamais') }}</span>
                                @elseif ($item->expires_at->isPast()) <span class="badge tone-amber">{{ __('expiré') }}</span>
                                @else {{ $item->expires_at->format(__('d/m/Y')) }}@endif
                            </td>
                            <td><div class="flex justify-end gap-1">
                                <button class="icon-button" type="button" title="{{ __('Historique') }}" aria-label="{{ __('Historique de :name', ['name' => $item->name]) }}" wire:click="$set('token', '{{ $item->id }}')"><x-icon name="history" class="size-4"/></button>
                                <button class="icon-button danger-text" type="button" title="{{ __('Révoquer') }}" aria-label="{{ __('Révoquer :name', ['name' => $item->name]) }}" wire:click="confirmRevoke({{ $item->id }})"><x-icon name="trash" class="size-4"/></button>
                            </div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    <section class="card">
        <div class="section-heading">
            <div><h2>{{ __('Historique') }}</h2><p>{{ __('Création et révocation des jetons, et modifications faites avec chacun d’eux.') }}</p></div>
            @if ($tokens->isNotEmpty())
                <x-select class="location-filter" name="token" wire:model.live="token" aria-label="{{ __('Filtrer par jeton') }}">
                    <option value="">{{ __('Tous les jetons') }}</option>
                    @foreach ($tokens as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->tokenable?->name }}</option>@endforeach
                </x-select>
            @endif
        </div>
        @if ($history->isEmpty())
            <x-empty-state icon="audit" :title="__('Aucun événement')" :text="__('Les actions faites avec les jetons apparaîtront ici.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Auteur') }}</th><th>{{ __('Action') }}</th><th>{{ __('Objet') }}</th><th>{{ __('Détails') }}</th></tr></thead>
                <tbody>
                    @foreach ($history as $entry)
                        <tr wire:key="history-{{ $entry->id }}">
                            <td class="whitespace-nowrap tabular-nums">{{ $entry->created_at->format(__('d/m/Y H:i')) }}</td>
                            <td class="whitespace-nowrap">{{ $entry->actor ?? __('Système') }}@if ($entry->api_token_name)<small class="token-chip"><x-icon name="key" class="size-3"/>{{ $entry->api_token_name }}</small>@endif</td>
                            <td><x-audit-action :action="$entry->action"/></td>
                            <td>@if ($entry->subject_type)<small class="muted block">{{ __(\App\Models\AuditLog::SUBJECTS[$entry->subject_type] ?? $entry->subject_type) }}</small>@endif<span class="font-semibold">{{ $entry->subject_label ?? '—' }}</span></td>
                            <td class="min-w-64"><x-audit-changes :changes="$entry->changes"/></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if ($history->hasPages())<div class="pagination">{{ $history->links() }}</div>@endif
        @endif
    </section>

    @if ($creating)
        <x-modal id="token-create" :title="__('Créer un jeton')" :description="__('Le jeton agit avec les droits du compte choisi. Pour un outil d’automatisation, créez de préférence un compte dédié.')">
            <form wire:submit="create" class="mt-6 grid gap-4">
                <x-field name="form.user_id" :label="__('Compte')" wire:model="form.user_id" required>
                    <option value="">{{ __('Choisir un compte') }}</option>
                    @foreach ($users as $option)<option value="{{ $option->id }}">{{ $option->name }} · {{ __($roles[$option->role] ?? $option->role) }}</option>@endforeach
                </x-field>
                <div class="grid gap-4 sm:grid-cols-[1fr_9rem]">
                    <x-field name="form.name" :label="__('Nom du jeton')" wire:model="form.name" :placeholder="__('Ansible production')" maxlength="100" required/>
                    <x-field name="form.days" :label="__('Expire dans (jours)')" type="number" min="1" max="3650" wire:model="form.days" :placeholder="__('Jamais')"/>
                </div>
                <label class="checkbox"><input type="checkbox" wire:model="form.write"> {{ __('Autoriser l’écriture (si le rôle du compte le permet)') }}</label>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="create">{{ __('Créer le jeton') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($revoking)
        <x-modal id="token-revoke" :title="__('Révoquer ce jeton ?')" :description="__('Les outils qui l’utilisent perdront l’accès à l’API immédiatement.')">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                <x-button variant="danger" wire:click="revoke" wire:loading.attr="disabled" wire:target="revoke">{{ __('Révoquer') }}</x-button>
            </div>
        </x-modal>
    @endif
</div>
