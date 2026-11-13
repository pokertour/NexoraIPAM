<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsNetworkDevice;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Support\DevicePollQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class NetworkDevices extends Component
{
    use EditsNetworkDevice;

    #[Locked]
    public bool $deleting = false;

    #[Url(except: '')]
    public string $search = '';

    /** Location whose devices are listed, sub-locations included. */
    #[Url(except: '')]
    public string $location = '';

    public function close(): void
    {
        $this->resetDeviceForm();
        $this->reset('deleting');
    }

    public function poll(int $id, DevicePollQueue $queue): void
    {
        Gate::authorize('administer');
        $message = $queue->enqueue(NetworkDevice::findOrFail($id)->id);
        $this->dispatch('nexora-toast', message: $message ?? __('Interrogation mise en file d’attente.'), tone: $message ? 'info' : 'success');
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('administer');
        $this->close();
        $this->recordId = NetworkDevice::findOrFail($id)->id;
        $this->deleting = true;
    }

    public function delete(): void
    {
        Gate::authorize('administer');
        abort_unless($this->deleting && $this->recordId, 409);
        NetworkDevice::findOrFail($this->recordId)->delete();
        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Entrée supprimée.'));
    }

    public function render(): View
    {
        $search = mb_substr(trim($this->search), 0, 100);
        $devices = NetworkDevice::with(['location.parent.parent', 'ports' => fn ($query) => $query->where('physical', true)])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereLike('name', '%'.$search.'%')
                ->orWhereLike('host', '%'.$search.'%')->orWhereLike('sys_name', '%'.$search.'%')->orWhereLike('sys_descr', '%'.$search.'%')))
            ->when(ctype_digit($this->location), fn (Builder $query) => $query->whereIn('location_id', Location::findOrFail($this->location)->descendantsAndSelf()))
            ->orderBy('name')->get();

        return view('livewire.network-devices', [
            'devices' => $devices,
            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
            'polling' => $devices->contains(fn (NetworkDevice $device): bool => $device->pollPending()),
        ])->layout('components.layouts.app', ['title' => __('Équipements')]);
    }
}
