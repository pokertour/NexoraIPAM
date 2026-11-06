<div>
    <div class="page-heading">
        <div><p class="eyebrow">ADMINISTRATION</p><h1 class="page-title mt-2">Paramètres</h1><p class="muted mt-2">Configurez les méthodes de connexion et l’envoi d’e-mails.</p></div>
    </div>
    <div class="settings-grid">
        <form wire:submit="save('local')" class="card settings-card">
            <div><h2>Authentification interne</h2><p>Le compte d’installation garde un accès local de secours.</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="local.enabled"> Autoriser les comptes internes</label>
            @error('local.enabled')<p class="field-error">{{ $message }}</p>@enderror
            <x-button type="submit" class="justify-self-start">Enregistrer</x-button>
        </form>

        <form wire:submit="save('ldap')" class="card settings-card">
            <div><h2>Annuaire LDAP</h2><p>Les membres du groupe AD autorisé peuvent se connecter et obtiennent un compte Lecteur au premier accès.</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="ldap.enabled"> Activer LDAP</label>
            @error('ldap.enabled')<p class="field-error">{{ $message }}</p>@enderror
            <x-field name="ldap.uri" label="Adresse du serveur" wire:model="ldap.uri" placeholder="ldaps://ldap.exemple.fr:636"/>
            <x-field name="ldap.base_dn" label="Base de recherche (DN)" wire:model="ldap.base_dn" placeholder="ou=People,dc=exemple,dc=fr"/>
            <x-field name="ldap.group_dn" label="Groupe AD autorisé (DN)" wire:model="ldap.group_dn" placeholder="CN=NexoraIPAM,OU=Groupes,DC=exemple,DC=fr" hint="Les membres directs et imbriqués de ce groupe ont accès."/>
            <x-field name="ldap.bind_dn" label="Compte de service (DN)" wire:model="ldap.bind_dn"/>
            <x-field name="ldap.password" label="Mot de passe du compte de service" type="password" wire:model="ldap.password" autocomplete="new-password" hint="Laissez vide pour conserver le secret enregistré."/>
            <x-button type="submit" class="justify-self-start">Enregistrer</x-button>
        </form>

        <form wire:submit="save('oidc')" class="card settings-card">
            <div><h2>OpenID Connect</h2><p>Attribuez l’accès à cette application chez le fournisseur. Les nouveaux comptes autorisés sont créés avec le rôle Lecteur.</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="oidc.enabled"> Activer OpenID Connect</label>
            @error('oidc.enabled')<p class="field-error">{{ $message }}</p>@enderror
            <x-field name="oidc.issuer" label="URL de l’émetteur" wire:model="oidc.issuer" placeholder="https://sso.exemple.fr/realms/reseau"/>
            <x-field name="oidc.client_id" label="Identifiant du client" wire:model="oidc.client_id"/>
            <x-field name="oidc.secret" label="Secret du client" type="password" wire:model="oidc.secret" autocomplete="new-password" hint="Laissez vide pour conserver le secret enregistré."/>
            <x-field name="oidc.callback" label="URL de retour à déclarer chez le fournisseur" :value="route('oidc.callback')" readonly/>
            <x-button type="submit" class="justify-self-start">Enregistrer</x-button>
        </form>

        <form wire:submit="save('smtp')" class="card settings-card">
            <div><h2>Serveur SMTP</h2><p>Configurez l’expédition des messages et envoyez un e-mail de test à votre compte.</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="smtp.enabled"> Activer l’envoi via SMTP</label>
            @error('smtp.enabled')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="smtp.scheme" label="Chiffrement" wire:model="smtp.scheme">
                    <option value="smtps">TLS implicite</option><option value="smtp">STARTTLS</option>
                </x-field>
                <x-field name="smtp.port" label="Port" type="number" wire:model="smtp.port" min="1" max="65535"/>
            </div>
            <x-field name="smtp.host" label="Serveur" wire:model="smtp.host" placeholder="smtp.exemple.fr"/>
            <x-field name="smtp.username" label="Identifiant (si requis)" wire:model="smtp.username"/>
            <x-field name="smtp.password" label="Mot de passe (si requis)" type="password" wire:model="smtp.password" autocomplete="new-password" hint="Laissez vide pour conserver le secret enregistré."/>
            <x-field name="smtp.from" label="Adresse d’expédition" type="email" wire:model="smtp.from"/>
            <div class="flex flex-wrap gap-2">
                <x-button type="submit">Enregistrer</x-button>
                <x-button variant="secondary" wire:click="testMail" wire:loading.attr="disabled" wire:target="testMail">Envoyer un test</x-button>
            </div>
        </form>
    </div>
</div>
