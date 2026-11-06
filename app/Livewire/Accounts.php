<?php

namespace App\Livewire;

use App\Console\Commands\InstallAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Accounts extends Component
{
    use WithPagination;

    public const ROLES = ['admin' => 'Administrateur', 'operator' => 'Opérateur', 'viewer' => 'Lecteur'];

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public string $provider = 'local';

    public array $form = [];

    public function open(?int $id = null): void
    {
        Gate::authorize('administer');
        $this->resetValidation();
        $this->recordId = $id;
        $user = $id === null ? null : User::findOrFail($id);
        if ($user !== null) {
            $this->authorizeAccount($user);
        }
        $this->provider = $user?->provider ?? 'local';
        $this->form = $user?->only('name', 'email', 'role', 'active') ?? [
            'name' => '', 'email' => '', 'role' => 'viewer', 'active' => true,
        ];
        $this->form['password'] = '';
        $this->editing = true;
    }

    public function close(): void
    {
        $this->reset('recordId', 'editing', 'provider', 'form');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('administer');
        abort_unless($this->editing, 409);
        $user = $this->recordId === null ? new User : User::findOrFail($this->recordId);
        $this->authorizeAccount($user);
        $protected = $user->email === InstallAdmin::EMAIL || $user->id === auth()->id();
        $provider = $user->provider;
        $email = $this->form['email'] ?? null;
        $this->form['email'] = $user->email === InstallAdmin::EMAIL ? $user->email : (is_string($email) ? strtolower(trim($email)) : $email);
        if ($protected) {
            $this->form['role'] = $user->role;
            $this->form['active'] = true;
        }

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user), Rule::notIn($user->email === InstallAdmin::EMAIL ? [] : [InstallAdmin::EMAIL])],
            'form.provider' => ['prohibited'],
            'form.subject' => ['prohibited'],
            'form.external_id' => ['prohibited'],
            'form.role' => ['required', Rule::in(array_keys(self::ROLES))],
            'form.active' => ['required', 'boolean'],
            'form.password' => [Rule::requiredIf(! $user->exists), 'nullable', 'string', Password::min(12), 'max:72'],
        ])['form'];

        if ($provider === 'local' && $user->id !== auth()->id() && filled($data['password'] ?? null)) {
            $data['must_change_password'] = true;
        } else {
            unset($data['password']);
        }

        unset($data['provider'], $data['subject'], $data['external_id']);
        $user->fill($data)->save();
        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Compte enregistré.'));
    }

    private function authorizeAccount(User $user): void
    {
        abort_if($user->email === InstallAdmin::EMAIL && $user->id !== auth()->id(), 403);
    }

    public function render(): View
    {
        Gate::authorize('administer');

        return view('livewire.accounts', ['accounts' => User::orderBy('name')->orderBy('id')->paginate(15)])
            ->layout('components.layouts.app', ['title' => __('Utilisateurs')]);
    }
}
