<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An anomaly detected by the monitoring. Openings and acknowledgements are
 * recorded explicitly in the audit trail by App\Support\AlertMonitor.
 */
class Alert extends Model
{
    /** @var array<string, string> */
    public const TYPES = [
        'capacity' => 'Seuil d’occupation dépassé',
        'unknown_host' => 'Hôte non inventorié détecté',
        'status_conflict' => 'Adresse disponible qui répond',
        'mac_changed' => 'Changement de MAC',
        'host_down' => 'Hôte injoignable',
        'device_unreachable' => 'Équipement SNMP injoignable',
    ];

    /** @var array<string, string> */
    public const SEVERITIES = ['warning' => 'Avertissement', 'critical' => 'Critique'];

    protected $fillable = ['type', 'severity', 'subject_type', 'subject_id', 'subject_label', 'details', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'details' => 'array', 'last_seen_at' => 'datetime', 'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime', 'notified_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('resolved_at')->whereNull('acknowledged_at');
    }

    public function message(): string
    {
        $details = $this->details ?? [];

        return match ($this->type) {
            'capacity' => __(':subject est occupé à :usage % (seuil :threshold %).', ['subject' => $this->subject_label, 'usage' => $details['usage'] ?? '?', 'threshold' => $details['threshold'] ?? '?']),
            'unknown_host' => __(':subject répond mais n’était pas inventoriée. Elle a été ajoutée automatiquement.', ['subject' => $this->subject_label]),
            'status_conflict' => __(':subject est marquée disponible mais répond au ping.', ['subject' => $this->subject_label]),
            'mac_changed' => __(':subject est passée de :old à :new.', ['subject' => $this->subject_label, 'old' => $details['old'] ?? '?', 'new' => $details['new'] ?? '?']),
            'host_down' => __(':subject ne répond plus depuis le dernier scan.', ['subject' => $this->subject_label]),
            'device_unreachable' => __(':subject ne répond pas en SNMP.', ['subject' => $this->subject_label]),
            default => $this->subject_label,
        };
    }

    public function url(): ?string
    {
        return match ($this->subject_type) {
            'subnet' => route('addresses', ['subnet' => $this->subject_id]),
            'ip_address' => ($subnet = IpAddress::whereKey($this->subject_id)->value('subnet_id')) ? route('addresses', ['subnet' => $subnet, 'search' => $this->subject_label]) : null,
            'network_device' => route('devices.show', $this->subject_id),
            default => null,
        };
    }
}
