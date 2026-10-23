<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
