{{-- Monitoring's pages: every website at a glance, then what error tracking (GlitchTip) collected. --}}
<nav class="gt-tabs" aria-label="Monitoring">
    @foreach (['overview' => ['Websites', 'chart', route('monitoring.index')], 'issues' => ['Issues', 'bug', route('monitoring.errors', 'issues')], 'performance' => ['Performance', 'bolt', route('monitoring.errors', 'performance')], 'logs' => ['Logs', 'logs', route('monitoring.errors', 'logs')]] as $key => [$label, $icon, $href])
        <a href="{{ $href }}" wire:navigate @class(['gt-tab', 'is-active' => $active === $key]) @if ($active === $key) aria-current="page" @endif>
            <x-menu-icon :name="$icon" class="size-4" /> {{ $label }}
        </a>
    @endforeach
</nav>
