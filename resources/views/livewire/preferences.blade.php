<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('ADMINISTRATION') }}</p><h1 class="page-title mt-2">{{ __('Paramètres') }}</h1><p class="muted mt-2">{{ __('Configurez les méthodes de connexion et l’envoi d’e-mails.') }}</p></div>
    </div>
    <div class="settings-grid">
        <form wire:submit="save('local')" class="card settings-card">
            <div><h2>{{ __('Authentification interne') }}</h2><p>{{ __('Le compte d’installation garde un accès local de secours.') }}</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="local.enabled"> {{ __('Autoriser les comptes internes') }}</label>
            @error('local.enabled')<p class="field-error">{{ $message }}</p>@enderror
            <x-button type="submit" class="justify-self-start">{{ __('Enregistrer') }}</x-button>
        </form>

        <form wire:submit="save('ldap')" class="card settings-card">
            <div><h2>{{ __('Annuaire LDAP') }}</h2><p>{{ __('Les membres du groupe AD autorisé peuvent se connecter et obtiennent un compte Lecteur au premier accès.') }}</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="ldap.enabled"> {{ __('Activer LDAP') }}</label>
            @error('ldap.enabled')<p class="field-error">{{ $message }}</p>@enderror
            <x-field name="ldap.uri" :label="__('Adresse du serveur')" wire:model="ldap.uri" placeholder="ldaps://ldap.exemple.fr:636"/>
            <x-field name="ldap.base_dn" :label="__('Base de recherche (DN)')" wire:model="ldap.base_dn" placeholder="ou=People,dc=exemple,dc=fr"/>
            <x-field name="ldap.group_dn" :label="__('Groupe AD autorisé (DN)')" wire:model="ldap.group_dn" placeholder="CN=NexoraIPAM,OU=Groupes,DC=exemple,DC=fr" :hint="__('Les membres directs et imbriqués de ce groupe ont accès.')"/>
            <x-field name="ldap.bind_dn" :label="__('Compte de service (DN)')" wire:model="ldap.bind_dn"/>
            <x-field name="ldap.password" :label="__('Mot de passe du compte de service')" type="password" wire:model="ldap.password" autocomplete="new-password" :hint="__('Laissez vide pour conserver le secret enregistré.')"/>
            <x-button type="submit" class="justify-self-start">{{ __('Enregistrer') }}</x-button>
        </form>

        <form wire:submit="save('oidc')" class="card settings-card">
            <div><h2>OpenID Connect</h2><p>{{ __('Attribuez l’accès à cette application chez le fournisseur. Les nouveaux comptes autorisés sont créés avec le rôle Lecteur.') }}</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="oidc.enabled"> {{ __('Activer OpenID Connect') }}</label>
            @error('oidc.enabled')<p class="field-error">{{ $message }}</p>@enderror
            <x-field name="oidc.issuer" :label="__('URL de l’émetteur')" wire:model="oidc.issuer" placeholder="https://sso.exemple.fr/realms/reseau"/>
            <x-field name="oidc.client_id" :label="__('Identifiant du client')" wire:model="oidc.client_id"/>
            <x-field name="oidc.secret" :label="__('Secret du client')" type="password" wire:model="oidc.secret" autocomplete="new-password" :hint="__('Laissez vide pour conserver le secret enregistré.')"/>
            <x-field name="oidc.callback" :label="__('URL de retour à déclarer chez le fournisseur')" :value="route('oidc.callback')" readonly/>
            <x-button type="submit" class="justify-self-start">{{ __('Enregistrer') }}</x-button>
        </form>

        <form wire:submit="save('smtp')" class="card settings-card">
            <div><h2>{{ __('Serveur SMTP') }}</h2><p>{{ __('Configurez l’expédition des messages et envoyez un e-mail de test à votre compte.') }}</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="smtp.enabled"> {{ __('Activer l’envoi via SMTP') }}</label>
            @error('smtp.enabled')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="smtp.scheme" :label="__('Chiffrement')" wire:model="smtp.scheme">
                    <option value="smtps">{{ __('TLS implicite') }}</option><option value="smtp">STARTTLS</option>
                </x-field>
                <x-field name="smtp.port" label="Port" type="number" wire:model="smtp.port" min="1" max="65535"/>
            </div>
            <x-field name="smtp.host" label="Serveur" wire:model="smtp.host" placeholder="smtp.exemple.fr"/>
            <x-field name="smtp.username" :label="__('Identifiant (si requis)')" wire:model="smtp.username"/>
            <x-field name="smtp.password" :label="__('Mot de passe (si requis)')" type="password" wire:model="smtp.password" autocomplete="new-password" :hint="__('Laissez vide pour conserver le secret enregistré.')"/>
            <x-field name="smtp.from" :label="__('Adresse d’expédition')" type="email" wire:model="smtp.from"/>
            <div class="flex flex-wrap gap-2">
                <x-button type="submit">{{ __('Enregistrer') }}</x-button>
                <x-button variant="secondary" wire:click="testMail" wire:loading.attr="disabled" wire:target="testMail">{{ __('Envoyer un test') }}</x-button>
            </div>
        </form>
    </div>
</div>
