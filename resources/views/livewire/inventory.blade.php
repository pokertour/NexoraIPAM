<div>
    @if ($section === 'subnets' && ! $editing && ! $deleting && ! $scanSubnet && $records->contains(fn ($subnet) => $subnet->scanPending()))
        <span wire:key="discovery-poll" wire:poll.4s hidden></span>
    @endif
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ __('INVENTAIRE RÉSEAU') }}</p>
            <h1 class="page-title mt-2">{{ __(\App\Livewire\Inventory::SECTIONS[$section][0]) }}</h1>
            <p class="muted mt-2">{{ __(\App\Livewire\Inventory::SECTIONS[$section][2]) }}</p>
        </div>
        @can('edit-inventory')
            @if ($selectedSubnet)
                <x-button icon="plus" wire:click="openAddress({{ $selectedSubnet->id }})">{{ __('Ajouter') }}</x-button>
            @elseif (! $spaceSubnet)
                <x-button icon="plus" wire:click="open">{{ __('Ajouter') }}</x-button>
            @endif
        @endcan
    </div>

    @if ($spaceSubnet)
        <x-subnet-space :subnet="$spaceSubnet" :map="$spaceMap"/>
    @else
    @if ($selectedSubnet && ! trim($search))
        <x-address-grid :subnet="$selectedSubnet" :cells="$addressGrid" :delegated-count="$delegatedCount"/>
    @endif
    <section class="card">
        <div class="table-toolbar">
            <label class="search">
                <x-icon name="search" class="size-4"/>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Rechercher…') }}" maxlength="100" aria-label="{{ __('Rechercher dans l’inventaire') }}">
            </label>
            @if ($section === 'addresses' && $addressSubnets->isNotEmpty())
                <x-select class="location-filter" name="subnet" wire:model.live="subnet" aria-label="{{ __('Voir les adresses d’un sous-réseau') }}">
                    <option value="">{{ __('Tous les sous-réseaux') }}</option>
                    @foreach ($addressSubnets as $option)
                        <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->cidr }}{{ $option->location ? ' · '.$option->location->path() : '' }}</option>
                    @endforeach
                </x-select>
            @endif
            @if ($section === 'addresses')
                <x-select class="location-filter" name="status" wire:model.live="status" aria-label="{{ __('Filtrer par statut') }}">
                    <option value="">{{ __('Tous les statuts') }}</option>
                    @foreach (\App\Models\IpAddress::STATUSES as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                </x-select>
                @if (isset(\App\Livewire\Inventory::PING_FILTERS[$ping]))
                    <button type="button" class="filter-chip" wire:click="$set('ping', '')" aria-label="{{ __('Retirer le filtre') }}">{{ __('Dernier ping') }} · {{ __(\App\Livewire\Inventory::PING_FILTERS[$ping]) }} <x-icon name="close" class="size-3"/></button>
                @endif
            @endif
            @if ($allTags->isNotEmpty())
                <x-select class="location-filter" name="tag" wire:model.live="tag" aria-label="{{ __('Filtrer par étiquette') }}">
                    <option value="">{{ __('Toutes les étiquettes') }}</option>
                    @foreach ($allTags as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                </x-select>
            @endif
            @if (! $selectedSubnet && $section !== 'locations' && $locations->isNotEmpty())
                <x-select class="location-filter" name="location" wire:model.live="location" aria-label="{{ __('Filtrer par emplacement') }}">
                    <x-location-options :locations="$locations" :placeholder="__('Tous les emplacements')"/>
                </x-select>
            @endif
            <span class="count-pill ml-auto">{{ $records->total() }} {{ $records->total() === 1 ? __('entrée') : __('entrées') }}</span>
        </div>
        @if (count($displayRows) === 0)
            <x-empty-state :icon="$section" :title="__('Aucun résultat')" :text="$search ? __('Essayez une autre recherche.') : __('Les entrées de cet inventaire s’afficheront ici.')"/>
        @else
            <div class="table-scroll">
                <table>
                    <thead><tr>
                        @switch($section)
                            @case('subnets') <th>{{ __('Réseau') }}</th><th>{{ __('Plage CIDR') }}</th><th>VLAN</th><th>{{ __('Occupation') }}</th><th>{{ __('Découverte') }}</th> @break
                            @case('addresses') <th>{{ __('Adresse IP') }}</th><th>{{ __('Nom d’hôte') }}</th><th>{{ __('Sous-réseau') }}</th><th>{{ __('Statut') }}</th><th>{{ __('Dernier ping') }}</th> @break
                            @case('vlans') <th>{{ __('Identifiant') }}</th><th>{{ __('Nom') }}</th><th>{{ __('Emplacement') }}</th> @break
                            @case('locations') <th>{{ __('Emplacement') }}</th><th>Type</th><th>{{ __('Inventaire direct') }}</th><th>{{ __('Découverte automatique') }}</th> @break
                        @endswitch
                        @can('edit-inventory')<th class="text-right">{{ __('Actions') }}</th>@endcan
                    </tr></thead>
                    <tbody>
                        @foreach ($displayRows as $record)
                            @if (is_array($record))
                                <tr class="free-address-row" wire:key="gap-{{ $record['first'] }}">
                                    <td colspan="5">
                                        <code>{{ $record['first'] }}{{ $record['first'] !== $record['last'] ? ' – '.$record['last'] : '' }}</code>
                                        <small class="ml-2">{{ $record['count'] }} {{ $record['count'] === '1' ? __('adresse libre') : __('adresses libres') }}</small>
                                    </td>
                                    @can('edit-inventory')
                                        <td class="text-right">
                                            <x-button variant="secondary" icon="plus" wire:click="openAddress({{ $selectedSubnet->id }}, '{{ $record['first'] }}')">{{ __('Attribuer') }}</x-button>
                                        </td>
                                    @endcan
                                </tr>
                                @continue
                            @endif
                            <tr wire:key="{{ $section }}-{{ $record->id }}">
                                @switch($section)
                                    @case('subnets')
                                        <td>
                                            <a class="table-link font-semibold" href="{{ route('addresses', ['subnet' => $record->id]) }}" wire:navigate>{{ $record->name }}</a>
                                            <small class="muted block">{{ $record->location?->path() ?? __('Sans emplacement') }}</small>
                                            <a class="table-link text-xs" href="{{ route('subnets', ['space' => $record->id]) }}" wire:navigate>{{ __('Découpage') }}</a>
                                            <x-record-metadata :record="$record" :fields="$customFields"/>
                                        </td>
                                        <td><code>{{ $record->cidr }}</code>@if ($record->gateway)<small class="muted block">{{ __('Passerelle') }} <code>{{ $record->gateway }}</code></small>@endif</td><td>@if ($record->vlan)<x-vlan-chip :number="$record->vlan->number" :color="$record->vlan->displayColor()" :name="$record->vlan->name"/>@else — @endif</td><td>
                                            @if (str_contains($record->cidr, ':'))
                                                <span>{{ $record->assigned }} {{ $record->assigned === 1 ? __('IP occupée') : __('IP occupées') }}</span>
                                            @else
                                                @php($capacity = $record->ipv4Capacity())
                                                <span class="tabular-nums">{{ $record->assigned }} / {{ number_format($capacity, 0, ',', ' ') }}</span>
                                                <x-meter :value="$capacity ? min(100, 100 * $record->assigned / $capacity) : 0" :label="__('Occupation de :name', ['name' => $record->name])" class="block mt-1"/>
                                            @endif
                                        </td><td><x-scan-status :subnet="$record"/></td>
                                        @break
                                    @case('addresses')
                                        <td>
                                            <span class="inline-flex items-center gap-1"><code>{{ $record->address }}</code>
                                                <button type="button" class="icon-button size-6" title="{{ __('Historique') }}" aria-label="{{ __('Historique de :address', ['address' => $record->address]) }}" wire:click="showHistory({{ $record->id }})"><x-icon name="history" class="size-3.5"/></button></span>
                                            @if ($record->mac_address)
                                                <small class="muted mt-1 block">MAC <a class="table-link" href="{{ route('tools', ['mac' => $record->mac_address]) }}" wire:navigate>{{ $record->mac_address }}</a></small>
                                            @endif
                                            @if ($record->description)<small class="muted mt-1 block">{{ $record->description }}</small>@endif
                                            <x-record-metadata :record="$record" :fields="$customFields"/>
                                        </td><td>{{ $record->hostname ?: ($record->resolved_hostname ?: '—') }}
                                            @if ($record->resolved_hostname && $record->hostname !== $record->resolved_hostname)
                                                <small class="muted block">{{ __('Résolution inverse') }} @if ($record->hostname) · {{ $record->resolved_hostname }}@endif</small>
                                            @endif
                                        </td><td>{{ $record->subnet->name }}<small class="muted block">{{ $record->subnet->location?->path() ?? __('Sans emplacement') }}</small></td>
                                        <td><span @class(['badge', 'tone-violet' => $record->status === 'used', 'tone-amber' => $record->status === 'reserved', 'tone-cyan' => $record->status === 'available'])>{{ __(\App\Models\IpAddress::STATUSES[$record->status]) }}</span></td>
                                        <td><x-ping-status :address="$record"/></td>
                                        @break
                                    @case('vlans')
                                        <td><x-vlan-chip :number="$record->number" :color="$record->displayColor()"/></td><td><span class="font-semibold">{{ $record->name }}</span>@if ($record->description)<small class="muted block">{{ $record->description }}</small>@endif<x-record-metadata :record="$record" :fields="$customFields"/></td><td class="muted">{{ $record->location?->path() ?? __('Sans emplacement') }}</td>
                                        @break
                                    @case('locations')
                                        <td><span class="font-semibold">{{ $record->name }}</span>@if ($record->parent)<small class="muted block">{{ $record->parent->path() }}</small>@endif</td>
                                        <td><span class="badge tone-blue">{{ __(\App\Models\Location::KINDS[$record->kind]) }}</span></td>
                                        <td><a class="table-link" href="{{ route('subnets', ['location' => $record->id]) }}" wire:navigate>{{ trans_choice(':count réseau|:count réseaux', $record->subnets_count) }}</a>
                                            <span class="muted"> · </span><a class="table-link" href="{{ route('vlans', ['location' => $record->id]) }}" wire:navigate>{{ $record->vlans_count }} VLAN</a></td>
                                        <td>
                                            @if ($record->discovery_interval)
                                                <span class="badge tone-blue">{{ __('Toutes les :minutes min', ['minutes' => $record->discovery_interval]) }}</span>
                                                <small class="muted mt-1 block">{{ __('Prochaine échéance') }} · {{ $record->next_discovery_at?->format(__('d/m/Y H:i T')) }}</small>
                                            @else
                                                <span class="muted">{{ __('Désactivée') }}</span>
                                            @endif
                                        </td>
                                        @break
                                @endswitch
                                @can('edit-inventory')
                                    <td><div class="flex justify-end gap-1">
                                        @can('administer')
                                            @if ($section === 'subnets' && ! $record->scanPending())
                                                <button class="icon-button" type="button" title="{{ __('Découvrir les hôtes') }}" aria-label="{{ __('Découvrir les hôtes de :name', ['name' => $record->name]) }}" wire:click="confirmScan({{ $record->id }})"><x-icon name="radar" class="size-4"/></button>
                                            @endif
                                        @endcan
                                        <button class="icon-button" type="button" title="{{ __('Modifier') }}" aria-label="{{ __('Modifier cette entrée') }}" wire:click="open({{ $record->id }})"><x-icon name="edit" class="size-4"/></button>
                                        <button class="icon-button danger-text" type="button" title="{{ __('Supprimer') }}" aria-label="{{ __('Supprimer cette entrée') }}" wire:click="confirmDelete({{ $record->id }})"><x-icon name="trash" class="size-4"/></button>
                                    </div></td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($records->hasPages())<div class="pagination">{{ $records->links() }}</div>@endif
        @endif
    </section>
    @endif

    @if ($editing)
        <x-modal id="inventory-edit" :title="__($recordId ? 'Modifier · :entry' : 'Ajouter · :entry', ['entry' => __(\App\Livewire\Inventory::SECTIONS[$section][1])])">
            <form wire:submit="save" class="mt-6 grid gap-4">
                @if ($section !== 'addresses')
                    <x-field name="form.name" :label="__('Nom')" wire:model="form.name" maxlength="100" required/>
                @endif
                @if (in_array($section, ['subnets', 'vlans'], true))
                    <x-field name="form.location_id" :label="__('Emplacement')" wire:model="form.location_id" :disabled="$allocationParent !== null">
                        <x-location-options :locations="$locations"/>
                    </x-field>
                @endif
                @switch($section)
                    @case('locations')
                        @if (!$recordId)
                            <x-field name="form.kind" label="Type" wire:model.live="form.kind">
                                @foreach (\App\Models\Location::KINDS as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                            </x-field>
                            @php($parentKind = \App\Models\Location::PARENT_KINDS[$form['kind']] ?? null)
                            @if ($parentKind)
                                <x-field name="form.parent_id" :label="__('Emplacement parent')" wire:model="form.parent_id" required>
                                    <x-location-options :locations="$locations->where('kind', $parentKind)" :placeholder="__('Sélectionner un parent')"/>
                                </x-field>
                            @endif
                        @endif
                        @can('administer')
                            <x-field name="form.discovery_interval" :label="__('Intervalle de découverte (minutes)')" type="number" min="1" max="10080" wire:model="form.discovery_interval" :hint="__('Laissez vide pour désactiver. S’applique aux sous-réseaux directement rattachés à cet emplacement.')"/>
                        @endcan
                        @break
                    @case('subnets')
                        <x-field name="form.cidr" :label="__('Plage CIDR')" wire:model="form.cidr" :placeholder="__('192.0.2.0/24 ou 2001:db8::/32')" required/>
                        <x-field name="form.vlan_id" label="VLAN" wire:model="form.vlan_id">
                            <option value="">{{ __('Aucun VLAN') }}</option>
                            @foreach ($options as $vlan)<option value="{{ $vlan->id }}">{{ $vlan->number }} · {{ $vlan->name }}{{ $vlan->location ? " · ".$vlan->location->path() : "" }}</option>@endforeach
                        </x-field>
                        <fieldset class="form-section">
                            <legend>{{ __('Paramètres réseau') }}</legend>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-field name="form.gateway" :label="__('Passerelle')" wire:model="form.gateway" placeholder="192.0.2.1" maxlength="45"/>
                                <x-field name="form.domain" :label="__('Domaine DNS')" wire:model="form.domain" placeholder="lan.exemple.fr" maxlength="253"/>
                            </div>
                            <x-field name="form.dns_servers" :label="__('Serveurs DNS')" wire:model="form.dns_servers" placeholder="192.0.2.53, 2001:db8::53" maxlength="255" :hint="__('Séparez les adresses par des virgules.')"/>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-field name="form.dhcp_start" :label="__('Début de la plage DHCP')" wire:model="form.dhcp_start" placeholder="192.0.2.100" maxlength="45"/>
                                <x-field name="form.dhcp_end" :label="__('Fin de la plage DHCP')" wire:model="form.dhcp_end" placeholder="192.0.2.199" maxlength="45"/>
                            </div>
                        </fieldset>
                        @break
                    @case('addresses')
                        <x-field name="form.subnet_id" :label="__('Sous-réseau')" wire:model="form.subnet_id" required>
                            <option value="">{{ __('Choisir un sous-réseau') }}</option>
                            @foreach ($addressSubnets as $subnet)<option value="{{ $subnet->id }}">{{ $subnet->name }} · {{ $subnet->cidr }}{{ $subnet->location ? " · ".$subnet->location->path() : "" }}</option>@endforeach
                        </x-field>
                        <x-field name="form.address" :label="__('Adresse IPv4 ou IPv6')" wire:model="form.address" required/>
                        <x-field name="form.hostname" :label="__('Nom d’hôte')" wire:model="form.hostname" maxlength="253"/>
                        <x-field name="form.status" :label="__('Statut')" wire:model="form.status">
                            @foreach (\App\Models\IpAddress::STATUSES as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                        </x-field>
                        @break
                    @case('vlans')
                        <x-field name="form.number" :label="__('Identifiant VLAN')" type="number" wire:model.live.debounce.300ms="form.number" min="1" max="4094" required/>
                        @php($automaticColor = \App\Support\VlanColor::automatic((int) ($form['number'] ?? 1)))
                        <div class="field">
                            <span>{{ __('Couleur') }}</span>
                            <div class="color-picker" role="radiogroup" aria-label="{{ __('Couleur') }}">
                                <label class="color-swatch color-swatch-auto" style="--tag: {{ $automaticColor }}" title="{{ __('Automatique') }}">
                                    <input type="radio" wire:model="form.color" value="" class="sr-only">
                                    <span aria-hidden="true">A</span><span class="sr-only">{{ __('Automatique') }}</span>
                                </label>
                                @foreach (\App\Support\VlanColor::PALETTE as $color)
                                    <label class="color-swatch" style="--tag: {{ $color }}" title="{{ $color }}">
                                        <input type="radio" wire:model="form.color" value="{{ $color }}" class="sr-only">
                                        <span aria-hidden="true"></span><span class="sr-only">{{ $color }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <small>{{ __('La couleur automatique dépend du numéro du VLAN. Elle s’affiche sur la façade des switchs.') }}</small>
                            @error('form.color')<small class="field-error" role="alert">{{ $message }}</small>@enderror
                        </div>
                        @break
                @endswitch
                <x-field name="form.description" label="Description" wire:model="form.description" maxlength="255"/>
                @if (isset(\App\Models\CustomField::TARGETS[$section]))
                    @if ($allTags->isNotEmpty())
                        <x-field name="form.tags" :label="__('Étiquettes')" wire:model="form.tags" multiple :placeholder="__('Ajouter des étiquettes…')">
                            @foreach ($allTags as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                        </x-field>
                    @endif
                    @if ($customFields->isNotEmpty())
                        <fieldset class="form-section">
                            <legend>{{ __('Champs personnalisés') }}</legend>
                            @foreach ($customFields as $field)
                                @if ($field->type === 'select')
                                    <x-field name="form.custom.{{ $field->key }}" :label="$field->label" wire:model="form.custom.{{ $field->key }}" :required="$field->required">
                                        <option value="">—</option>
                                        @foreach ($field->options ?? [] as $choice)<option value="{{ $choice }}">{{ $choice }}</option>@endforeach
                                    </x-field>
                                @else
                                    <x-field name="form.custom.{{ $field->key }}" :label="$field->label" wire:model="form.custom.{{ $field->key }}" :type="['number' => 'number', 'url' => 'url', 'date' => 'date'][$field->type] ?? 'text'" :required="$field->required" maxlength="500" step="any"/>
                                @endif
                            @endforeach
                        </fieldset>
                    @endif
                @endif
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($historyAddress)
        <x-modal id="address-history" wide :title="__('Historique · :address', ['address' => $historyAddress->address])" :description="$historyAddress->subnet->name.' · '.$historyAddress->subnet->cidr">
            <div class="mt-5 grid gap-5">
                <section>
                    <h3 class="history-title">{{ __('Observations réseau') }}</h3>
                    @if ($historyAddress->observations->isEmpty())
                        <p class="muted text-sm">{{ __('Aucune observation. Lancez une découverte ou interrogez un équipement SNMP.') }}</p>
                    @else
                        <ol class="timeline">
                            @foreach ($historyAddress->observations as $observation)
                                <li @class(['timeline-current' => $loop->first])>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($observation->mac_address)<a class="table-link font-mono text-xs" href="{{ route('tools', ['mac' => $observation->mac_address]) }}" wire:navigate>{{ $observation->mac_address }}</a>@else<span class="muted text-xs">{{ __('MAC inconnue') }}</span>@endif
                                        @if ($observation->hostname)<span class="font-semibold">{{ $observation->hostname }}</span>@endif
                                        <span class="badge tone-blue">{{ __(\App\Models\AddressObservation::SOURCES[$observation->source] ?? $observation->source) }}</span>
                                        @if ($observation->location)<span class="muted text-xs">{{ $observation->location }}</span>@endif
                                    </div>
                                    <small class="muted block mt-1 tabular-nums">{{ $observation->first_seen_at->format(__('d/m/Y H:i')) }} → {{ $observation->last_seen_at->format(__('d/m/Y H:i')) }} · {{ trans_choice(':count fois|:count fois', $observation->times_seen) }}</small>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
                @if ($historyPorts->isNotEmpty())
                    <section>
                        <h3 class="history-title">{{ __('Branchement actuel') }}</h3>
                        <ul class="grid gap-2">
                            @foreach ($historyPorts as $location)
                                <li class="flex flex-wrap items-center gap-2 text-sm">
                                    <x-icon name="switch" class="muted size-4"/>
                                    <a class="table-link font-semibold" href="{{ route('devices.show', ['device' => $location->network_device_id, 'port' => $location->port->if_index]) }}" wire:navigate>{{ $location->device->name }} · {{ $location->port->name }}</a>
                                    @if ($location->vlan)<span class="badge tone-violet">VLAN {{ $location->vlan }}</span>@endif
                                    <small class="muted">{{ __('vu le :date', ['date' => $location->seen_at->format(__('d/m/Y H:i'))]) }}</small>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                <section>
                    <h3 class="history-title">{{ __('Modifications') }}</h3>
                    @if ($historyChanges->isEmpty())
                        <p class="muted text-sm">{{ __('Aucune modification enregistrée.') }}</p>
                    @else
                        <ol class="timeline">
                            @foreach ($historyChanges as $entry)
                                <li>
                                    <div class="flex flex-wrap items-center gap-2"><x-audit-action :action="$entry->action"/><span class="text-sm">{{ $entry->actor ?? __('Système') }}</span><small class="muted tabular-nums">{{ $entry->created_at->format(__('d/m/Y H:i')) }}</small></div>
                                    <div class="mt-1.5"><x-audit-changes :changes="$entry->changes"/></div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>
            <div class="dialog-actions mt-6"><x-button variant="secondary" wire:click="close">{{ __('Fermer') }}</x-button></div>
        </x-modal>
    @endif

    @if ($scanSubnet)
        <x-modal id="inventory-scan" :title="__('Découvrir les hôtes')" :description="$scanSubnet->name.' · '.$scanSubnet->cidr">
            <p class="muted mt-4 text-sm">{{ __('Le serveur sondera les adresses par ICMP depuis son propre réseau et cherchera le nom des hôtes qui répondent. Vérifiez qu’il accède à cet emplacement. Pour les grandes plages, seuls les premiers :limit hôtes déjà saisis seront vérifiés.', ['limit' => \App\Support\NetworkDiscovery::LIMIT]) }}</p>
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                <x-button icon="radar" wire:click="scan" wire:loading.attr="disabled" wire:target="scan">{{ __('Lancer la découverte') }}</x-button>
            </div>
        </x-modal>
    @endif

    @if ($deleting)
        <x-modal id="inventory-delete" :title="__('Supprimer cette entrée ?')" :description="__('Cette action est définitive.')">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                <x-button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">{{ __('Supprimer') }}</x-button>
            </div>
        </x-modal>
    @endif
</div>
