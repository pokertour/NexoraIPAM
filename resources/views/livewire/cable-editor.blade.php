<div>
    @if ($open)
        <x-modal id="cable-editor" wide :title="__($cableId ? 'Modifier la liaison' : 'Ajouter une liaison')" :description="__('Documentez un câble que LLDP et les tables MAC ne voient pas : brassage, fibre entre baies, équipement sans SNMP. Une liaison détectée entre les mêmes ports la confirme.')">
            <form wire:submit="save" class="mt-6 grid gap-4">
                @foreach (['a' => __('Extrémité A'), 'b' => __('Extrémité B')] as $end => $title)
                    @php
                        $chosen = $endpoints->get($form[$end] ?? '');
                    @endphp
                    <fieldset class="form-section">
                        <legend>{{ $title }}</legend>
                        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]">
                            <x-field :name="'form.'.$end" :label="__('Élément ou équipement')" wire:model.live="form.{{ $end }}">
                                <option value="">{{ __('Choisir un élément') }}</option>
                                @foreach ($endpoints->groupBy('group', true) as $group => $options)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($options as $key => $option)<option value="{{ $key }}">{{ $option['label'] }}</option>@endforeach
                                    </optgroup>
                                @endforeach
                            </x-field>
                            @if ($chosen['device'] ?? null)
                                <x-field :name="'form.'.$end.'_port'" :label="__('Port')" wire:model="form.{{ $end }}_port" wire:key="cable-port-{{ $end }}-{{ $form[$end] }}">
                                    <option value="">{{ __('Non précisé') }}</option>
                                    @foreach ($chosen['device']->ports->where('physical', true)->sortBy('name', SORT_NATURAL) as $port)<option value="{{ $port->name }}">{{ $port->name }}</option>@endforeach
                                </x-field>
                            @else
                                <x-field :name="'form.'.$end.'_port'" :label="__('Port')" wire:model="form.{{ $end }}_port" maxlength="100" :placeholder="__('ex. 12')" wire:key="cable-port-{{ $end }}-text"/>
                            @endif
                        </div>
                    </fieldset>
                @endforeach
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="form.medium" label="Type" wire:model="form.medium">
                        @foreach (\App\Models\CableLink::MEDIA as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                    </x-field>
                    <x-field name="form.label" :label="__('Repère')" wire:model="form.label" maxlength="100" :placeholder="__('ex. fibre F12')"/>
                </div>
                <div class="dialog-actions">
                    <x-button variant="secondary" wire:click="close">{{ __('Annuler') }}</x-button>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Enregistrer') }}</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
