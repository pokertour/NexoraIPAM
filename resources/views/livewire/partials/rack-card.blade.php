@php($used = $rack->items->sum('height'))
@php($rackLinks = \App\Livewire\Racks::rackLinks($rack, $cables))
@php($rackCables = $rack->items->flatMap(fn ($item) => \App\Support\RackCabling::of($item, $cables))->unique('key')->values())
<section class="card rack-card" wire:key="rack-{{ $rack->id }}">
    <div class="section-heading">
        <div class="min-w-0">
            <h2 class="truncate">{{ $rack->name }}</h2>
            <p>{{ $rack->location?->path() ?? __('Sans emplacement') }} · {{ __(':used U sur :total', ['used' => $used, 'total' => $rack->units]) }}</p>
        </div>
        @can('edit-inventory')
            <div class="flex gap-1">
                <button class="icon-button" type="button" aria-label="{{ __('Modifier :name', ['name' => $rack->name]) }}" wire:click="open('rack', {{ $rack->id }})"><x-icon name="edit" class="size-4"/></button>
                <button class="icon-button danger-text" type="button" aria-label="{{ __('Supprimer :name', ['name' => $rack->name]) }}" wire:click="confirmDelete('rack', {{ $rack->id }})"><x-icon name="trash" class="size-4"/></button>
            </div>
        @endcan
    </div>
    <div class="rack-body" style="--u: {{ $unit }}px; --units: {{ $rack->units }}">
        <ol class="rack-scale" aria-hidden="true">
            @for ($u = $rack->units; $u >= 1; $u--)<li>{{ $u }}</li>@endfor
        </ol>
        <div class="rack-frame">
            @can('edit-inventory')
                @for ($u = $rack->units; $u >= 1; $u--)
                    @if (! $rack->items->contains(fn ($item) => $item->position <= $u && $item->top() >= $u))
                        <button type="button" class="rack-slot" style="top: {{ ($rack->units - $u) * $unit }}px" wire:click="open('item', null, {{ $rack->id }}, {{ $u }})" aria-label="{{ __('Ajouter un élément en U:unit', ['unit' => $u]) }}"><x-icon name="plus" class="size-3"/></button>
                    @endif
                @endfor
            @endcan
            @foreach ($rack->items as $item)
                @php($device = $item->device)
                @php($up = $device?->ports->where('oper_status', 1)->count())
                @php($itemCables = \App\Support\RackCabling::of($item, $cables))
                @php($outgoing = $itemCables->filter(fn ($cable) => $cable['far']['item']?->rack_id !== $rack->id)->values())
                <div class="rack-item" data-kind="{{ $item->kind }}" data-item="{{ $item->id }}" data-cables="{{ $itemCables->pluck('key')->implode(' ') }}"
                    :class="{ 'is-hot': hot && $el.dataset.cables.split(' ').includes(hot) }"
                    style="top: {{ ($rack->units - $item->top()) * $unit }}px; height: {{ $item->height * $unit }}px" wire:key="item-{{ $item->id }}">
                    @if ($device)
                        <span @class(['rack-led', 'is-down' => $device->poll_status === 'failed', 'is-unknown' => ! $device->polled_at])></span>
                        <a href="{{ route('devices.show', $device) }}" wire:navigate class="rack-item-name">{{ $device->name }}</a>
                    @else
                        <span class="rack-item-name">{{ $item->displayName() }}</span>
                        <span class="muted rack-item-kind">{{ __(\App\Models\RackItem::KINDS[$item->kind]) }}</span>
                    @endif
                    @if ($outgoing->isNotEmpty())
                        @php($first = $outgoing->first())
                        <button type="button" class="rack-exit" data-source="{{ $first['source'] }}"
                            title="{{ $outgoing->map(fn ($cable) => '→ '.($cable['far']['item']?->rack->name ?? __('Hors baie')).' · '.$cable['far']['name'].($cable['far']['port'] ? ' · '.$cable['far']['port'] : ''))->implode("\n") }}"
                            @mouseenter="hot = '{{ $first['key'] }}'" @mouseleave="hot = null"
                            @if ($first['far']['item']) @click="jump({{ $first['far']['item']->id }}, '{{ $first['key'] }}')" @endif>
                            <x-icon name="link" class="size-3"/>
                            <span>{{ $outgoing->count() > 1 ? $outgoing->count() : ($first['far']['item']?->rack->name ?? __('Hors baie')) }}</span>
                        </button>
                    @elseif ($device?->ports->isNotEmpty())
                        <span class="rack-ports" aria-label="{{ __(':up ports actifs sur :total', ['up' => $up, 'total' => $device->ports->count()]) }}">
                            @foreach ($device->ports->sortBy('if_index')->take(28) as $port)<i data-state="{{ $port->state() }}"></i>@endforeach
                        </span>
                    @endif
                    @can('edit-inventory')
                        <span class="rack-item-actions">
                            <button class="icon-button" type="button" aria-label="{{ __('Ajouter une liaison depuis :name', ['name' => $item->displayName()]) }}" title="{{ __('Ajouter une liaison') }}" wire:click="open('cable', null, {{ $item->id }})"><x-icon name="link" class="size-3.5"/></button>
                            <button class="icon-button" type="button" aria-label="{{ __('Modifier :name', ['name' => $item->displayName()]) }}" wire:click="open('item', {{ $item->id }})"><x-icon name="edit" class="size-3.5"/></button>
                            <button class="icon-button danger-text" type="button" aria-label="{{ __('Retirer :name de la baie', ['name' => $item->displayName()]) }}" wire:click="confirmDelete('item', {{ $item->id }})"><x-icon name="trash" class="size-3.5"/></button>
                        </span>
                    @endcan
                </div>
            @endforeach
        </div>
        <svg class="rack-links" width="64" height="{{ $rack->units * $unit }}" viewBox="0 0 64 {{ $rack->units * $unit }}" aria-hidden="true">
            @foreach ($rackLinks as $index => $link)
                @php($reach = 14 + ($index % 3) * 14)
                <path d="M0 {{ $link['from'] }} C{{ $reach }} {{ $link['from'] }}, {{ $reach }} {{ $link['to'] }}, 0 {{ $link['to'] }}" data-source="{{ $link['source'] }}" :class="{ 'is-hot': hot === '{{ $link['key'] }}' }"><title>{{ $link['label'] }}</title></path>
            @endforeach
        </svg>
    </div>
    @if ($rackCables->isNotEmpty())
        <ul class="rack-link-list">
            @foreach ($rackCables->sortBy(fn ($cable) => ($cable['far']['item']?->rack_id === $rack->id ? '0' : '1').($cable['far']['item']?->rack->name ?? '~')) as $cable)
                @php($inside = $cable['far']['item']?->rack_id === $rack->id)
                <li wire:key="cable-{{ $rack->id }}-{{ $cable['key'] }}" @mouseenter="hot = '{{ $cable['key'] }}'" @mouseleave="hot = null" :class="{ 'is-hot': hot === '{{ $cable['key'] }}' }">
                    <span class="link-source" data-source="{{ $cable['source'] }}">{{ ['lldp' => 'LLDP', 'mac' => 'MAC', 'manual' => __('MANUEL')][$cable['source']] }}</span>
                    @if ($cable['confirmed'])<span class="link-confirmed" title="{{ __('Liaison documentée confirmée par la détection') }}"><x-icon name="check" class="size-3"/></span>@endif
                    <span class="rack-link-ends">
                        {{ $cable['near']['name'] }} @if ($cable['near']['port'])<code>{{ $cable['near']['port'] }}</code>@endif
                        {{ $inside ? '↔' : '→' }}
                        @if (! $inside)
                            @if ($cable['far']['item'])
                                <button type="button" class="rack-link-jump" @click="jump({{ $cable['far']['item']->id }}, '{{ $cable['key'] }}')">{{ $cable['far']['item']->rack->name }}</button>
                            @else
                                <span class="muted">{{ __('Hors baie') }}</span>
                            @endif
                            ·
                        @endif
                        {{ $cable['far']['name'] }} @if ($cable['far']['port'])<code>{{ $cable['far']['port'] }}</code>@endif
                        @if ($cable['cable'])<small class="muted">· {{ __(\App\Models\CableLink::MEDIA[$cable['cable']->medium] ?? $cable['cable']->medium) }}@if ($cable['cable']->label) · {{ $cable['cable']->label }}@endif</small>@endif
                    </span>
                    @if ($cable['cable'])
                        @can('edit-inventory')
                            <span class="rack-link-actions">
                                <button class="icon-button" type="button" aria-label="{{ __('Modifier la liaison') }}" wire:click="open('cable', {{ $cable['cable']->id }})"><x-icon name="edit" class="size-3.5"/></button>
                                <button class="icon-button danger-text" type="button" aria-label="{{ __('Supprimer la liaison') }}" wire:click="confirmDelete('cable', {{ $cable['cable']->id }})"><x-icon name="trash" class="size-3.5"/></button>
                            </span>
                        @endcan
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
