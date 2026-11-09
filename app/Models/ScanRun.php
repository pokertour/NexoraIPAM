<?php

namespace App\Models;

use Database\Factories\ScanRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One execution of a background scan: ICMP discovery of a subnet, SNMP poll
 * of a device or DHCP lease import. Requests are already recorded in the
 * audit trail; this table keeps the operational outcome of each run.
 */
class ScanRun extends Model
{
    /** @use HasFactory<ScanRunFactory> */
    use HasFactory, Prunable;

    /** Number of days a finished run is kept. */
    public const RETENTION_DAYS = 90;

    /** @var array<string, string> */
    public const KINDS = ['discovery' => 'Découverte ICMP', 'snmp' => 'Interrogation SNMP', 'dhcp' => 'Import DHCP'];

    /** @var array<string, string> */
    public const STATUSES = [
        'queued' => 'En attente', 'running' => 'En cours', 'completed' => 'Terminé',
        'failed' => 'Échec', 'cancelled' => 'Annulé',
    ];

    /** @var array<string, string> */
    public const TRIGGERS = ['manual' => 'Manuel', 'scheduled' => 'Planifié'];

    /** @var array<string, string> */
    public const SUMMARY_LABELS = [
        'targets' => 'Adresses sondées', 'responded' => 'Réponses',
        'ports' => 'Ports', 'up' => 'Ports actifs', 'macs' => 'MAC apprises', 'arp' => 'Entrées ARP', 'addresses' => 'Adresses mises à jour', 'api_ports' => 'Ports lus par API',
        'leases' => 'Baux', 'named' => 'Noms d’hôte', 'created' => 'Adresses créées',
    ];

    protected $fillable = ['kind', 'subject_type', 'subject_id', 'subject_label', 'trigger', 'user_id', 'status', 'summary', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a queued run. A run requested from an authenticated session is
     * manual; one queued by the scheduler or the CLI is scheduled.
     */
    public static function queue(string $kind, ?Model $subject = null, ?string $label = null): self
    {
        return self::create([
            'kind' => $kind,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label ?? ($subject && method_exists($subject, 'auditLabel') ? $subject->auditLabel() : null),
            'trigger' => auth()->check() ? 'manual' : 'scheduled',
            'user_id' => auth()->id(),
            'status' => 'queued',
        ]);
    }

    public static function markRunning(?int $id): void
    {
        if ($id !== null) {
            self::whereKey($id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Close a pending run. A run that is already finished is left untouched.
     *
     * @param  array<string, int>  $summary
     */
    public static function finish(?int $id, string $status, array $summary = [], ?string $error = null): void
    {
        if ($id === null) {
            return;
        }

        self::whereKey($id)->whereIn('status', ['queued', 'running'])->update([
            'status' => $status,
            'summary' => $summary === [] ? null : json_encode($summary),
            'error' => $error === null ? null : mb_substr($error, 0, 255),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Link to the scanned object, or null when it no longer exists. Requires the subject relation. */
    public function subjectUrl(): ?string
    {
        return match (true) {
            $this->subject instanceof Subnet => route('addresses', ['subnet' => $this->subject_id]),
            $this->subject instanceof NetworkDevice => route('devices.show', $this->subject_id),
            default => null,
        };
    }

    public function durationInSeconds(): ?int
    {
        return $this->started_at && $this->finished_at ? (int) $this->started_at->diffInSeconds($this->finished_at, true) : null;
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->whereNotIn('status', ['queued', 'running']);
    }
}
