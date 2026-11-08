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

        <form wire:submit="save('alerts')" class="card settings-card">
            <div><h2>{{ __('Alertes') }}</h2><p>{{ __('Seuil d’occupation, conflits d’adresses et hôtes qui disparaissent lors des découvertes.') }}</p></div>
            <x-field name="alerts.threshold" :label="__('Seuil d’occupation des sous-réseaux IPv4 (%)')" type="number" min="1" max="100" wire:model="alerts.threshold"/>
            <label class="checkbox"><input type="checkbox" wire:model="alerts.conflicts"> {{ __('Signaler les conflits : hôte non inventorié, adresse disponible qui répond, changement de MAC') }}</label>
            <label class="checkbox"><input type="checkbox" wire:model="alerts.host_down"> {{ __('Signaler les hôtes attribués qui ne répondent plus') }}</label>
            <label class="checkbox"><input type="checkbox" wire:model="alerts.enabled"> {{ __('Envoyer les nouvelles alertes par e-mail (SMTP requis)') }}</label>
            <x-field name="alerts.recipients" :label="__('Destinataires')" wire:model="alerts.recipients" placeholder="noc@exemple.fr, reseau@exemple.fr" maxlength="1000"/>
            <x-button type="submit" class="justify-self-start">{{ __('Enregistrer') }}</x-button>
        </form>

        <form wire:submit="save('dhcp')" class="card settings-card">
            <div><h2>{{ __('Baux DHCP') }}</h2><p>{{ __('Récupère les noms et adresses MAC annoncés par les clients DHCP, pour nommer les hôtes absents du DNS.') }}</p></div>
            <label class="checkbox"><input type="checkbox" wire:model="dhcp.enabled"> {{ __('Importer les baux DHCP') }}</label>
            @error('dhcp.enabled')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <x-field name="dhcp.kea_url" :label="__('URL de l’agent de contrôle Kea')" wire:model="dhcp.kea_url" placeholder="http://kea.exemple.fr:8000/" :hint="__('Commande lease4-get-all. Laissez vide pour n’utiliser que le fichier.')"/>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="dhcp.kea_username" :label="__('Identifiant Kea')" wire:model="dhcp.kea_username" autocomplete="off"/>
                <x-field name="dhcp.kea_password" :label="__('Mot de passe Kea')" type="password" wire:model="dhcp.kea_password" autocomplete="new-password" :hint="__('Laissez vide pour conserver le secret enregistré.')"/>
            </div>
            <div class="grid gap-4 sm:grid-cols-[1fr_9rem]">
                <x-field name="dhcp.leases_file" :label="__('Fichier de baux ISC dhcpd')" wire:model="dhcp.leases_file" placeholder="/var/lib/dhcp/dhcpd.leases" :hint="__('Chemin lisible par le worker, par exemple un volume monté.')"/>
                <x-field name="dhcp.interval" :label="__('Intervalle (minutes)')" type="number" min="1" max="1440" wire:model="dhcp.interval"/>
            </div>
            @if ($dhcpState['last_run'] ?? null)
                <p @class(['text-sm', 'field-error' => $dhcpState['error'] ?? null])>
                    {{ __('Dernier import : :date', ['date' => \Illuminate\Support\Carbon::parse($dhcpState['last_run'])->diffForHumans()]) }}
                    @if ($dhcpState['error'] ?? null) · {{ $dhcpState['error'] }}@elseif (isset($dhcpState['leases'])) · {{ __(':leases baux, :named noms, :created adresses ajoutées', ['leases' => $dhcpState['leases'], 'named' => $dhcpState['named'], 'created' => $dhcpState['created']]) }}@endif
                </p>
            @endif
            <div class="flex flex-wrap gap-2">
                <x-button type="submit">{{ __('Enregistrer') }}</x-button>
                <x-button variant="secondary" wire:click="importDhcp" wire:loading.attr="disabled" wire:target="importDhcp">{{ __('Importer maintenant') }}</x-button>
            </div>
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
