<?php

namespace App\Livewire;

use App\Models\Setting;
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

    public function mount(): void
    {
        Gate::authorize('administer');
        $this->local = Setting::read('local') + ['enabled' => true];
        $this->ldap = $this->display('ldap', ['uri' => '', 'base_dn' => '', 'group_dn' => '', 'bind_dn' => '', 'password' => ''], 'password');
        $this->oidc = $this->display('oidc', ['issuer' => '', 'client_id' => '', 'secret' => ''], 'secret');
        $this->smtp = $this->display('smtp', ['scheme' => 'smtps', 'host' => '', 'port' => 465, 'username' => '', 'password' => '', 'from' => ''], 'password');
    }

    public function save(string $section): void
    {
        Gate::authorize('administer');

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
            ],
            'smtp' => [
                'scheme' => ['required', 'in:smtp,smtps'],
                'host' => [$required, 'nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.-]+$/D'],
                'port' => [$required, 'nullable', 'integer', 'between:1,65535'],
                'username' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'max:1024'],
                'from' => [$required, 'nullable', 'email', 'max:255'],
            ],
            default => abort(404),
        };

        $input = $this->{$section};
        $stored = Setting::read($section);
        $secret = $section === 'oidc' ? 'secret' : 'password';
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

        Setting::write($section, $data);
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
            Log::warning('Échec du test SMTP', ['type' => $exception::class]);
            $this->dispatch('nexora-toast', tone: 'error', message: __('Le serveur SMTP a refusé le test. Vérifiez les paramètres enregistrés.'));

            return;
        }

        $this->dispatch('nexora-toast', tone: 'success', message: __('Message de test envoyé à :email.', ['email' => auth()->user()->email]));
    }

    private function display(string $key, array $defaults, string $secret): array
    {
        return [$secret => ''] + Setting::read($key) + ['enabled' => false] + $defaults;
    }

    public function render(): View
    {
        Gate::authorize('administer');

        return view('livewire.preferences')->layout('components.layouts.app', ['title' => __('Paramètres')]);
    }
}
