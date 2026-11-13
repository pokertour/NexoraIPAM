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
                        <div class="device-badges">
                            <x-device-status :device="$device"/>
                            <x-device-role :device="$device" short/>
                        </div>
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
                    @if ($device->poll_error && in_array($device->poll_status, ['failed', 'unstable'], true))<p class="field-error text-xs">{{ $device->poll_error }}</p>@endif
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
        @include('livewire.partials.device-form')
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
