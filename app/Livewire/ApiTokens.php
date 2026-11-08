<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Administrators create, review and revoke the API tokens of every account,
 * and follow what each token did through the audit trail.
 */
class ApiTokens extends Component
{
    use WithPagination;

    #[Locked]
    public bool $creating = false;

    #[Locked]
    public ?int $revoking = null;

    /** Shown once right after creation, never stored. */
    #[Locked]
    public ?string $plainToken = null;

    #[Url(except: '')]
    public string $token = '';

    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('administer');
    }

    public function open(): void
    {
        Gate::authorize('administer');
        $this->close();
        $this->form = ['user_id' => null, 'name' => '', 'write' => false, 'days' => 365];
        $this->creating = true;
    }

    public function close(): void
    {
        $this->reset('creating', 'revoking', 'plainToken', 'form');
        $this->resetValidation();
    }

    public function create(): void
    {
        Gate::authorize('administer');
        abort_unless($this->creating, 409);
        $this->form['days'] = filled($this->form['days'] ?? null) ? $this->form['days'] : null;
        $data = $this->validate([
            'form.user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'form.name' => ['required', 'string', 'max:100'],
            'form.write' => ['boolean'],
            'form.days' => ['nullable', 'integer', 'between:1,3650'],
        ])['form'];

        $owner = User::findOrFail($data['user_id']);
        $abilities = $data['write'] ? ['read', 'write'] : ['read'];
        $expires = $data['days'] ? now()->addDays((int) $data['days']) : null;
        $token = $owner->createToken($data['name'], $abilities, $expires);
        Audit::record('api_token.created', $owner, [
            'name' => [null, $data['name']], 'abilities' => [null, implode(', ', $abilities)],
            'expires_at' => [null, $expires?->format('Y-m-d')],
        ]);

        $this->reset('creating', 'form');
        $this->plainToken = $token->plainTextToken;
    }

    public function confirmRevoke(int $id): void
    {
        Gate::authorize('administer');
        $this->close();
        $this->revoking = PersonalAccessToken::findOrFail($id)->id;
    }

    public function revoke(): void
    {
        Gate::authorize('administer');
        abort_unless($this->revoking !== null, 409);
        $token = PersonalAccessToken::with('tokenable')->findOrFail($this->revoking);
        $token->delete();
        Audit::record('api_token.revoked', $token->tokenable, ['name' => [$token->name, null]]);
        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Jeton révoqué.'));
    }

    public function updatedToken(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('administer');
        $tokens = PersonalAccessToken::with('tokenable')->latest('id')->get();
        $selected = ctype_digit($this->token) ? (int) $this->token : null;
        $selectedToken = $selected ? $tokens->firstWhere('id', $selected) : null;

        $history = AuditLog::query()
            ->where(fn (Builder $query) => $query->whereNotNull('api_token_id')->orWhereLike('action', 'api_token.%'))
            ->when($selected, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('api_token_id', $selected)
                ->orWhere(fn (Builder $query) => $query->whereLike('action', 'api_token.%')->whereLike('changes', '%'.json_encode($selectedToken?->name ?? "\0").'%'))))
            ->latest('id')->paginate(20);

        return view('livewire.api-tokens', [
            'tokens' => $tokens,
            'history' => $history,
            'users' => $this->creating ? User::where('active', true)->orderBy('name')->get() : collect(),
            'roles' => Accounts::ROLES,
        ])->layout('components.layouts.app', ['title' => __('Jetons d’API')]);
    }
}
