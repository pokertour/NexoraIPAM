<div>
    @if ($polling && ! $editing && ! $deleting)
        <span wire:key="device-poll" wire:poll.4s hidden></span>
    @endif
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('INFRASTRUCTURE') }}</p><h1 class="page-title mt-2">{{ __('Équipements') }}</h1><p class="muted mt-2">{{ __('Switchs et routeurs interrogés en SNMP : ports, VLAN, adresses MAC, débit et voisins LLDP.') }}</p></div>
        @can('administer')<x-button icon="plus" wire:click="open">{{ __('Ajouter') }}</x-button>@endcan
    </div>

    <div class="table-toolbar card mb-4">
        <label class="search">
            <x-icon name="search" class="size-4"/>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Rechercher…') }}" maxlength="100" aria-label="{{ __('Rechercher un équipement') }}">
        </label>
        <span class="count-pill ml-auto">{{ $devices->count() }} {{ $devices->count() === 1 ? __('entrée') : __('entrées') }}</span>
    </div>

    @if ($devices->isEmpty())
        <section class="card"><x-empty-state icon="switch" :title="__('Aucun équipement')" :text="__('Ajoutez un switch ou un routeur avec ses identifiants SNMP pour afficher ses ports.')"/></section>
    @else
        <div class="device-grid">
            @foreach ($devices as $device)
                @php($ports = $device->ports->sortBy('if_index', SORT_NATURAL))
                <article class="card device-card" wire:key="device-{{ $device->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('devices.show', $device) }}" wire:navigate class="flex min-w-0 items-center gap-3">
                            <span class="stat-icon tone-blue"><x-icon name="switch" class="size-[18px]"/></span>
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{ $device->name }}</span>
                                <code class="block truncate">{{ $device->host }}</code>
                            </span>
                        </a>
                        <x-device-status :device="$device"/>
                    </div>
                    @if ($ports->isNotEmpty())
                        <div class="led-strip" aria-label="{{ __(':up ports actifs sur :total', ['up' => $ports->where('oper_status', 1)->count(), 'total' => $ports->count()]) }}">
                            @foreach ($ports->take(52) as $port)<i data-state="{{ $port->state() }}"></i>@endforeach
                        </div>
                    @endif
                    <dl class="device-facts">
                        <div><dt>{{ __('Ports actifs') }}</dt><dd class="tabular-nums">{{ $ports->where('oper_status', 1)->count() }} / {{ $ports->count() }}</dd></div>
                        <div><dt>{{ __('Emplacement') }}</dt><dd class="truncate">{{ $device->location?->path() ?? '—' }}</dd></div>
                        <div><dt>{{ __('Dernière interrogation') }}</dt><dd>{{ $device->polled_at?->diffForHumans() ?? '—' }}</dd></div>
                    </dl>
                    @if ($device->sys_descr)<p class="muted truncate text-xs" title="{{ $device->sys_descr }}">{{ \Illuminate\Support\Str::before($device->sys_descr, "\n") }}</p>@endif
                    @if ($device->poll_error && $device->poll_status === 'failed')<p class="field-error text-xs">{{ $device->poll_error }}</p>@endif
                    <div class="device-actions">
                        <a class="button button-secondary" href="{{ route('devices.show', $device) }}" wire:navigate>{{ __('Voir la façade') }} <x-icon name="arrow" class="size-4"/></a>
                        @can('administer')
                            <div class="ml-auto flex gap-1">
                                <button class="icon-button" type="button" title="{{ __('Interroger maintenant') }}" aria-label="{{ __('Interroger :name maintenant', ['name' => $device->name]) }}" wire:click="poll({{ $device->id }})" @disabled($device->pollPending())><x-icon name="radar" class="size-4"/></button>
                                <button class="icon-button" type="button" title="{{ __('Modifier') }}" aria-label="{{ __('Modifier :name', ['name' => $device->name]) }}" wire:click="open({{ $device->id }})"><x-icon name="edit" class="size-4"/></button>
                                <button class="icon-button danger-text" type="button" title="{{ __('Supprimer') }}" aria-label="{{ __('Supprimer :name', ['name' => $device->name]) }}" wire:click="confirmDelete({{ $device->id }})"><x-icon name="trash" class="size-4"/></button>
                            </div>
                        @endcan
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if ($editing)
        <x-modal id="device-edit" wide :title="__($recordId ? 'Modifier l’équipement' : 'Ajouter un équipement')" :description="__('Le worker de la file discovery interroge l’équipement depuis son propre réseau.')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="form.name" :label="__('Nom')" wire:model="form.name" maxlength="100" required/>
                    <x-field name="form.location_id" :label="__('Emplacement')" wire:model="form.location_id">
                        <x-location-options :locations="$locations"/>
                    </x-field>
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                    <x-field name="form.host" :label="__('Adresse IP ou nom d’hôte')" wire:model="form.host" placeholder="192.0.2.2" maxlength="253" required/>
                    <x-field name="form.port" :label="__('Port UDP')" type="number" min="1" max="65535" wire:model="form.port" required/>
                </div>
                <fieldset class="form-section">
                    <legend>SNMP</legend>
                    <x-field name="form.snmp_version" label="Version" wire:model.live="form.snmp_version">
                        @foreach (\App\Models\NetworkDevice::VERSIONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </x-field>
                    @if (($form['snmp_version'] ?? '2c') === '3')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field name="form.username" :label="__('Utilisateur')" wire:model="form.username" maxlength="255" autocomplete="off"/>
                            <x-field name="form.security_level" :label="__('Niveau de sécurité')" wire:model.live="form.security_level">
                                @foreach (\App\Models\NetworkDevice::SECURITY_LEVELS as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                            </x-field>
                        </div>
                        @if (($form['security_level'] ?? 'authPriv') !== 'noAuthNoPriv')
                            <div class="grid gap-4 sm:grid-cols-[10rem_1fr]">
                                <x-field name="form.auth_protocol" :label="__('Authentification')" wire:model="form.auth_protocol">
                                    @foreach (\App\Models\NetworkDevice::AUTH_PROTOCOLS as $protocol)<option value="{{ $protocol }}">{{ $protocol }}</option>@endforeach
                                </x-field>
                                <x-field name="form.auth_password" :label="__('Mot de passe d’authentification')" type="password" wire:model="form.auth_password" autocomplete="new-password" :hint="$recordId ? __('Laissez vide pour conserver le secret enregistré.') : null"/>
                            </div>
                        @endif
                        @if (($form['security_level'] ?? 'authPriv') === 'authPriv')
                            <div class="grid gap-4 sm:grid-cols-[10rem_1fr]">
                                <x-field name="form.privacy_protocol" :label="__('Chiffrement')" wire:model="form.privacy_protocol">
                                    @foreach (\App\Models\NetworkDevice::PRIVACY_PROTOCOLS as $protocol)<option value="{{ $protocol }}">{{ $protocol }}</option>@endforeach
                                </x-field>
                                <x-field name="form.privacy_password" :label="__('Mot de passe de chiffrement')" type="password" wire:model="form.privacy_password" autocomplete="new-password" :hint="$recordId ? __('Laissez vide pour conserver le secret enregistré.') : null"/>
                            </div>
                        @endif
                    @else
                        <x-field name="form.community" :label="__('Communauté')" type="password" wire:model="form.community" autocomplete="new-password" :hint="$recordId ? __('Laissez vide pour conserver le secret enregistré.') : __('Accès en lecture seule recommandé.')"/>
                    @endif
                </fieldset>
                <fieldset class="form-section">
                    <legend>{{ __('API de l’équipement') }}</legend>
                    @php($apiDriver = \App\Support\DeviceApi\DeviceApis::DRIVERS[$form['api_driver'] ?? ''] ?? null)
                    <x-field name="form.api_driver" :label="__('API')" wire:model.live="form.api_driver" :hint="$apiDriver ? __($apiDriver['hint']) : __('Facultatif. Complète SNMP pour les données que l’équipement n’y publie pas, comme les VLAN des ports.')">
                        <option value="">{{ __('Aucune, SNMP seulement') }}</option>
                        @foreach (\App\Support\DeviceApi\DeviceApis::DRIVERS as $value => $driver)<option value="{{ $value }}">{{ $driver['label'] }}</option>@endforeach
                    </x-field>
                    @if ($apiDriver)
                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-field name="form.api_username" :label="__('Utilisateur')" wire:model="form.api_username" maxlength="255" autocomplete="off"/>
                            <x-field name="form.api_password" :label="__('Mot de passe')" type="password" wire:model="form.api_password" autocomplete="new-password" :hint="$hasApiPassword ? __('Laissez vide pour conserver le secret enregistré.') : null"/>
                            <x-field name="form.api_port" :label="__('Port HTTPS')" type="number" min="1" max="65535" wire:model="form.api_port" :placeholder="$apiDriver['port']"/>
                        </div>
                        <label class="checkbox"><input type="checkbox" wire:model="form.api_verify_tls"> {{ __('Vérifier le certificat HTTPS') }}</label>
                    @endif
                </fieldset>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="form.poll_interval" :label="__('Interrogation automatique (minutes)')" type="number" min="5" max="10080" wire:model="form.poll_interval" :hint="__('Laissez vide pour interroger uniquement à la demande.')"/>
                    <x-field name="form.description" label="Description" wire:model="form.description" maxlength="255"/>
                </div>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($deleting)
        <x-modal id="device-delete" :title="__('Supprimer cette entrée ?')" :description="__('Les ports et adresses MAC relevés sur cet équipement seront supprimés. L’inventaire IP est conservé.')">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                <x-button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">{{ __('Supprimer') }}</x-button>
            </div>
        </x-modal>
    @endif
</div>
