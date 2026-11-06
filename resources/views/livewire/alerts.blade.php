<div>
    <div class="page-heading">
        <div><p class="eyebrow">{{ __('SURVEILLANCE') }}</p><h1 class="page-title mt-2">{{ __('Alertes') }}</h1><p class="muted mt-2">{{ __('Seuils d’occupation, conflits d’adresses et hôtes injoignables détectés par les découvertes.') }}</p></div>
        @if ($currentState === 'active' && $counts['active'] > 0)
            @can('edit-inventory')
                <x-button variant="secondary" wire:click="acknowledgeAll" wire:loading.attr="disabled" wire:target="acknowledgeAll">{{ __('Tout acquitter') }}</x-button>
            @endcan
        @endif
    </div>
    <section class="card">
        <div class="table-toolbar">
            <nav class="segmented" aria-label="{{ __('État des alertes') }}">
                @foreach (\App\Livewire\Alerts::STATES as $value => $label)
                    <button type="button" @class(['active' => $currentState === $value]) wire:click="$set('state', '{{ $value }}')" @if ($currentState === $value) aria-current="true" @endif>
                        {{ __($label) }} <span class="count-pill">{{ $counts[$value] }}</span>
                    </button>
                @endforeach
            </nav>
        </div>
        @if ($alerts->isEmpty())
            <x-empty-state icon="bell" :title="__('Aucune alerte')" :text="__('Tout est calme sur le réseau.')"/>
        @else
            <ul class="alert-list">
                @foreach ($alerts as $alert)<x-alert-row :alert="$alert"/>@endforeach
            </ul>
            @if ($alerts->hasPages())<div class="pagination">{{ $alerts->links() }}</div>@endif
        @endif
    </section>
</div>
