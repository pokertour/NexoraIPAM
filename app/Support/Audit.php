<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Writes entries to the audit trail. Every feature that changes data or
 * security state must report through this class.
 */
class Audit
{
    public const MASK = '••••••';

    /** @var list<string> */
    private const IGNORED = ['id', 'created_at', 'updated_at', 'remember_token'];

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>|array<string, mixed>  $changes
     */
    public static function record(string $action, ?Model $subject = null, array $changes = [], ?string $subjectType = null, ?string $subjectLabel = null, ?User $actor = null): AuditLog
    {
        $actor ??= auth()->user();
        $token = $actor?->is(auth()->user()) && method_exists($actor, 'currentAccessToken') ? $actor->currentAccessToken() : null;
        $token = $token instanceof PersonalAccessToken ? $token : null;

        return AuditLog::create([
            'api_token_id' => $token?->id,
            'api_token_name' => $token?->name,
            'user_id' => $actor?->getKey(),
            'actor' => $actor?->name,
            'action' => $action,
            'subject_type' => $subjectType ?? ($subject ? self::type($subject) : null),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel ?? ($subject ? self::label($subject) : null),
            'changes' => $changes === [] ? null : $changes,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /** Records an Eloquent lifecycle event with the attribute differences. */
    public static function model(string $event, Model $model): void
    {
        $ignored = array_merge(self::IGNORED, method_exists($model, 'auditIgnored') ? $model->auditIgnored() : []);
        $hidden = $model->getHidden();
        $changes = [];

        $keys = match ($event) {
            'updated' => array_keys($model->getChanges()),
            default => array_keys($model->getAttributes()),
        };

        foreach (array_diff($keys, $ignored) as $key) {
            $old = $event === 'created' ? null : self::value($model, $key, $model->getRawOriginal($key));
            $new = $event === 'deleted' ? null : self::value($model, $key, $model->getAttributes()[$key] ?? null);
            if (in_array($key, $hidden, true)) {
                $old = $old === null ? null : self::MASK;
                $new = $new === null ? null : self::MASK;
            }
            if ($event === 'updated' && $old === $new && ! in_array($key, $hidden, true)) {
                continue;
            }
            if ($event !== 'updated' && $old === null && $new === null) {
                continue;
            }
            $changes[$key] = [$old, $new];
        }

        if ($event === 'updated' && $changes === []) {
            return;
        }

        self::record($event, $model, $changes);
    }

    public static function type(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    public static function label(Model $model): string
    {
        return method_exists($model, 'auditLabel') ? $model->auditLabel() : '#'.$model->getKey();
    }

    private static function value(Model $model, string $key, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof BackedEnum) {
            return $raw->value;
        }
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d H:i:s');
        }
        if (is_string($raw) && $model->hasCast($key, ['array', 'json', 'collection', 'object'])) {
            return json_decode($raw, true);
        }

        return is_scalar($raw) ? $raw : json_encode($raw);
    }
}
