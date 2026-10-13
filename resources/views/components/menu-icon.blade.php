{{-- Material Design icons, generated into resources/icons/mui.json by scripts/sync-mui-icons.mjs. --}}
@props(['name'])

@php
    static $icons = null;
    $icons ??= json_decode(file_get_contents(resource_path('icons/mui.json')), true);
    $paths = $icons[$name] ?? $icons['settings'];
@endphp

<svg {{ $attributes->merge(['class' => 'menu-icon']) }} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
    @foreach ($paths as $path)
        <path d="{{ $path }}" />
    @endforeach
</svg>
