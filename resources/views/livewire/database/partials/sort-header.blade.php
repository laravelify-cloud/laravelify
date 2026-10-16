{{-- A column header that sorts the list: click once, click again to reverse. --}}
<button class="sort-header {{ $sort === $key ? 'is-active' : '' }}" type="button" wire:click="sortBy('{{ $key }}')" aria-label="Sort by {{ strtolower($label) }}">
    {{ $label }}
    <span class="sort-header-arrow" aria-hidden="true">{{ $sort === $key ? ($direction === 'desc' ? '↓' : '↑') : '↕' }}</span>
</button>
