<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        {{-- The panel is laid out for PCs (1024px and wider): phones and tablets show that layout, zoomed to fit, and can pinch-zoom. --}}
        <meta name="viewport" content="width=1024">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? config('app.name') }}</title>
        @include('layouts.partials.favicons')
        @fonts
        @vite(['resources/css/app.css', 'resources/css/editor.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="shell h-screen overflow-hidden">
        {{ $slot }}
        @livewireScripts
    </body>
</html>
