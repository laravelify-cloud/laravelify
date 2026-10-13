@props(['status'])

@php
    $status = (string) $status;
    $color = match ($status) {
        'success' => 'var(--color-success)',
        'failed' => 'var(--color-danger)',
        'running', 'queued' => 'var(--color-warning)',
        default => 'var(--color-muted)',
    };
    $label = match ($status) {
        'success' => 'Finished',
        'running' => 'In progress',
        'queued' => 'Waiting',
        default => ucfirst($status),
    };
@endphp

<span {{ $attributes->class(['status-badge', 'is-live' => in_array($status, ['running', 'queued'], true)]) }} style="--status-color: {{ $color }}">{{ $label }}</span>
