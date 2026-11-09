<?php

namespace App\Livewire\Concerns;

use App\Models\NetworkDevice;
use App\Support\DeviceApi\DeviceApis;
use App\Support\DevicePollQueue;
use App\Support\IpNetwork;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * Add and edit form of a network device, shared by the device list and the
 * device page. The component defines close(), which calls resetDeviceForm(),
 * and includes the livewire.partials.device-form view with $locations.
 */
trait EditsNetworkDevice
{
    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public bool $hasApiPassword = false;

    public array $form = [];

    public function open(?int $id = null): void
    {
        Gate::authorize('administer');
        $this->close();
        $device = $id ? NetworkDevice::findOrFail($id) : null;
        $credentials = $device?->credentials ?? [];
        $this->recordId = $device?->id;
        $this->form = [
            'name' => $device?->name ?? '', 'role' => $device?->role ?? 'other', 'host' => $device?->host ?? '', 'port' => $device?->port ?? 161,
            'snmp_version' => $device?->snmp_version ?? '2c', 'location_id' => $device?->location_id, 'description' => $device?->description ?? '',
            'poll_interval' => $device?->poll_interval ?? 15,
            'community' => '', 'username' => $credentials['username'] ?? '', 'security_level' => $credentials['security_level'] ?? 'authPriv',
            'auth_protocol' => $credentials['auth_protocol'] ?? 'SHA', 'auth_password' => '',
            'privacy_protocol' => $credentials['privacy_protocol'] ?? 'AES', 'privacy_password' => '',
            'api_driver' => $device?->api_driver ?? '', 'api_port' => $device?->api_port, 'api_verify_tls' => $device?->api_verify_tls ?? true,
            'api_username' => $device?->api_credentials['username'] ?? '', 'api_password' => '',
        ];
        $this->hasApiPassword = filled($device?->api_credentials['password'] ?? null);
        $this->editing = true;
    }

    /** A port typed for one API rarely fits another: changing the API goes back to its default port. */
    public function updatedFormApiDriver(): void
    {
        $this->form['api_port'] = null;
        $this->resetValidation('form.api_port');
    }

    protected function resetDeviceForm(): void
    {
        $this->reset('editing', 'recordId', 'form', 'hasApiPassword');
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
        $this->form['api_driver'] = filled($this->form['api_driver'] ?? null) ? $this->form['api_driver'] : null;
        $this->form['api_port'] = filled($this->form['api_port'] ?? null) ? $this->form['api_port'] : null;
        $api = $this->form['api_driver'] !== null;
        $storedApi = $device->api_credentials ?? [];
        $keepApiPassword = blank($this->form['api_password'] ?? null) && filled($storedApi['password'] ?? null);

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.role' => ['required', Rule::in(array_keys(NetworkDevice::ROLES))],
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
            'form.api_driver' => ['nullable', Rule::in(array_keys(DeviceApis::DRIVERS))],
            'form.api_port' => ['nullable', 'integer', 'between:1,65535'],
            'form.api_verify_tls' => ['boolean'],
            'form.api_username' => [Rule::requiredIf($api), 'nullable', 'string', 'max:255'],
            'form.api_password' => [Rule::requiredIf($api && ! $keepApiPassword), 'nullable', 'string', 'max:255'],
        ])['form'];

        $credentials = $v3 ? [
            'username' => $data['username'], 'security_level' => $level,
            'auth_protocol' => $data['auth_protocol'], 'auth_password' => $data['auth_password'] ?: ($stored['auth_password'] ?? null),
            'privacy_protocol' => $data['privacy_protocol'], 'privacy_password' => $data['privacy_password'] ?: ($stored['privacy_password'] ?? null),
        ] : ['community' => $data['community'] ?: ($stored['community'] ?? null)];

        $intervalChanged = $device->poll_interval !== ($data['poll_interval'] === null ? null : (int) $data['poll_interval']);
        $device->fill([
            'name' => $data['name'], 'role' => $data['role'], 'host' => $data['host'], 'port' => (int) $data['port'], 'snmp_version' => $data['snmp_version'],
            'location_id' => $data['location_id'], 'description' => $data['description'] ?: null,
            'poll_interval' => $data['poll_interval'] === null ? null : (int) $data['poll_interval'],
            'api_driver' => $data['api_driver'],
            // An empty port follows the default port of the chosen API.
            'api_port' => $api && $data['api_port'] !== null && (int) $data['api_port'] !== DeviceApis::DRIVERS[$data['api_driver']]['port'] ? (int) $data['api_port'] : null,
            'api_verify_tls' => (bool) ($data['api_verify_tls'] ?? true),
        ]);
        $apiCredentials = $api ? ['username' => $data['api_username'], 'password' => $data['api_password'] ?: ($storedApi['password'] ?? null)] : null;
        if ($apiCredentials != ($device->api_credentials ?: null)) {
            $device->api_credentials = $apiCredentials;
        }
        if (! $api) {
            $device->api_error = null;
        }
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
}
