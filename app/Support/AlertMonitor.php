<?php

namespace App\Support;

use App\Models\Alert;
use App\Models\Setting;
use App\Models\Subnet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AlertMonitor
{
    public const DEFAULT_THRESHOLD = 80;

    /** @return array{enabled: bool, recipients: string, threshold: int, conflicts: bool, host_down: bool} */
    public static function settings(): array
    {
        return Setting::read('alerts') + ['enabled' => false, 'recipients' => '', 'threshold' => self::DEFAULT_THRESHOLD, 'conflicts' => true, 'host_down' => true];
    }

    /**
     * Opens an alert, or refreshes the open one for the same subject and type.
     *
     * @param  array<string, mixed>  $details
     */
    public function raise(string $type, Model $subject, array $details = [], string $severity = 'warning'): Alert
    {
        $open = $this->open($type, $subject);
        if ($open) {
            $open->forceFill(['details' => $details, 'severity' => $severity, 'last_seen_at' => now()])->save();

            return $open;
        }

        $alert = Alert::create([
            'type' => $type, 'severity' => $severity, 'subject_type' => Audit::type($subject), 'subject_id' => $subject->getKey(),
            'subject_label' => Audit::label($subject), 'details' => $details, 'last_seen_at' => now(),
        ]);
        Audit::record('alert.opened', $alert, ['type' => [null, $type], 'severity' => [null, $severity]] + array_map(fn (mixed $value): array => [null, $value], $details), 'alert', $alert->message());
        $this->notify($alert);

        return $alert;
    }

    public function resolve(string $type, Model $subject): void
    {
        Alert::where('type', $type)->where('subject_type', Audit::type($subject))->where('subject_id', $subject->getKey())
            ->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    public function acknowledge(Alert $alert): void
    {
        if ($alert->acknowledged_at || $alert->resolved_at) {
            return;
        }

        $alert->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => auth()->id()])->save();
        Audit::record('alert.acknowledged', $alert, subjectType: 'alert', subjectLabel: $alert->message());
    }

    public function checkCapacity(Subnet $subnet): void
    {
        if (str_contains($subnet->cidr, ':')) {
            return;
        }

        $capacity = $subnet->ipv4Capacity();
        $assigned = $subnet->addresses()->whereIn('status', ['used', 'reserved'])->count();
        $usage = $capacity > 0 ? round(100 * $assigned / $capacity, 1) : 0;
        $threshold = (int) self::settings()['threshold'];

        if ($capacity > 0 && $usage >= $threshold) {
            $this->raise('capacity', $subnet, ['usage' => $usage, 'threshold' => $threshold], $usage >= 95 ? 'critical' : 'warning');
        } else {
            $this->resolve('capacity', $subnet);
        }
    }

    private function open(string $type, Model $subject): ?Alert
    {
        return Alert::where('type', $type)->where('subject_type', Audit::type($subject))
            ->where('subject_id', $subject->getKey())->whereNull('resolved_at')->first();
    }

    private function notify(Alert $alert): void
    {
        $settings = self::settings();
        $smtp = Setting::read('smtp');
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $settings['recipients']))));
        if (! $settings['enabled'] || ! ($smtp['enabled'] ?? false) || $recipients === []) {
            return;
        }

        try {
            Mail::mailer('smtp')->raw($alert->message()."\n\n".($alert->url() ?? config('app.url')), function ($message) use ($alert, $recipients, $smtp): void {
                $message->from($smtp['from'], config('app.name'))->to($recipients)
                    ->subject('[NexoraIPAM] '.__(Alert::TYPES[$alert->type] ?? $alert->type));
            });
            $alert->forceFill(['notified_at' => now()])->save();
        } catch (Throwable $exception) {
            Log::warning('Échec de l’envoi d’une alerte', ['type' => $exception::class, 'alert' => $alert->id]);
        }
    }
}
