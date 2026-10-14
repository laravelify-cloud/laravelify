{{-- A tinted notice with an icon and a title (Coolify's callout). Types: warning, danger, info, success. --}}
@props([
    'type' => 'warning',
    'title' => null,
])

@php
    $icon = [
        'warning' => 'danger',
        'danger' => 'ban',
        'info' => 'book',
        'success' => 'check',
    ][$type] ?? 'danger';
@endphp

<div {{ $attributes->class(['notice', 'is-'.$type]) }} role="{{ $type === 'danger' ? 'alert' : 'note' }}">
    <x-menu-icon name="{{ $icon }}" class="notice-icon" />
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="notice-title">{{ $title }}</p>
        @endif
        <div class="notice-text">{{ $slot }}</div>
    </div>
</div>
