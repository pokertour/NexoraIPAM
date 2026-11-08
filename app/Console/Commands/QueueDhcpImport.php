<?php

namespace App\Console\Commands;

use App\Jobs\ImportDhcpLeases;
use App\Models\Setting;
use App\Support\DhcpLeases;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class QueueDhcpImport extends Command
{
    protected $signature = 'nexora:import-dhcp {--now : Importer sans attendre l’intervalle}';

    protected $description = 'Importe les baux DHCP lorsque l’intervalle configuré est écoulé';

    public function handle(): int
    {
        $settings = DhcpLeases::settings();
        if (! $settings['enabled']) {
            return self::SUCCESS;
        }

        $lastRun = Setting::read('dhcp_state')['last_run'] ?? null;
        if (! $this->option('now') && $lastRun && Carbon::parse($lastRun)->addMinutes(max(1, (int) $settings['interval']))->isFuture()) {
            return self::SUCCESS;
        }

        Setting::write('dhcp_state', ['last_run' => now()->toIso8601String()] + Setting::read('dhcp_state'));
        ImportDhcpLeases::dispatch()->onConnection('database')->onQueue('discovery');

        return self::SUCCESS;
    }
}
