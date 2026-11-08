<?php

namespace App\Livewire;

use App\Models\ScanRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ScanHistory extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['kind', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $search = mb_substr(trim($this->search), 0, 100);

        $runs = ScanRun::query()->with(['subject', 'user:id,name'])
            ->when(isset(ScanRun::KINDS[$this->kind]), fn (Builder $query) => $query->where('kind', $this->kind))
            ->when(isset(ScanRun::STATUSES[$this->status]), fn (Builder $query) => $query->where('status', $this->status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('subject_label', '%'.$search.'%')->orWhereLike('error', '%'.$search.'%')))
            ->latest('id')
            ->paginate(25);

        $counts = ScanRun::selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        return view('livewire.scan-history', [
            'runs' => $runs,
            'counts' => $counts,
            'pending' => $runs->getCollection()->contains(fn (ScanRun $run): bool => in_array($run->status, ['queued', 'running'], true)),
        ])->layout('components.layouts.app', ['title' => __('Historique des scans')]);
    }
}
