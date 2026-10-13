@props([
    'buckets',
    'height' => 150,
])

{{-- Response time lines: average and p95 per time bucket, with a hover tooltip. --}}
@php
    $count = max(1, count($buckets));
    $max = max(1, (int) collect($buckets)->max('p95'), (int) collect($buckets)->max('avg'));
    $width = 1000;
    $point = fn (int $index, int $value): string => round(($index + 0.5) / $count * $width, 2).','.round($height - ($value / $max) * ($height - 12) - 2, 2);

    $path = function (string $key) use ($buckets, $point): string {
        $path = '';
        $open = false;

        foreach ($buckets as $index => $bucket) {
            if ($bucket[$key] === null) {
                $open = false;

                continue;
            }

            $path .= ($open ? ' L' : ' M').$point($index, (int) $bucket[$key]);
            $open = true;
        }

        return trim($path);
    };

    $p95 = $path('p95');
    $avg = $path('avg');
@endphp

<div class="mon-chart" wire:key="chart-{{ md5(json_encode($buckets)) }}" x-data="{ hover: null, buckets: @js($buckets) }" @mouseleave="hover = null">
    <div class="mon-plot" style="height: {{ $height }}px">
        <div class="mon-grid"><span></span><span></span><span></span></div>
        <svg class="mon-svg" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" aria-hidden="true">
            @if ($p95 !== '')
                <path class="mon-line is-p95" d="{{ $p95 }}" />
            @endif
            @if ($avg !== '')
                <path class="mon-line is-avg" d="{{ $avg }}" />
            @endif
        </svg>
        <div class="mon-cols is-overlay">
            @foreach ($buckets as $index => $bucket)
                <div class="mon-col" @mouseenter="hover = {{ $index }}" :class="hover === {{ $index }} && 'is-hover'"></div>
            @endforeach
        </div>

        <template x-if="hover !== null">
            <div class="mon-tip" :style="{ left: ((hover + 0.5) / {{ $count }} * 100) + '%' }" :class="hover < {{ $count }} * 0.2 ? 'is-start' : (hover > {{ $count }} * 0.8 ? 'is-end' : '')">
                <p class="mon-tip-title" x-text="buckets[hover].label"></p>
                <p><span class="mon-dot is-avg"></span>AVG <b x-text="buckets[hover].avg === null ? '—' : buckets[hover].avg.toLocaleString() + ' ms'"></b></p>
                <p><span class="mon-dot is-p95"></span>P95 <b x-text="buckets[hover].p95 === null ? '—' : buckets[hover].p95.toLocaleString() + ' ms'"></b></p>
            </div>
        </template>
    </div>
    <div class="mon-axis">
        <span>{{ $buckets[0]['label'] ?? '' }}</span>
        <span>{{ $buckets[count($buckets) - 1]['label'] ?? '' }}</span>
    </div>
</div>
