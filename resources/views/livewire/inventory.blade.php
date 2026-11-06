<div>
    @if ($section === 'subnets' && ! $editing && ! $deleting && ! $scanSubnet && $records->contains(fn ($subnet) => $subnet->scanPending()))
        <span wire:key="discovery-poll" wire:poll.4s hidden></span>
    @endif
    <div class="page-heading">
        <div>
            <p class="eyebrow">INVENTAIRE RÉSEAU</p>
            <h1 class="page-title mt-2">{{ \App\Livewire\Inventory::SECTIONS[$section][0] }}</h1>
            <p class="muted mt-2">{{ \App\Livewire\Inventory::SECTIONS[$section][2] }}</p>
        </div>
        @can('edit-inventory')
            @if ($selectedSubnet)
                <x-button icon="plus" wire:click="openAddress({{ $selectedSubnet->id }})">Ajouter</x-button>
            @elseif (! $spaceSubnet)
                <x-button icon="plus" wire:click="open">Ajouter</x-button>
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
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Rechercher…" maxlength="100" aria-label="Rechercher dans l’inventaire">
            </label>
            @if ($section === 'addresses' && $addressSubnets->isNotEmpty())
                <x-select class="location-filter" name="subnet" wire:model.live="subnet" aria-label="Voir les adresses d’un sous-réseau">
                    <option value="">Tous les sous-réseaux</option>
                    @foreach ($addressSubnets as $option)
                        <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->cidr }}{{ $option->location ? ' · '.$option->location->path() : '' }}</option>
                    @endforeach
                </x-select>
            @endif
            @if (! $selectedSubnet && $section !== 'locations' && $locations->isNotEmpty())
                <x-select class="location-filter" name="location" wire:model.live="location" aria-label="Filtrer par emplacement">
                    <x-location-options :locations="$locations" placeholder="Tous les emplacements"/>
                </x-select>
            @endif
            <span class="muted ml-auto text-sm">{{ $records->total() }} {{ $records->total() === 1 ? 'entrée' : 'entrées' }}</span>
        </div>
        @if (count($displayRows) === 0)
            <x-empty-state :icon="$section" title="Aucun résultat" :text="$search ? 'Essayez une autre recherche.' : 'Les entrées de cet inventaire s’afficheront ici.'"/>
        @else
            <div class="table-scroll">
                <table>
                    <thead><tr>
                        @switch($section)
                            @case('subnets') <th>Réseau</th><th>Plage CIDR</th><th>VLAN</th><th>Occupation</th><th>Découverte</th> @break
                            @case('addresses') <th>Adresse IP</th><th>Nom d’hôte</th><th>Sous-réseau</th><th>Statut</th><th>Dernier ping</th> @break
                            @case('vlans') <th>Identifiant</th><th>Nom</th><th>Emplacement</th> @break
                            @case('locations') <th>Emplacement</th><th>Type</th><th>Inventaire direct</th><th>Découverte automatique</th> @break
                        @endswitch
                        @can('edit-inventory')<th class="text-right">Actions</th>@endcan
                    </tr></thead>
                    <tbody>
                        @foreach ($displayRows as $record)
                            @if (is_array($record))
                                <tr class="free-address-row" wire:key="gap-{{ $record['first'] }}">
                                    <td colspan="5">
                                        <code>{{ $record['first'] }}{{ $record['first'] !== $record['last'] ? ' – '.$record['last'] : '' }}</code>
                                        <small class="ml-2">{{ $record['count'] }} {{ $record['count'] === '1' ? 'adresse libre' : 'adresses libres' }}</small>
                                    </td>
                                    @can('edit-inventory')
                                        <td class="text-right">
                                            <x-button variant="secondary" icon="plus" wire:click="openAddress({{ $selectedSubnet->id }}, '{{ $record['first'] }}')">Attribuer</x-button>
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
                                            <small class="muted block">{{ $record->location?->path() ?? 'Sans emplacement' }}</small>
                                            <a class="table-link text-xs" href="{{ route('subnets', ['space' => $record->id]) }}" wire:navigate>Découpage</a>
                                        </td>
                                        <td><code>{{ $record->cidr }}</code></td><td>{{ $record->vlan ? $record->vlan->number.' · '.$record->vlan->name : '—' }}</td><td>
                                            @if (str_contains($record->cidr, ':'))
                                                <span>{{ $record->assigned }} {{ $record->assigned === 1 ? 'IP occupée' : 'IP occupées' }}</span>
                                            @else
                                                @php($capacity = $record->ipv4Capacity())
                                                <span class="tabular-nums">{{ $record->assigned }} / {{ number_format($capacity, 0, ',', ' ') }}</span>
                                                <x-meter :value="$capacity ? min(100, 100 * $record->assigned / $capacity) : 0" :label="'Occupation de '.$record->name" class="block mt-1"/>
                                            @endif
                                        </td><td><x-scan-status :subnet="$record"/></td>
                                        @break
                                    @case('addresses')
                                        <td>
                                            <code>{{ $record->address }}</code>
                                            @if ($record->mac_address)
                                                <small class="muted mt-1 block">MAC <a class="table-link" href="{{ route('tools', ['mac' => $record->mac_address]) }}" wire:navigate>{{ $record->mac_address }}</a></small>
                                            @endif
                                            @if ($record->description)<small class="muted mt-1 block">{{ $record->description }}</small>@endif
                                        </td><td>{{ $record->hostname ?: ($record->resolved_hostname ?: '—') }}
                                            @if ($record->resolved_hostname && $record->hostname !== $record->resolved_hostname)
                                                <small class="muted block">Résolution inverse @if ($record->hostname) · {{ $record->resolved_hostname }}@endif</small>
                                            @endif
                                        </td><td>{{ $record->subnet->name }}<small class="muted block">{{ $record->subnet->location?->path() ?? 'Sans emplacement' }}</small></td>
                                        <td><span @class(['badge', 'tone-violet' => $record->status === 'used', 'tone-amber' => $record->status === 'reserved', 'tone-cyan' => $record->status === 'available'])>{{ \App\Models\IpAddress::STATUSES[$record->status] }}</span></td>
                                        <td><x-ping-status :address="$record"/></td>
                                        @break
                                    @case('vlans')
                                        <td><code>{{ $record->number }}</code></td><td class="font-semibold">{{ $record->name }}@if ($record->description)<small class="muted block">{{ $record->description }}</small>@endif</td><td class="muted">{{ $record->location?->path() ?? 'Sans emplacement' }}</td>
                                        @break
                                    @case('locations')
                                        <td><span class="font-semibold">{{ $record->name }}</span>@if ($record->parent)<small class="muted block">{{ $record->parent->path() }}</small>@endif</td>
                                        <td><span class="badge tone-blue">{{ \App\Models\Location::KINDS[$record->kind] }}</span></td>
                                        <td><a class="table-link" href="{{ route('subnets', ['location' => $record->id]) }}" wire:navigate>{{ $record->subnets_count }} réseau(x)</a>
                                            <span class="muted"> · </span><a class="table-link" href="{{ route('vlans', ['location' => $record->id]) }}" wire:navigate>{{ $record->vlans_count }} VLAN</a></td>
                                        <td>
                                            @if ($record->discovery_interval)
                                                <span class="badge tone-blue">Toutes les {{ $record->discovery_interval }} min</span>
                                                <small class="muted mt-1 block">Prochaine échéance · {{ $record->next_discovery_at?->format('d/m/Y H:i T') }}</small>
                                            @else
                                                <span class="muted">Désactivée</span>
                                            @endif
                                        </td>
                                        @break
                                @endswitch
                                @can('edit-inventory')
                                    <td><div class="flex justify-end gap-1">
                                        @can('administer')
                                            @if ($section === 'subnets' && ! $record->scanPending())
                                                <button class="icon-button" type="button" title="Découvrir les hôtes" aria-label="Découvrir les hôtes de {{ $record->name }}" wire:click="confirmScan({{ $record->id }})"><x-icon name="radar" class="size-4"/></button>
                                            @endif
                                        @endcan
                                        <button class="icon-button" type="button" title="Modifier" aria-label="Modifier cette entrée" wire:click="open({{ $record->id }})"><x-icon name="edit" class="size-4"/></button>
                                        <button class="icon-button danger-text" type="button" title="Supprimer" aria-label="Supprimer cette entrée" wire:click="confirmDelete({{ $record->id }})"><x-icon name="trash" class="size-4"/></button>
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
        <x-modal id="inventory-edit" :title="($recordId ? 'Modifier · ' : 'Ajouter · ').\App\Livewire\Inventory::SECTIONS[$section][1]">
            <form wire:submit="save" class="mt-6 grid gap-4">
                @if ($section !== 'addresses')
                    <x-field name="form.name" label="Nom" wire:model="form.name" maxlength="100" required/>
                @endif
                @if (in_array($section, ['subnets', 'vlans'], true))
                    <x-field name="form.location_id" label="Emplacement" wire:model="form.location_id" :disabled="$allocationParent !== null">
                        <x-location-options :locations="$locations"/>
                    </x-field>
                @endif
                @switch($section)
                    @case('locations')
                        @if (!$recordId)
                            <x-field name="form.kind" label="Type" wire:model.live="form.kind">
                                @foreach (\App\Models\Location::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </x-field>
                            @php($parentKind = \App\Models\Location::PARENT_KINDS[$form['kind']] ?? null)
                            @if ($parentKind)
                                <x-field name="form.parent_id" label="Emplacement parent" wire:model="form.parent_id" required>
                                    <x-location-options :locations="$locations->where('kind', $parentKind)" placeholder="Sélectionner un parent"/>
                                </x-field>
                            @endif
                        @endif
                        @can('administer')
                            <x-field name="form.discovery_interval" label="Intervalle de découverte (minutes)" type="number" min="1" max="10080" wire:model="form.discovery_interval" hint="Laissez vide pour désactiver. S’applique aux sous-réseaux directement rattachés à cet emplacement."/>
                        @endcan
                        @break
                    @case('subnets')
                        <x-field name="form.cidr" label="Plage CIDR" wire:model="form.cidr" placeholder="192.0.2.0/24 ou 2001:db8::/32" required/>
                        <x-field name="form.vlan_id" label="VLAN" wire:model="form.vlan_id">
                            <option value="">Aucun VLAN</option>
                            @foreach ($options as $vlan)<option value="{{ $vlan->id }}">{{ $vlan->number }} · {{ $vlan->name }}{{ $vlan->location ? " · ".$vlan->location->path() : "" }}</option>@endforeach
                        </x-field>
                        @break
                    @case('addresses')
                        <x-field name="form.subnet_id" label="Sous-réseau" wire:model="form.subnet_id" required>
                            <option value="">Choisir un sous-réseau</option>
                            @foreach ($addressSubnets as $subnet)<option value="{{ $subnet->id }}">{{ $subnet->name }} · {{ $subnet->cidr }}{{ $subnet->location ? " · ".$subnet->location->path() : "" }}</option>@endforeach
                        </x-field>
                        <x-field name="form.address" label="Adresse IPv4 ou IPv6" wire:model="form.address" required/>
                        <x-field name="form.hostname" label="Nom d’hôte" wire:model="form.hostname" maxlength="253"/>
                        <x-field name="form.status" label="Statut" wire:model="form.status">
                            @foreach (\App\Models\IpAddress::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </x-field>
                        @break
                    @case('vlans')
                        <x-field name="form.number" label="Identifiant VLAN" type="number" wire:model="form.number" min="1" max="4094" required/>
                        @break
                @endswitch
                <x-field name="form.description" label="Description" wire:model="form.description" maxlength="255"/>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">Annuler</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">Enregistrer</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($scanSubnet)
        <x-modal id="inventory-scan" title="Découvrir les hôtes" :description="$scanSubnet->name.' · '.$scanSubnet->cidr">
            <p class="muted mt-4 text-sm">Le serveur sondera les adresses par ICMP depuis son propre réseau et cherchera le nom des hôtes qui répondent. Vérifiez qu’il accède à cet emplacement. Pour les grandes plages, seuls les premiers {{ \App\Support\NetworkDiscovery::LIMIT }} hôtes déjà saisis seront vérifiés.</p>
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">Annuler</x-button>
                <x-button icon="radar" wire:click="scan" wire:loading.attr="disabled" wire:target="scan">Lancer la découverte</x-button>
            </div>
        </x-modal>
    @endif

    @if ($deleting)
        <x-modal id="inventory-delete" title="Supprimer cette entrée ?" description="Cette action est définitive.">
            <div class="dialog-actions mt-6">
                <x-button variant="secondary" wire:click="close">Annuler</x-button>
                <x-button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Supprimer</x-button>
            </div>
        </x-modal>
    @endif
</div>
