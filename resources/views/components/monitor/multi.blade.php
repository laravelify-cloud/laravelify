@props([
    'buckets',
    'series',
    'format' => 'number',
    'max' => null,
    'height' => 130,
])

{{-- Line chart with one or more series. Each bucket holds the series keys as numbers or null. --}}
@php
    $count = max(1, count($buckets));
    $width = 1000;
    $top = max(1.0, (float) ($max ?? collect($buckets)->flatMap(fn ($bucket) => collect($series)->map(fn ($line) => $bucket[$line['key']] ?? 0))->max()));
    $top = $max ?? $top * 1.15;

    $fmt = function (?float $value) use ($format): string {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'percent' => round($value, 1).'%',
            'bytes' => $value >= 1048576 ? round($value / 1048576, 2).' MB/s' : ($value >= 1024 ? round($value / 1024, 1).' KB/s' : round($value).' B/s'),
            default => (string) round($value, 2),
        };
    };

    $paths = [];

    foreach ($series as $line) {
        $path = '';
        $open = false;

        foreach ($buckets as $index => $bucket) {
            $value = $bucket[$line['key']] ?? null;

            if ($value === null) {
                $open = false;

                continue;
            }

            $x = round(($index + 0.5) / $count * $width, 2);
            $y = round($height - min(1, $value / $top) * ($height - 8) - 2, 2);
            $path .= ($open ? ' L' : ' M').$x.','.$y;
            $open = true;
        }

        $paths[$line['key']] = trim($path);
    }

    $tips = array_map(fn (array $bucket): array => [
        'label' => $bucket['label'],
        'values' => array_map(fn (array $line): array => ['label' => $line['label'], 'color' => $line['color'], 'text' => $fmt($bucket[$line['key']] ?? null)], $series),
    ], $buckets);
@endphp

<div class="mon-chart" wire:key="chart-{{ md5(json_encode($buckets)) }}" x-data="{ hover: null, tips: @js($tips) }" @mouseleave="hover = null">
    <div class="mon-plot" style="height: {{ $height }}px">
        <div class="mon-grid"><span></span><span></span><span></span></div>
        <svg class="mon-svg" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" aria-hidden="true">
            @foreach ($series as $line)
                @if ($paths[$line['key']] !== '')
                    <path class="mon-line" style="stroke: {{ $line['color'] }}" d="{{ $paths[$line['key']] }}" />
                @endif
            @endforeach
        </svg>
        <div class="mon-cols is-overlay">
            @foreach ($buckets as $index => $bucket)
                <div class="mon-col" @mouseenter="hover = {{ $index }}" :class="hover === {{ $index }} && 'is-hover'"></div>
            @endforeach
        </div>

        <template x-if="hover !== null">
            <div class="mon-tip" :style="{ left: ((hover + 0.5) / {{ $count }} * 100) + '%' }" :class="hover < {{ $count }} * 0.2 ? 'is-start' : (hover > {{ $count }} * 0.8 ? 'is-end' : '')">
                <p class="mon-tip-title" x-text="tips[hover].label"></p>
                <template x-for="value in tips[hover].values" :key="value.label">
                    <p><span class="mon-dot" :style="{ background: value.color }"></span><span x-text="value.label"></span> <b x-text="value.text"></b></p>
                </template>
            </div>
        </template>
    </div>
    <div class="mon-axis">
        <span>{{ $buckets[0]['label'] ?? '' }}</span>
        <span>{{ $buckets[count($buckets) - 1]['label'] ?? '' }}</span>
    </div>
</div>
