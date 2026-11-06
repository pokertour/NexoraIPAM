@props(['action'])
@php($tone = match (true) {
    $action === 'created', str_ends_with($action, '.enabled'), $action === 'auth.login' => 'cyan',
    $action === 'deleted', $action === 'auth.failed', str_ends_with($action, '.disabled'), $action === 'alert.opened' => 'amber',
    $action === 'updated', str_starts_with($action, 'settings') => 'violet',
    default => 'blue',
})
<span class="badge tone-{{ $tone }}">{{ __(\App\Models\AuditLog::ACTIONS[$action] ?? $action) }}</span>
