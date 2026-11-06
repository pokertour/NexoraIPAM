<?php

namespace App\Livewire;

use App\Models\Location;
use App\Models\NetworkDevice;
use App\Support\DevicePollQueue;
use App\Support\IpNetwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class NetworkDevices extends Component
{
    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public bool $deleting = false;

    #[Url(except: '')]
    public string $search = '';

    public array $form = [];

    public function open(?int $id = null): void
    {
        Gate::authorize('administer');
        $this->close();
        $device = $id ? NetworkDevice::findOrFail($id) : null;
        $credentials = $device?->credentials ?? [];
        $this->recordId = $device?->id;
        $this->form = [
            'name' => $device?->name ?? '', 'host' => $device?->host ?? '', 'port' => $device?->port ?? 161,
            'snmp_version' => $device?->snmp_version ?? '2c', 'location_id' => $device?->location_id, 'description' => $device?->description ?? '',
            'poll_interval' => $device?->poll_interval ?? 15,
            'community' => '', 'username' => $credentials['username'] ?? '', 'security_level' => $credentials['security_level'] ?? 'authPriv',
            'auth_protocol' => $credentials['auth_protocol'] ?? 'SHA', 'auth_password' => '',
            'privacy_protocol' => $credentials['privacy_protocol'] ?? 'AES', 'privacy_password' => '',
        ];
        $this->editing = true;
    }

    public function close(): void
    {
        $this->reset('editing', 'deleting', 'recordId', 'form');
        $this->resetValidation();
    }

    public function save(DevicePollQueue $queue): void
    {
        Gate::authorize('administer');
        abort_unless($this->editing, 409);
        $device = $this->recordId ? NetworkDevice::findOrFail($this->recordId) : new NetworkDevice;
        $stored = $device->credentials ?? [];
        $this->form['host'] = strtolower(trim((string) ($this->form['host'] ?? '')));
        $this->form['location_id'] = filled($this->form['location_id'] ?? null) ? $this->form['location_id'] : null;
        $this->form['poll_interval'] = filled($this->form['poll_interval'] ?? null) ? $this->form['poll_interval'] : null;
        $v3 = ($this->form['snmp_version'] ?? null) === '3';
        $level = $this->form['security_level'] ?? 'authPriv';
        $keep = fn (string $key): bool => blank($this->form[$key] ?? null) && filled($stored[$key] ?? null);

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.host' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! IpNetwork::address((string) $value) && ! filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                    $fail(__('Saisissez une adresse IP ou un nom d’hôte valide.'));
                }
            }],
            'form.port' => ['required', 'integer', 'between:1,65535'],
            'form.snmp_version' => ['required', Rule::in(array_keys(NetworkDevice::VERSIONS))],
            'form.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'form.description' => ['nullable', 'string', 'max:255'],
            'form.poll_interval' => ['nullable', 'integer', 'between:5,10080'],
            'form.community' => [Rule::requiredIf(! $v3 && ! $keep('community')), 'nullable', 'string', 'max:255'],
            'form.username' => [Rule::requiredIf($v3), 'nullable', 'string', 'max:255'],
            'form.security_level' => [Rule::requiredIf($v3), Rule::in(array_keys(NetworkDevice::SECURITY_LEVELS))],
            'form.auth_protocol' => [Rule::in(NetworkDevice::AUTH_PROTOCOLS)],
            'form.auth_password' => [Rule::requiredIf($v3 && $level !== 'noAuthNoPriv' && ! $keep('auth_password')), 'nullable', 'string', 'min:8', 'max:255'],
            'form.privacy_protocol' => [Rule::in(NetworkDevice::PRIVACY_PROTOCOLS)],
            'form.privacy_password' => [Rule::requiredIf($v3 && $level === 'authPriv' && ! $keep('privacy_password')), 'nullable', 'string', 'min:8', 'max:255'],
        ])['form'];

        $credentials = $v3 ? [
            'username' => $data['username'], 'security_level' => $level,
            'auth_protocol' => $data['auth_protocol'], 'auth_password' => $data['auth_password'] ?: ($stored['auth_password'] ?? null),
            'privacy_protocol' => $data['privacy_protocol'], 'privacy_password' => $data['privacy_password'] ?: ($stored['privacy_password'] ?? null),
        ] : ['community' => $data['community'] ?: ($stored['community'] ?? null)];

        $intervalChanged = $device->poll_interval !== ($data['poll_interval'] === null ? null : (int) $data['poll_interval']);
        $device->fill([
            'name' => $data['name'], 'host' => $data['host'], 'port' => (int) $data['port'], 'snmp_version' => $data['snmp_version'],
            'location_id' => $data['location_id'], 'description' => $data['description'] ?: null,
            'poll_interval' => $data['poll_interval'] === null ? null : (int) $data['poll_interval'],
        ]);
        if ($credentials != $stored) {
            $device->credentials = $credentials;
        }
        if ($intervalChanged || ! $device->exists) {
            $device->next_poll_at = $device->poll_interval ? now()->addMinutes($device->poll_interval) : null;
        }
        $created = ! $device->exists;
        $device->save();

        $this->close();
        if ($created) {
            $queue->enqueue($device->id);
        }
        $this->dispatch('nexora-toast', tone: 'success', message: $created ? __('Équipement ajouté. Première interrogation en file d’attente.') : __('Entrée enregistrée.'));
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
            ->orderBy('name')->get();

        return view('livewire.network-devices', [
            'devices' => $devices,
            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
            'polling' => $devices->contains(fn (NetworkDevice $device): bool => $device->pollPending()),
        ])->layout('components.layouts.app', ['title' => __('Équipements')]);
    }
}
