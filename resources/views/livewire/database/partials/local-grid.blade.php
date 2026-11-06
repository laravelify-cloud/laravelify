{{-- A list in the rows grid (see mountLocalGrid in db-grid.js). $spec: key, columns, rows, and the options there. --}}
@php
    $spec['operators'] ??= \App\Services\DatabaseBrowser::OPERATORS;
    $payload = json_encode($spec, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp
<div wire:ignore wire:key="local-grid-{{ $spec['key'] }}-{{ md5((string) $payload) }}" x-data="localGrid(JSON.parse($el.dataset.spec))" data-spec="{{ $payload }}">
    <div class="sql-result-bar">
        <input class="input h-8 w-60" type="search" x-ref="search" placeholder="{{ $spec['search'] ?? 'Search' }}" aria-label="{{ $spec['search'] ?? 'Search' }}">
        <span class="text-[12px] text-muted">The funnel on a header filters by its values; click a header to sort; ⋮ pins, sizes, and hides columns.</span>
    </div>
    <div x-ref="grid" class="local-grid {{ count($spec['rows']) > 15 ? 'is-tall' : (count($spec['rows']) === 0 ? 'is-empty' : '') }}"></div>
</div>
