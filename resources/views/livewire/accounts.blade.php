<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('ADMINISTRATION') }}</p><h1 class="page-title mt-2">{{ __('Utilisateurs') }}</h1><p class="muted mt-2">{{ __('Gérez les comptes, les rôles et les accès à l’inventaire.') }}</p></div>
        <x-button icon="plus" wire:click="open">{{ __('Ajouter un compte interne') }}</x-button>
    </div>
    <section class="card">
        <div class="table-toolbar">
            <label class="search">
                <x-icon name="search" class="size-4"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Rechercher…') }}" maxlength="100" aria-label="{{ __('Rechercher un utilisateur') }}">
            </label>
            <nav class="segmented" aria-label="{{ __('Source du compte') }}">
                <button type="button" @class(['active' => $source === '']) wire:click="$set('source', '')" aria-current="{{ $source === '' ? 'true' : 'false' }}">
                    {{ __('Tous') }} <span class="count-pill">{{ $counts->sum() }}</span>
                </button>
                @foreach (\App\Livewire\Accounts::PROVIDERS as $value => $label)
                    <button type="button" @class(['active' => $source === $value]) wire:click="$set('source', '{{ $value }}')" aria-current="{{ $source === $value ? 'true' : 'false' }}">
                        {{ __($label) }} <span class="count-pill">{{ $counts[$value] ?? 0 }}</span>
                    </button>
                @endforeach
            </nav>
        </div>
        @if ($accounts->isEmpty())
            <x-empty-state icon="accounts" :title="__('Aucun compte')" :text="__('Les comptes LDAP et OpenID Connect apparaissent après leur première connexion.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>{{ __('Compte') }}</th><th>{{ __('Rôle') }}</th><th>{{ __('Connexion') }}</th><th>{{ __('Dernière connexion') }}</th><th>{{ __('Accès') }}</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
                <tbody>
                    @foreach ($accounts as $account)
                        <tr wire:key="account-{{ $account->id }}">
                            <td><span class="font-semibold">{{ $account->name }}</span><small class="muted block">{{ $account->email }}</small></td>
                            <td>{{ __(\App\Livewire\Accounts::ROLES[$account->role]) }}</td>
                            <td><span @class(['badge', 'tone-blue' => $account->provider === 'ldap', 'tone-violet' => $account->provider === 'oidc'])>{{ __(\App\Livewire\Accounts::PROVIDERS[$account->provider] ?? $account->provider) }}</span></td>
                            <td>@if ($account->last_login_at)<time datetime="{{ \Illuminate\Support\Carbon::parse($account->last_login_at)->toIso8601String() }}" title="{{ \Illuminate\Support\Carbon::parse($account->last_login_at)->isoFormat('LLL') }}">{{ \Illuminate\Support\Carbon::parse($account->last_login_at)->diffForHumans() }}</time>@else<span class="muted">{{ __('Jamais') }}</span>@endif</td>
                            <td><span @class(['badge', 'tone-cyan' => $account->active, 'tone-amber' => ! $account->active])>{{ $account->active ? __('Actif') : __('Désactivé') }}</span></td>
                            <td class="text-right">
                                @if (! \App\Console\Commands\InstallAdmin::isInstallAccount($account) || $account->id === auth()->id())
                                <button type="button" class="icon-button" aria-label="{{ __('Modifier :name', ['name' => $account->name]) }}" wire:click="open({{ $account->id }})"><x-icon name="edit" class="size-4"/></button>
                                @endif
                                @if (\App\Livewire\Accounts::deletable($account))
                                <button type="button" class="icon-button danger-text" aria-label="{{ __('Supprimer :name', ['name' => $account->name]) }}" wire:click="confirmDelete({{ $account->id }})"><x-icon name="trash" class="size-4"/></button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if ($accounts->hasPages())<div class="pagination">{{ $accounts->links() }}</div>@endif
        @endif
    </section>

    @if ($editing)
        <x-modal id="account-edit" :title="__($recordId ? 'Modifier le compte' : 'Ajouter un compte interne')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <x-field name="form.name" :label="__('Nom')" wire:model="form.name" maxlength="100" required/>
                <x-field name="form.email" :label="__('Adresse e-mail')" type="email" wire:model="form.email" required/>
                <x-field name="form.role" :label="__('Rôle')" wire:model="form.role">
                    @foreach (\App\Livewire\Accounts::ROLES as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                </x-field>
                @if ($provider === 'local' && $recordId !== auth()->id())
                    <x-field name="form.password" :label="__('Mot de passe temporaire')" type="password" wire:model="form.password" autocomplete="new-password" :required="! $recordId" :hint="__('Au moins 12 caractères. L’utilisateur le changera à sa prochaine connexion.')"/>
                @endif
                <label class="checkbox"><input type="checkbox" wire:model="form.active"> {{ __('Autoriser la connexion') }}</label>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($deletingId)
        @php($target = \App\Models\User::find($deletingId))
        <x-modal id="account-delete" :title="__('Supprimer le compte :name ?', ['name' => $target?->name])" :description="$target?->provider === 'local' ? __('Le compte et ses jetons d’API sont supprimés. Ses entrées du journal d’audit sont conservées.') : __('Le compte et ses jetons d’API sont supprimés. Il sera recréé avec le rôle Lecteur à sa prochaine connexion si l’annuaire ou le fournisseur l’autorise encore.')">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="cancelDelete">{{ __('Annuler') }}</x-button>
                <x-button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">{{ __('Supprimer') }}</x-button>
            </div>
        </x-modal>
    @endif
</div>
