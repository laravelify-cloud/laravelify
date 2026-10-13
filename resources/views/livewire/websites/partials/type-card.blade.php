@php
    $runs = [
        'php' => 'PHP-FPM',
        'node' => 'PM2',
        'static' => 'nginx',
    ][\App\Support\SiteTypes::runtime($item['type'] ?? $key)] ?? 'nginx';
@endphp

<button
    class="type-card {{ $selected ? 'is-selected' : '' }}"
    type="button"
    wire:click="chooseType('{{ $key }}'{{ $stack !== '' ? ", '".$stack."'" : '' }})"
    aria-pressed="{{ $selected ? 'true' : 'false' }}"
>
    <span class="type-card-icon"><x-menu-icon name="{{ $item['icon'] }}" /></span>
    <span class="type-card-body">
        <span class="flex flex-wrap items-center gap-1.5">
            <span class="type-card-title">{{ $item['label'] }}</span>
            <span class="pill">{{ $runs }}</span>
        </span>
        <span class="type-card-meta">{{ $item['language'] }}</span>
        <span class="type-card-text">{{ $item['strength'] }}</span>
        <span class="type-card-best"><b>Best for:</b> {{ $item['best'] }}</span>
    </span>
</button>
