<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Support\AlertMonitor;
use App\Support\Audit;
use App\Support\DhcpLeases;
use App\Support\OpenIdClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

class Preferences extends Component
{
    public array $local = [];

    public array $ldap = [];

    public array $oidc = [];

    public array $smtp = [];

    public array $alerts = [];

    public array $dhcp = [];

    public function mount(): void
    {
        Gate::authorize('administer');
        $this->local = Setting::read('local') + ['enabled' => true];
        $this->ldap = $this->display('ldap', ['uri' => '', 'base_dn' => '', 'group_dn' => '', 'bind_dn' => '', 'password' => ''], 'password');
        $this->oidc = $this->display('oidc', ['issuer' => '', 'client_id' => '', 'secret' => '', 'scopes' => OpenIdClient::DEFAULT_SCOPES], 'secret');
        $this->alerts = AlertMonitor::settings();
        $this->dhcp = $this->display('dhcp', ['kea_url' => '', 'kea_username' => '', 'kea_password' => '', 'leases_file' => '', 'interval' => 15], 'kea_password');
        $this->smtp = $this->display('smtp', ['scheme' => 'smtps', 'host' => '', 'port' => 465, 'username' => '', 'password' => '', 'from' => ''], 'password');
    }

    public function save(string $section): void
    {
        Gate::authorize('administer');
        $this->resetValidation();

        $required = Rule::requiredIf(fn (): bool => (bool) ($this->{$section}['enabled'] ?? false));
        $rules = match ($section) {
            'local' => [],
            'ldap' => [
                'uri' => [$required, 'nullable', 'max:255', 'regex:~^ldaps://(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$~iD'],
                'base_dn' => [$required, 'nullable', 'string', 'max:255'],
                'group_dn' => [$required, 'nullable', 'string', 'max:1024'],
                'bind_dn' => [$required, 'nullable', 'string', 'max:255'],
                'password' => [$required, 'nullable', 'string', 'max:1024'],
            ],
            'oidc' => [
                'issuer' => [$required, 'nullable', 'url:https', 'max:255', 'not_regex:/[?#]/'],
                'client_id' => [$required, 'nullable', 'string', 'max:255'],
                'secret' => [$required, 'nullable', 'string', 'max:1024'],
                // Scope tokens as defined by OAuth 2.0 (RFC 6749), separated by spaces or commas.
                'scopes' => ['nullable', 'string', 'max:500', 'regex:/^[\x21\x23-\x5B\x5D-\x7E\s,]*$/'],
            ],
            'smtp' => [
                'scheme' => ['required', 'in:smtp,smtps,none'],
                'host' => [$required, 'nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.-]+$/D'],
                'port' => [$required, 'nullable', 'integer', 'between:1,65535'],
                'username' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'max:1024'],
                'from' => [$required, 'nullable', 'email', 'max:255'],
            ],
            'alerts' => [
                'recipients' => [$required, 'nullable', 'string', 'max:1000', function (string $attribute, mixed $value, \Closure $fail): void {
                    foreach (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $email) {
                        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $fail(__('Saisissez des adresses e-mail séparées par des virgules.'));

                            return;
                        }
                    }
                }],
                'threshold' => ['required', 'integer', 'between:1,100'],
                'conflicts' => ['required', 'boolean'],
                'host_down' => ['required', 'boolean'],
            ],
            'dhcp' => [
                'kea_url' => ['nullable', 'url:http,https', 'max:255', Rule::requiredIf(fn (): bool => (bool) ($this->dhcp['enabled'] ?? false) && blank($this->dhcp['leases_file'] ?? null))],
                'kea_username' => ['nullable', 'string', 'max:255'],
                'kea_password' => ['nullable', 'string', 'max:1024'],
                'leases_file' => ['nullable', 'string', 'max:255', 'regex:~^/[^\0]*$~'],
                'interval' => ['required', 'integer', 'between:1,1440'],
            ],
            default => abort(404),
        };

        $input = $this->{$section};
        $stored = Setting::read($section);
        $secret = ['oidc' => 'secret', 'dhcp' => 'kea_password'][$section] ?? 'password';
        if (array_key_exists($secret, $rules)) {
            if (blank($input[$secret] ?? null)) {
                $input[$secret] = $stored[$secret] ?? null;
            }
            $this->{$section}[$secret] = '';
        }

        $rules = collect(['enabled' => ['required', 'boolean']] + $rules)
            ->mapWithKeys(fn (array $rule, string $key): array => [$section.'.'.$key => $rule])->all();
        $data = Validator::make([$section => $input], $rules)->validate()[$section];
        if ($section === 'ldap' && $data['enabled'] && ! extension_loaded('ldap')) {
            $this->addError('ldap.enabled', __('Activez l’extension PHP LDAP sur le serveur.'));

            return;
        }

        if ($section === 'oidc') {
            // Stored as typed by the provider documentation: openid first, separated by spaces.
            $data['scopes'] = implode(' ', ['openid', ...OpenIdClient::scopes($data['scopes'] ?? null)]);
            $this->oidc['scopes'] = $data['scopes'];
        }

        $previous = Setting::read($section);
        Setting::write($section, $data);
        $changes = [];
        foreach ($data as $key => $value) {
            if (($previous[$key] ?? null) !== $value) {
                $secret = in_array($key, ['password', 'secret', 'kea_password'], true);
                $changes[$key] = $secret ? [isset($previous[$key]) ? Audit::MASK : null, Audit::MASK] : [$previous[$key] ?? null, $value];
            }
        }
        Audit::record('settings.updated', changes: $changes, subjectType: 'settings', subjectLabel: $section);
        $this->dispatch('nexora-toast', tone: 'success', message: __('Paramètres enregistrés.'));
    }

    public function testMail(): void
    {
        Gate::authorize('administer');
        $smtp = Setting::read('smtp');
        if (! ($smtp['enabled'] ?? false)) {
            $this->addError('smtp.enabled', __('Activez et enregistrez le SMTP avant de le tester.'));

            return;
        }

        try {
            Mail::mailer('smtp')->raw(__('Le serveur SMTP de NexoraIPAM fonctionne.'), function ($message) use ($smtp): void {
                $message->from($smtp['from'], config('app.name'))
                    ->to(auth()->user()->email)->subject(__('Test SMTP NexoraIPAM'));
            });
        } catch (\Throwable $exception) {
            Audit::record('mail.tested', changes: ['result' => [null, 'failed']], subjectType: 'settings', subjectLabel: 'smtp');
            Log::warning('Échec du test SMTP', ['type' => $exception::class]);
            $this->dispatch('nexora-toast', tone: 'error', message: __('Le serveur SMTP a refusé le test. Vérifiez les paramètres enregistrés.'));

            return;
        }

        Audit::record('mail.tested', changes: ['result' => [null, 'sent']], subjectType: 'settings', subjectLabel: 'smtp');
        $this->dispatch('nexora-toast', tone: 'success', message: __('Message de test envoyé à :email.', ['email' => auth()->user()->email]));
    }

    public function importDhcp(): void
    {
        Gate::authorize('administer');
        if (! DhcpLeases::settings()['enabled']) {
            $this->addError('dhcp.enabled', __('Activez et enregistrez l’import DHCP avant de le lancer.'));

            return;
        }

        $this->dispatch('nexora-toast', tone: 'success', message: __('Import DHCP mis en file d’attente.'));
        Artisan::call('nexora:import-dhcp', ['--now' => true]);
    }

    private function display(string $key, array $defaults, string $secret): array
    {
        return [$secret => ''] + Setting::read($key) + ['enabled' => false] + $defaults;
    }

    public function render(): View
    {
        Gate::authorize('administer');

        return view('livewire.preferences', ['dhcpState' => Setting::read('dhcp_state')])->layout('components.layouts.app', ['title' => __('Paramètres')]);
    }
}
