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
                        <x-field name="form.api_port" :label="__('Port TCP')" type="number" min="1" max="65535" wire:model="form.api_port" :placeholder="$apiDriver['port']"/>
                    </div>
                    @if ($apiDriver['tls'])
                    <label class="checkbox"><input type="checkbox" wire:model="form.api_verify_tls"> {{ __('Vérifier le certificat') }}</label>
                @endif
                @if ($apiDriver['warning'] ?? null)
                    <div class="callout callout-warning" role="note"><x-icon name="info" class="size-4"/> {{ __($apiDriver['warning']) }}</div>
                @endif
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
