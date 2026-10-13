@props(['size' => 26])

{{-- The Laravelify mark (brand/laravelify-brand-assets/02-mark): a white "L" and a yellow deploy chevron. --}}
@php($id = 'brand-mark-'.\Illuminate\Support\Str::random(6))
<svg {{ $attributes->merge(['class' => 'shrink-0']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 64 64" fill="none" aria-hidden="true">
    <defs>
        <linearGradient id="{{ $id }}-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8B5CF6" /><stop offset="1" stop-color="#5A12C7" /></linearGradient>
        <radialGradient id="{{ $id }}-h" cx="0.22" cy="0.12" r="0.85"><stop offset="0" stop-color="#fff" stop-opacity="0.22" /><stop offset="1" stop-color="#fff" stop-opacity="0" /></radialGradient>
    </defs>
    <rect width="64" height="64" rx="15" fill="url(#{{ $id }}-g)" />
    <rect width="64" height="64" rx="15" fill="url(#{{ $id }}-h)" />
    <path d="M19 15.5a3.5 3.5 0 0 1 3.5-3.5h3a3.5 3.5 0 0 1 3.5 3.5V40h15.5a3.5 3.5 0 0 1 3.5 3.5v3a3.5 3.5 0 0 1-3.5 3.5h-22A3.5 3.5 0 0 1 19 46.5z" fill="#fff" />
    <path d="M34.5 30l7.25-7.75 7.25 7.75" stroke="#FCD452" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round" />
</svg>
