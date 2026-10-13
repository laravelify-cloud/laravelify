@php
    $runs = [
        'php' => 'PHP-FPM',
        'node' => 'PM2',
        'static' => 'nginx',
    ][\App\Support\SiteTypes::runtime($item['type'] ?? $key)] ?? 'nginx';
    $searchText = strtolower($item['label'].' '.$item['language'].' '.$runs.' '.$item['strength']);
@endphp

<div
    class="type-card-wrap"
    x-show="shows('{{ $group }}', @js($searchText))"
    :style="{ order: isPinned('{{ $pinId }}') ? 0 : 1 }"
>
    <button
        class="type-card {{ $selected ? 'is-selected' : '' }}"
        type="button"
        wire:click="chooseType('{{ $key }}'{{ $stack !== '' ? ", '".$stack."'" : '' }})"
        aria-pressed="{{ $selected ? 'true' : 'false' }}"
    >
        <span class="type-card-icon"><x-menu-icon name="{{ $item['icon'] }}" /></span>
        <span class="type-card-body">
            <span class="flex flex-wrap items-center gap-1.5 pr-6">
                <span class="type-card-title">{{ $item['label'] }}</span>
                <span class="pill">{{ $runs }}</span>
            </span>
            <span class="type-card-meta">{{ $item['language'] }}</span>
            <span class="type-card-text">{{ $item['strength'] }}</span>
        </span>
    </button>
    <button
        type="button"
        class="type-pin"
        :class="{ 'is-pinned': isPinned('{{ $pinId }}') }"
        :aria-pressed="isPinned('{{ $pinId }}') ? 'true' : 'false'"
        :title="isPinned('{{ $pinId }}') ? 'Unpin' : 'Pin to the top'"
        x-on:click="togglePin('{{ $pinId }}')"
    >
        <svg viewBox="0 0 24 24" class="size-4" fill="currentColor" aria-hidden="true"><path d="M16 3a1 1 0 0 1 .7 1.7L15.4 6l1.6 5 2.2 2.2a1 1 0 0 1-.7 1.8H13v6l-1 1-1-1v-6H5.5a1 1 0 0 1-.7-1.8L7 11l1.6-5-1.3-1.3A1 1 0 0 1 8 3z"/></svg>
    </button>
</div>
