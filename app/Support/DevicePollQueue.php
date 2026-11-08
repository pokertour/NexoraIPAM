<?php

namespace App\Support;

use App\Jobs\PollNetworkDevice;
use App\Models\NetworkDevice;
use App\Models\ScanRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DevicePollQueue
{
    public function enqueue(int $deviceId): ?string
    {
        return DB::transaction(function () use ($deviceId): ?string {
            $device = NetworkDevice::lockForUpdate()->find($deviceId);
            if (! $device) {
                return __('Cet équipement n’existe plus.');
            }
            if ($device->pollPending()) {
                return __('Une interrogation est déjà en cours pour cet équipement.');
            }

            $token = (string) Str::uuid();
            $device->forceFill(['poll_token' => $token, 'poll_status' => 'queued', 'poll_requested_at' => now()])->save();
            Audit::record('device.queued', $device);
            $run = ScanRun::queue('snmp', $device);
            Bus::dispatch((new PollNetworkDevice($device->id, $token, $run->id))->onConnection('database')->onQueue('discovery')->beforeCommit());

            return null;
        }, attempts: 3);
    }
}
