<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AuditTrail extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $subject = '';

    #[Url(except: '')]
    public string $user = '';

    public function mount(): void
    {
        Gate::authorize('administer');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'action', 'subject', 'user'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        Gate::authorize('administer');
        $search = mb_substr(trim($this->search), 0, 100);

        $entries = AuditLog::query()
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('subject_label', '%'.$search.'%')->orWhereLike('actor', '%'.$search.'%')
                ->orWhereLike('ip_address', '%'.$search.'%')->orWhereLike('changes', '%'.$search.'%')))
            ->when(isset(AuditLog::ACTIONS[$this->action]), fn (Builder $query) => $query->where('action', $this->action))
            ->when(isset(AuditLog::SUBJECTS[$this->subject]), fn (Builder $query) => $query->where('subject_type', $this->subject))
            ->when($this->user === 'system', fn (Builder $query) => $query->whereNull('user_id'))
            ->when(ctype_digit($this->user), fn (Builder $query) => $query->where('user_id', (int) $this->user))
            ->latest('id')
            ->paginate(25);

        return view('livewire.audit-trail', [
            'entries' => $entries,
            'users' => User::orderBy('name')->get(['id', 'name']),
        ])->layout('components.layouts.app', ['title' => __('Journal d’audit')]);
    }
}
