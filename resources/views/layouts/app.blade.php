<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ request()->cookie('laravelify-theme') === 'light' ? '' : 'dark' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? config('app.name') }}</title>
        @include('layouts.partials.favicons')
        @include('layouts.partials.theme')
        @include('layouts.partials.browser-time')
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="shell">
        <div class="flex min-h-screen" x-data="{ nav: false }" @keydown.escape.window="nav = false" x-on:livewire:navigated.window="nav = false">
            <div class="sidebar-scrim lg:hidden" x-cloak x-show="nav" x-transition.opacity @click="nav = false"></div>
            <aside class="sidebar" :class="nav && 'is-open'">
                <a href="{{ route('dashboard') }}" class="sidebar-brand" wire:navigate>
                    <x-brand-mark :size="26" />
                    Laravelify
                </a>

                <nav class="sidebar-nav">
                    <p class="nav-section">Workspace</p>
                    <a class="menu-item {{ request()->routeIs('dashboard') ? 'menu-item-active' : '' }}" href="{{ route('dashboard') }}" wire:navigate>
                        <x-menu-icon name="dashboard" />
                        <span>Dashboard</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('groups.*', 'websites.*') ? 'menu-item-active' : '' }}" href="{{ route('groups.index') }}" wire:navigate>
                        <x-menu-icon name="groups" />
                        <span>Groups</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('monitoring.index') ? 'menu-item-active' : '' }}" href="{{ route('monitoring.index') }}" wire:navigate>
                        <x-menu-icon name="chart" />
                        <span>Monitoring</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('monitoring.server') ? 'menu-item-active' : '' }}" href="{{ route('monitoring.server') }}" wire:navigate>
                        <x-menu-icon name="cpu" />
                        <span>Server monitoring</span>
                    </a>

                    <p class="nav-section">Deployments</p>
                    <a class="menu-item {{ request()->routeIs('deployments.*') ? 'menu-item-active' : '' }}" href="{{ route('deployments.index') }}" wire:navigate>
                        <x-menu-icon name="deployments" />
                        <span>All deployments</span>
                    </a>

                    <p class="nav-section">Tools</p>
                    <a class="menu-item {{ request()->routeIs('files.*') ? 'menu-item-active' : '' }}" href="{{ route('files.index') }}" wire:navigate>
                        <x-menu-icon name="files" />
                        <span>File manager</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('terminal.*') ? 'menu-item-active' : '' }}" href="{{ route('terminal.index') }}" wire:navigate>
                        <x-menu-icon name="terminal" />
                        <span>Terminal</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('database.*') ? 'menu-item-active' : '' }}" href="{{ route('database.index') }}" wire:navigate>
                        <x-menu-icon name="database" />
                        <span>Database</span>
                    </a>

                    @php($sidebarLinks = \App\Support\SidebarLinks::custom())
                    @php($hasPhpMyAdmin = \App\Support\SidebarLinks::phpMyAdminAvailable())
                    <p class="nav-section">
                        <span>Links</span>
                        <a class="text-muted transition-colors hover:text-ink" href="{{ route('settings.links') }}" wire:navigate title="Edit links"><x-menu-icon name="pencil" class="size-3.5" /></a>
                    </p>
                    @if ($hasPhpMyAdmin)
                        <a class="menu-item" href="{{ route('tools.phpmyadmin') }}" target="_blank" rel="noopener" title="Opens signed in with a one-time link">
                            <x-menu-icon name="database" />
                            <span>phpMyAdmin</span>
                            <x-menu-icon name="external" class="ml-auto size-3.5 opacity-50" />
                        </a>
                    @endif
                    @foreach ($sidebarLinks as $link)
                        <a class="menu-item" href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ $link['url'] }}">
                            <x-menu-icon name="link" />
                            <span>{{ $link['label'] }}</span>
                            <x-menu-icon name="external" class="ml-auto size-3.5 opacity-50" />
                        </a>
                    @endforeach
                    @if (! $hasPhpMyAdmin && $sidebarLinks === [])
                        <a class="menu-item" href="{{ route('settings.links') }}" wire:navigate>
                            <x-menu-icon name="plus" />
                            <span>Add a link</span>
                        </a>
                    @endif

                    <p class="nav-section">Backups</p>
                    <a class="menu-item {{ request()->routeIs('backups.databases', 'backups.schedules.*') && (request()->route('kind') ?? request()->route('schedule')?->kind ?? 'database') !== 'files' ? 'menu-item-active' : '' }}" href="{{ route('backups.databases') }}" wire:navigate>
                        <x-menu-icon name="database" />
                        <span>Databases</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('backups.files', 'backups.schedules.*') && (request()->route('kind') ?? request()->route('schedule')?->kind) === 'files' ? 'menu-item-active' : '' }}" href="{{ route('backups.files') }}" wire:navigate>
                        <x-menu-icon name="backup" />
                        <span>Files</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('backups.storages') ? 'menu-item-active' : '' }}" href="{{ route('backups.storages') }}" wire:navigate>
                        <x-menu-icon name="s3" />
                        <span>Backup storage</span>
                    </a>

                    <p class="nav-section">Infrastructure</p>
                    <a class="menu-item {{ request()->routeIs('server.*') ? 'menu-item-active' : '' }}" href="{{ route('server.index') }}" wire:navigate>
                        <x-menu-icon name="server" />
                        <span>Server</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('sources.*', 'settings.github') ? 'menu-item-active' : '' }}" href="{{ route('sources.index') }}" wire:navigate>
                        <x-menu-icon name="sources" />
                        <span>Sources</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('tunnels.*') ? 'menu-item-active' : '' }}" href="{{ route('tunnels.index') }}" wire:navigate>
                        <x-menu-icon name="tunnels" />
                        <span>Tunnels</span>
                    </a>

                    <p class="nav-section">Security</p>
                    <a class="menu-item {{ request()->routeIs('security.access') ? 'menu-item-active' : '' }}" href="{{ route('security.access') }}" wire:navigate>
                        <x-menu-icon name="lock" />
                        <span>Access</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('security.private-keys') ? 'menu-item-active' : '' }}" href="{{ route('security.private-keys') }}" wire:navigate>
                        <x-menu-icon name="keys" />
                        <span>Private keys</span>
                    </a>

                    <p class="nav-section">Settings</p>
                    <a class="menu-item {{ request()->routeIs('settings.general') ? 'menu-item-active' : '' }}" href="{{ route('settings.general') }}" wire:navigate>
                        <x-menu-icon name="clock" />
                        <span>General</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('settings.telegram') ? 'menu-item-active' : '' }}" href="{{ route('settings.telegram') }}" wire:navigate>
                        <x-menu-icon name="telegram" />
                        <span>Telegram bot</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('settings.links') ? 'menu-item-active' : '' }}" href="{{ route('settings.links') }}" wire:navigate>
                        <x-menu-icon name="link" />
                        <span>Links</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('settings.cloudflare') ? 'menu-item-active' : '' }}" href="{{ route('settings.cloudflare') }}" wire:navigate>
                        <x-menu-icon name="globe" />
                        <span>Cloudflare</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('settings.mcp') ? 'menu-item-active' : '' }}" href="{{ route('settings.mcp') }}" wire:navigate>
                        <x-menu-icon name="robot" />
                        <span>MCP</span>
                    </a>
                    <a class="menu-item {{ request()->routeIs('settings.github') ? 'menu-item-active' : '' }}" href="{{ route('settings.github') }}" wire:navigate>
                        <x-menu-icon name="git" />
                        <span>GitHub token</span>
                    </a>

                    <p class="nav-section">Help</p>
                    <a class="menu-item" href="{{ config('laravelify.docs_url') }}" target="_blank" rel="noopener">
                        <x-menu-icon name="book" />
                        <span>Documentation</span>
                        <x-menu-icon name="external" class="ml-auto size-3.5 opacity-60" />
                    </a>
                    <a class="menu-item" href="{{ config('laravelify.repository_url') }}" target="_blank" rel="noopener">
                        <x-menu-icon name="git" />
                        <span>GitHub</span>
                        <span class="ml-auto font-mono text-[11px] text-muted">{{ config('laravelify.version') }}</span>
                    </a>
                </nav>

                <div class="relative border-t border-line pt-2" x-data="{ open: false, dark: document.documentElement.classList.contains('dark') }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button class="profile-button" type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu">
                        <span class="profile-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="min-w-0 flex-1 truncate text-left">{{ auth()->user()->name }}</span>
                        <x-menu-icon name="chevrons" class="size-4 text-muted" />
                    </button>
                    <div
                        class="dropdown profile-menu"
                        role="menu"
                        x-cloak
                        x-show="open"
                        x-transition:enter="dropdown-in"
                        x-transition:enter-start="dropdown-from"
                        x-transition:enter-end="dropdown-to"
                        x-transition:leave="dropdown-out"
                        x-transition:leave-start="dropdown-to"
                        x-transition:leave-end="dropdown-from"
                        style="top: auto"
                    >
                        <div class="px-2 pb-2 pt-1.5">
                            <p class="truncate text-[13px] font-medium">{{ auth()->user()->name }}</p>
                            <p class="truncate text-[12px] text-muted">{{ auth()->user()->email }}</p>
                        </div>
                        <div class="flex items-center justify-between gap-2 px-2 pb-1.5">
                            <span class="text-[12px] text-muted">Appearance</span>
                            <div class="segmented" role="group" aria-label="Theme">
                                <button type="button" title="Light" aria-label="Light theme" :aria-pressed="!dark" @click="laravelifyTheme('light'); dark = false"><x-menu-icon name="sun" class="size-4" /></button>
                                <button type="button" title="Dark" aria-label="Dark theme" :aria-pressed="dark" @click="laravelifyTheme('dark'); dark = true"><x-menu-icon name="moon" class="size-4" /></button>
                            </div>
                        </div>
                        <div class="dropdown-sep"></div>
                        <a class="dropdown-option" role="menuitem" href="{{ route('account.security') }}" wire:navigate>
                            <x-menu-icon name="shield" /> Security
                        </a>
                        <a class="dropdown-option" role="menuitem" href="{{ route('settings.github') }}" wire:navigate>
                            <x-menu-icon name="keys" /> Personal access token
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="dropdown-option" role="menuitem" type="submit"><x-menu-icon name="logout" /> Log out</button>
                        </form>
                    </div>
                </div>
            </aside>
            <div class="min-w-0 flex-1">
                <header class="topbar">
                    <button class="icon-btn lg:hidden" type="button" @click="nav = true" aria-label="Open navigation">
                        <x-menu-icon name="menu" />
                    </button>
                    <nav class="topbar-crumbs" aria-label="Breadcrumb">
                        <a href="{{ route('dashboard') }}" wire:navigate>Laravelify</a>
                        @if (($title ?? null) && ! request()->routeIs('dashboard'))
                            <span class="sep">/</span>
                            <h1 class="current">{{ $title }}</h1>
                        @else
                            <span class="sep">/</span>
                            <h1 class="current">Dashboard</h1>
                        @endif
                    </nav>
                </header>
                <main class="mx-auto flex w-full max-w-[96rem] flex-col gap-5 px-4 py-5 sm:px-6 lg:py-6">
                    <livewire:panel-worker-alert />
                    {{ $slot }}
                </main>
            </div>
        </div>
        @include('layouts.partials.motion')
        @include('layouts.partials.file-manager')
        @include('layouts.partials.logs')
        @include('layouts.partials.secrets')
        <script>window.laravelifyTime = @js(app(\App\Services\DisplayTime::class)->forBrowser());</script>
        @include('layouts.partials.monitoring')
        @include('layouts.partials.select')
        @include('layouts.partials.toast')
        @if (session('clear-draft'))
            <script>try { localStorage.removeItem(@js(session('clear-draft'))); } catch (e) {}</script>
        @endif
        @livewireScripts
    </body>
</html>
