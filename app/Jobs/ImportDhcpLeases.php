<?php

namespace App\Jobs;

use App\Models\ScanRun;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\DhcpLeases;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ImportDhcpLeases implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public ?int $runId = null) {}

    public function handle(DhcpLeases $leases): void
    {
        ScanRun::markRunning($this->runId);
        try {
            $summary = $leases->import();
        } catch (RuntimeException $exception) {
            Setting::write('dhcp_state', ['last_run' => now()->toIso8601String(), 'error' => $exception->getMessage()]);
            Audit::record('dhcp.imported', changes: ['result' => [null, 'failed'], 'error' => [null, $exception->getMessage()]], subjectType: 'settings', subjectLabel: 'dhcp');
            ScanRun::finish($this->runId, 'failed', error: $exception->getMessage());

            return;
        }

        Setting::write('dhcp_state', ['last_run' => now()->toIso8601String(), 'error' => null] + $summary);
        Audit::record('dhcp.imported', changes: array_map(fn (int $value): array => [null, $value], $summary), subjectType: 'settings', subjectLabel: 'dhcp');
        ScanRun::finish($this->runId, 'completed', $summary);
    }

    public function failed(?Throwable $exception): void
    {
        ScanRun::finish($this->runId, 'failed', error: $exception?->getMessage() ?: __('L’import DHCP a échoué ou dépassé le délai.'));
    }
}
