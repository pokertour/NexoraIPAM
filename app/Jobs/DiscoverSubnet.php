<?php

namespace App\Jobs;

use App\Models\Subnet;
use App\Support\Audit;
use App\Support\NetworkDiscovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class DiscoverSubnet implements ShouldQueue
{
    use Queueable;

    public int $timeout = 65;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $subnetId, public string $token) {}

    public function handle(NetworkDiscovery $discovery): void
    {
        $started = Subnet::whereKey($this->subnetId)->where('scan_token', $this->token)
            ->where('scan_status', 'queued')
            ->update(['scan_status' => 'running']);
        if (! $started) {
            return;
        }

        $subnet = Subnet::find($this->subnetId);
        if (! $subnet) {
            return;
        }

        $results = $discovery->probe($discovery->targets($subnet));

        DB::transaction(function () use ($results): void {
            $subnet = Subnet::lockForUpdate()->find($this->subnetId);
            if ($subnet?->scan_token !== $this->token) {
                return;
            }

            $timestamp = now();
            $responded = 0;
            foreach ($results as $address => $result) {
                $record = $subnet->addresses()->where('address', $address)->first();
                if ($result['reachable']) {
                    $responded++;
                    $record ??= $subnet->addresses()->make(['address' => $address, 'status' => 'used']);
                    $record->last_seen_at = $timestamp;
                    $record->resolved_hostname = $result['hostname'];
                    $record->mac_address = $result['mac'] ?? $record->mac_address;
                }
                if ($record) {
                    $record->last_probe_at = $timestamp;
                    $record->last_probe_responded = $result['reachable'];
                    $record->save();
                }
            }

            Audit::record('discovery.completed', $subnet, ['targets' => [null, count($results)], 'responded' => [null, $responded]]);
            $subnet->forceFill([
                'scan_status' => 'completed', 'scanned_at' => $timestamp,
                'scan_targets' => count($results), 'scan_responded' => $responded,
            ])->save();
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        Subnet::whereKey($this->subnetId)->where('scan_token', $this->token)
            ->whereIn('scan_status', ['queued', 'running'])->update(['scan_status' => 'failed']);
    }
}
