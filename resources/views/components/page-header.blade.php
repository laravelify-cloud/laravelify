@props([
    'title',
    'summary' => null,
])

<div {{ $attributes->class(['page-header']) }}>
    <div class="min-w-0">
        @isset($crumbs)
            <p class="mb-1 flex flex-wrap items-center gap-1.5 text-[13px] text-muted">{{ $crumbs }}</p>
        @endisset
        <h2 class="page-title truncate">{{ $title }}</h2>
        @if ($summary)
            <p class="page-summary">{{ $summary }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-1.5">{{ $actions }}</div>
    @endisset
</div>
