<?php

namespace App\Livewire;

use App\Models\Alert;
use App\Support\AlertMonitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Alerts extends Component
{
    use WithPagination;

    /** @var array<string, string> */
    public const STATES = ['active' => 'Actives', 'acknowledged' => 'Acquittées', 'resolved' => 'Résolues'];

    #[Url(except: 'active')]
    public string $state = 'active';

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function acknowledge(int $id, AlertMonitor $monitor): void
    {
        Gate::authorize('edit-inventory');
        $monitor->acknowledge(Alert::findOrFail($id));
        $this->dispatch('nexora-toast', tone: 'success', message: __('Alerte acquittée.'));
    }

    public function acknowledgeAll(AlertMonitor $monitor): void
    {
        Gate::authorize('edit-inventory');
        Alert::active()->each(fn (Alert $alert) => $monitor->acknowledge($alert));
        $this->dispatch('nexora-toast', tone: 'success', message: __('Alertes acquittées.'));
    }

    public function render(): View
    {
        $state = isset(self::STATES[$this->state]) ? $this->state : 'active';

        return view('livewire.alerts', [
            'alerts' => Alert::query()->with('acknowledgedBy')
                ->when($state === 'active', fn (Builder $query) => $query->active())
                ->when($state === 'acknowledged', fn (Builder $query) => $query->whereNull('resolved_at')->whereNotNull('acknowledged_at'))
                ->when($state === 'resolved', fn (Builder $query) => $query->whereNotNull('resolved_at'))
                ->orderByRaw("case severity when 'critical' then 0 else 1 end")->latest('last_seen_at')->latest('id')
                ->paginate(20),
            'counts' => [
                'active' => Alert::active()->count(),
                'acknowledged' => Alert::whereNull('resolved_at')->whereNotNull('acknowledged_at')->count(),
                'resolved' => Alert::whereNotNull('resolved_at')->count(),
            ],
            'currentState' => $state,
        ])->layout('components.layouts.app', ['title' => __('Alertes')]);
    }
}
