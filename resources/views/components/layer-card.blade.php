@props([
    'title',
    'helper' => null,
    'flush' => false,
])

<section {{ $attributes->class(['layer-card']) }}>
    <header class="layer-card-head">
        <div class="min-w-0">
            <h2>{{ $title }}</h2>
            @if ($helper)
                <p>{{ $helper }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="layer-card-actions">{{ $actions }}</div>
        @endisset
    </header>
    <div @class(['layer-card-body', 'is-flush' => $flush])>
        {{ $slot }}
    </div>
</section>
