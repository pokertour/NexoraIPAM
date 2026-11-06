<?php

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Support\AddressHistory;
use App\Support\AlertMonitor;
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

    public function handle(NetworkDiscovery $discovery, AddressHistory $history, AlertMonitor $monitor): void
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

        /** @var list<array{0: string, 1: IpAddress, 2: array<string, mixed>, 3: string}> $events */
        $events = [];
        $completed = DB::transaction(function () use ($results, $history, &$events): bool {
            $events = [];
            $subnet = Subnet::lockForUpdate()->find($this->subnetId);
            if ($subnet?->scan_token !== $this->token) {
                return false;
            }

            $timestamp = now();
            $responded = 0;
            foreach ($results as $address => $result) {
                $record = $subnet->addresses()->where('address', $address)->first();
                $known = $record !== null;
                $previouslyResponded = $record?->last_probe_responded;
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
                if ($result['reachable']) {
                    $previousMac = $history->observe($record, $result['mac'] ?? null, $result['hostname'], 'icmp', $timestamp);
                    $events[] = ['resolve', $record, [], 'host_down'];
                    if (! $known) {
                        $events[] = ['raise', $record, ['subnet' => $subnet->cidr], 'unknown_host'];
                    } elseif ($record->status === 'available') {
                        $events[] = ['raise', $record, [], 'status_conflict'];
                    }
                    if ($previousMac !== null) {
                        $events[] = ['raise', $record, ['old' => $previousMac, 'new' => $record->mac_address], 'mac_changed'];
                    }
                } elseif ($record && $previouslyResponded === true && $record->status === 'used') {
                    $events[] = ['raise', $record, [], 'host_down'];
                }
            }

            Audit::record('discovery.completed', $subnet, ['targets' => [null, count($results)], 'responded' => [null, $responded]]);
            $subnet->forceFill([
                'scan_status' => 'completed', 'scanned_at' => $timestamp,
                'scan_targets' => count($results), 'scan_responded' => $responded,
            ])->save();

            return true;
        }, attempts: 3);

        if (! $completed) {
            return;
        }

        $settings = AlertMonitor::settings();
        foreach ($events as [$action, $record, $details, $type]) {
            if ($action === 'resolve') {
                $monitor->resolve($type, $record);
            } elseif ($type === 'host_down' ? $settings['host_down'] : $settings['conflicts']) {
                $monitor->raise($type, $record, $details, $type === 'mac_changed' || $type === 'status_conflict' ? 'critical' : 'warning');
            }
        }
        $monitor->checkCapacity(Subnet::findOrFail($this->subnetId));
    }

    public function failed(?Throwable $exception): void
    {
        Subnet::whereKey($this->subnetId)->where('scan_token', $this->token)
            ->whereIn('scan_status', ['queued', 'running'])->update(['scan_status' => 'failed']);
    }
}
