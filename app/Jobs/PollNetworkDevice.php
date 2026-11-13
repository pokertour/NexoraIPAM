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

    /** Minutes before polling again a device whose last poll failed once. */
    public const RETRY_MINUTES = 2;

    public int $timeout = 900;

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
            // One lost poll, common through a VPN, is retried soon and only a
            // second failure in a row marks the device unreachable.
            $failures = $device->poll_failures + 1;
            $confirmed = $failures >= NetworkDevice::FAILURES_BEFORE_ALERT;
            $device->forceFill([
                'poll_status' => $confirmed ? 'failed' : 'unstable',
                'poll_error' => mb_substr($exception->getMessage(), 0, 255),
                'poll_failures' => $failures,
            ]);
            if (! $confirmed && $device->poll_interval) {
                $device->next_poll_at = now()->addMinutes(min(self::RETRY_MINUTES, $device->poll_interval));
            }
            $device->save();
            Audit::record('device.polled', $device, ['result' => [null, $confirmed ? 'failed' : 'unstable'], 'error' => [null, $device->poll_error]]);
            if ($confirmed) {
                $monitor->raise('device_unreachable', $device, ['error' => $device->poll_error, 'failures' => $failures], 'critical');
            }
            ScanRun::finish($this->runId, 'failed', error: $device->poll_error);

            return;
        }

        $device->forceFill(['poll_status' => 'completed', 'poll_error' => null, 'poll_failures' => 0])->save();
        $monitor->resolve('device_unreachable', $device);
        $changes = array_map(fn (int $value): array => [null, $value], $summary);
        if ($device->api_error) {
            $changes['api_error'] = [null, $device->api_error];
        }
        Audit::record('device.polled', $device, $changes);
        ScanRun::finish($this->runId, 'completed', $summary, $device->api_error);
    }

    public function failed(?Throwable $exception): void
    {
        NetworkDevice::whereKey($this->deviceId)->where('poll_token', $this->token)
            ->whereIn('poll_status', ['queued', 'running'])->update(['poll_status' => 'failed', 'poll_error' => __('L’interrogation a échoué ou dépassé le délai.')]);
        ScanRun::finish($this->runId, 'failed', error: __('L’interrogation a échoué ou dépassé le délai.'));
    }
}
