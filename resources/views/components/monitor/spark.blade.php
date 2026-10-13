@props([
    'values',
])

{{-- Tiny bar sparkline (requests per hour on the overview). --}}
@php
    $max = max(1, (int) max($values ?: [0]));
@endphp

<div {{ $attributes->class(['mon-spark']) }} aria-hidden="true">
    @foreach ($values as $value)
        <span style="height: {{ $value > 0 ? max(8, round($value / $max * 100)) : 4 }}%" @class(['is-empty' => $value === 0])></span>
    @endforeach
</div>
