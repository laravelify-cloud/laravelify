<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ request()->cookie('laravelify-theme') === 'light' ? '' : 'dark' }}">
    <head>
        <meta charset="utf-8">
        {{-- The panel is laid out for PCs (1024px and wider): phones and tablets show that layout, zoomed to fit, and can pinch-zoom. --}}
        <meta name="viewport" content="width=1024">
        <title>{{ $title ?? config('app.name') }}</title>
        @include('layouts.partials.favicons')
        @include('layouts.partials.theme')
        @include('layouts.partials.browser-time')
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="shell guest-shell">
        <div class="guest-backdrop" aria-hidden="true"></div>
        <main class="relative mx-auto flex min-h-screen w-full max-w-[440px] flex-col justify-center gap-7 px-4 py-12">
            <div class="flex items-center justify-center gap-3 text-[20px] font-semibold tracking-tight">
                <x-brand-mark :size="38" class="guest-mark" />
                <span>Laravel<span class="text-accent">ify</span></span>
            </div>
            <section class="guest-card">
                {{ $slot }}
            </section>
            <p class="text-center text-[12px] text-muted">Self-hosted server panel</p>
        </main>
        @include('layouts.partials.motion')
        @livewireScripts
    </body>
</html>
