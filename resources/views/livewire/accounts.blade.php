<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('ADMINISTRATION') }}</p><h1 class="page-title mt-2">{{ __('Utilisateurs') }}</h1><p class="muted mt-2">{{ __('Gérez les comptes, les rôles et les accès à l’inventaire.') }}</p></div>
        <x-button icon="plus" wire:click="open">{{ __('Ajouter un compte interne') }}</x-button>
    </div>
    <section class="card">
        @if ($accounts->isEmpty())
            <x-empty-state icon="accounts" :title="__('Aucun compte')" :text="__('Les comptes LDAP et OpenID Connect apparaissent après leur première connexion.')"/>
        @else
            <div class="table-scroll"><table>
                <thead><tr><th>{{ __('Compte') }}</th><th>{{ __('Rôle') }}</th><th>{{ __('Connexion') }}</th><th>{{ __('Accès') }}</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
                <tbody>
                    @foreach ($accounts as $account)
                        <tr wire:key="account-{{ $account->id }}">
                            <td><span class="font-semibold">{{ $account->name }}</span><small class="muted block">{{ $account->email }}</small></td>
                            <td>{{ __(\App\Livewire\Accounts::ROLES[$account->role]) }}</td>
                            <td>{{ ['local' => __('Interne'), 'ldap' => 'LDAP', 'oidc' => 'OpenID Connect'][$account->provider] }}</td>
                            <td><span @class(['badge', 'tone-cyan' => $account->active, 'tone-amber' => ! $account->active])>{{ $account->active ? __('Actif') : __('Désactivé') }}</span></td>
                            <td class="text-right">
                                @if ($account->email !== \App\Console\Commands\InstallAdmin::EMAIL || $account->id === auth()->id())
                                <button type="button" class="icon-button" aria-label="{{ __('Modifier :name', ['name' => $account->name]) }}" wire:click="open({{ $account->id }})"><x-icon name="edit" class="size-4"/></button>
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
</div>
