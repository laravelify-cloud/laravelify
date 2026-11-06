{{-- Small bar chart of counts, oldest first: an issue's events per hour or day, or a duration over time. --}}
@php
    $points = array_values($points);
    $peak = max(1, max($points ?: [0]));
    $unit ??= '';
@endphp
<span @class(['gt-trend', 'is-tall' => $tall ?? false]) aria-hidden="true">
    @foreach ($points as $count)
        <i @class(['is-hot' => $count > 0 && $count === $peak && $unit === '']) style="height: {{ $count > 0 ? max(12, round($count / $peak * 100)) : 4 }}%" title="{{ number_format($count) }}{{ $unit }}"></i>
    @endforeach
</span>
