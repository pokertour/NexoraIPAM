<?php

namespace App\Jobs;

use App\Models\NetworkDevice;
use App\Models\ScanRun;
use App\Support\AlertMonitor;
use App\Support\Audit;
use App\Support\DevicePoller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class PollNetworkDevice implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $deviceId, public string $token, public ?int $runId = null) {}

    public function handle(DevicePoller $poller, AlertMonitor $monitor): void
    {
        $started = NetworkDevice::whereKey($this->deviceId)->where('poll_token', $this->token)
            ->where('poll_status', 'queued')->update(['poll_status' => 'running', 'updated_at' => now()]);
        $device = $started ? NetworkDevice::find($this->deviceId) : null;
        if (! $device) {
            ScanRun::finish($this->runId, 'cancelled', error: __('L’interrogation a été remplacée ou l’équipement supprimé.'));

            return;
        }
        ScanRun::markRunning($this->runId);

        try {
            $summary = $poller->poll($device);
        } catch (RuntimeException $exception) {
            $device->forceFill(['poll_status' => 'failed', 'poll_error' => mb_substr($exception->getMessage(), 0, 255)])->save();
            Audit::record('device.polled', $device, ['result' => [null, 'failed'], 'error' => [null, $device->poll_error]]);
            $monitor->raise('device_unreachable', $device, ['error' => $device->poll_error], 'critical');
            ScanRun::finish($this->runId, 'failed', error: $device->poll_error);

            return;
        }

        $device->forceFill(['poll_status' => 'completed', 'poll_error' => null])->save();
        $monitor->resolve('device_unreachable', $device);
        Audit::record('device.polled', $device, array_map(fn (int $value): array => [null, $value], $summary));
        ScanRun::finish($this->runId, 'completed', $summary);
    }

    public function failed(?Throwable $exception): void
    {
        NetworkDevice::whereKey($this->deviceId)->where('poll_token', $this->token)
            ->whereIn('poll_status', ['queued', 'running'])->update(['poll_status' => 'failed', 'poll_error' => __('L’interrogation a échoué ou dépassé le délai.')]);
        ScanRun::finish($this->runId, 'failed', error: __('L’interrogation a échoué ou dépassé le délai.'));
    }
}
