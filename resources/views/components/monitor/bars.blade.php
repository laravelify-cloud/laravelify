@props([
    'buckets',
    'height' => 150,
])

{{-- Stacked request bars: 1/2/3xx, 4xx, 5xx per time bucket, with a hover tooltip. --}}
@php
    $max = max(1, (int) collect($buckets)->max('requests'));
    $count = max(1, count($buckets));
@endphp

<div class="mon-chart" wire:key="chart-{{ md5(json_encode($buckets)) }}" x-data="{ hover: null, buckets: @js($buckets) }" @mouseleave="hover = null">
    <div class="mon-plot" style="height: {{ $height }}px">
        <div class="mon-grid"><span></span><span></span><span></span></div>
        <div class="mon-cols">
            @foreach ($buckets as $index => $bucket)
                <div class="mon-col" @mouseenter="hover = {{ $index }}" :class="hover === {{ $index }} && 'is-hover'">
                    @if ($bucket['requests'] > 0)
                        <div class="mon-bar" style="height: {{ max(2, round($bucket['requests'] / $max * 100, 2)) }}%">
                            <span class="mon-seg is-5xx" style="flex-grow: {{ $bucket['server_errors'] }}"></span>
                            <span class="mon-seg is-4xx" style="flex-grow: {{ $bucket['client_errors'] }}"></span>
                            <span class="mon-seg is-ok" style="flex-grow: {{ $bucket['ok'] }}"></span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <template x-if="hover !== null">
            <div class="mon-tip" :style="{ left: ((hover + 0.5) / {{ $count }} * 100) + '%' }" :class="hover < {{ $count }} * 0.2 ? 'is-start' : (hover > {{ $count }} * 0.8 ? 'is-end' : '')">
                <p class="mon-tip-title" x-text="buckets[hover].label"></p>
                <p><span class="mon-dot is-ok"></span>1/2/3XX <b x-text="buckets[hover].ok.toLocaleString()"></b></p>
                <p><span class="mon-dot is-4xx"></span>4XX <b x-text="buckets[hover].client_errors.toLocaleString()"></b></p>
                <p><span class="mon-dot is-5xx"></span>5XX <b x-text="buckets[hover].server_errors.toLocaleString()"></b></p>
                <p class="mon-tip-total">Total <b x-text="buckets[hover].requests.toLocaleString()"></b></p>
            </div>
        </template>
    </div>
    <div class="mon-axis">
        <span>{{ $buckets[0]['label'] ?? '' }}</span>
        <span>{{ $buckets[count($buckets) - 1]['label'] ?? '' }}</span>
    </div>
</div>
