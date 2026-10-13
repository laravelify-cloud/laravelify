<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ request()->cookie('laravelify-theme') === 'light' ? '' : 'dark' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title ?? config('app.name') }}</title>
        @include('layouts.partials.favicons')
        @include('layouts.partials.theme')
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="shell">
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center gap-4 p-4">
            <div class="flex items-center gap-2.5 text-[17px] font-semibold tracking-tight">
                <x-brand-mark :size="32" />
                <span>Laravel<span class="text-accent">ify</span></span>
            </div>
            <section class="card p-4">
                {{ $slot }}
            </section>
        </main>
        @include('layouts.partials.motion')
        @livewireScripts
    </body>
</html>
