{{-- One spreadsheet cell of an SQL result: numbers right-aligned, NULL dimmed, long text cut with the whole value on hover. --}}
@php
    $isNull = $value === null;
    $text = $isNull ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
@endphp
<td class="{{ $isNull ? 'is-null' : (is_numeric($value) ? 'is-number' : '') }}" @if (! $isNull && mb_strlen($text) > 60) title="{{ \Illuminate\Support\Str::limit($text, 2000) }}" @endif>{{ $isNull ? 'NULL' : \Illuminate\Support\Str::limit($text, 120) }}</td>
