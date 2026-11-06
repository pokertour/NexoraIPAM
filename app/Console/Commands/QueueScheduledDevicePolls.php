<?php

namespace App\Console\Commands;

use App\Models\NetworkDevice;
use App\Support\DevicePollQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueScheduledDevicePolls extends Command
{
    protected $signature = 'nexora:poll-devices';

    protected $description = 'Planifie les interrogations SNMP arrivées à échéance';

    public function handle(DevicePollQueue $queue): int
    {
        $now = now();
        NetworkDevice::where('next_poll_at', '<=', $now)->select('id')->eachById(function (NetworkDevice $candidate) use ($queue, $now): void {
            $due = DB::transaction(function () use ($candidate, $now): bool {
                $device = NetworkDevice::where('next_poll_at', '<=', $now)->lockForUpdate()->find($candidate->id);
                if (! $device?->poll_interval) {
                    return false;
                }
                $device->forceFill(['next_poll_at' => $now->copy()->addMinutes($device->poll_interval)])->save();

                return true;
            }, attempts: 3);

            if ($due) {
                $queue->enqueue($candidate->id);
            }
        });

        return self::SUCCESS;
    }
}
