@php
    $color = fn (string $status) => match ($status) {
        'ok' => 'var(--color-success)',
        'warn' => 'var(--color-warning)',
        'fail' => 'var(--color-danger)',
        default => 'var(--color-muted)',
    };
    $label = fn (string $status) => match ($status) {
        'ok' => 'Healthy',
        'warn' => 'Warning',
        'fail' => 'Failing',
        default => 'Unknown',
    };
@endphp

<x-layer-card title="Health" helper="Is the site up, are its workers running, and is it logging errors?" flush>
    <x-slot:actions>
        @if ($checkedAt)
            <span class="text-[12px] text-muted">Checked at {{ $checkedAt }}</span>
        @endif
        <button class="btn" type="button" wire:click="runChecks" wire:loading.attr="disabled" wire:target="runChecks, restart, enableNginxLogs">
            <x-menu-icon name="refresh" wire:loading.class="animate-spin" wire:target="runChecks" /> Run checks
        </button>
    </x-slot:actions>

    <div class="health-summary" style="--status-color: {{ $color($summary[0]) }}">
        <span class="health-dot"></span>
        <p class="font-medium">{{ $summary[1] }}</p>
        <p class="text-[12.5px] text-muted">{{ collect($checks)->where('status', 'ok')->count() }} of {{ count($checks) }} checks passing</p>
    </div>

    <ul class="health-list" wire:loading.class="opacity-60" wire:target="runChecks">
        @foreach ($checks as $check)
            <li class="health-row" wire:key="health-{{ $check['key'] }}">
                <span class="status-badge w-24 justify-center" style="--status-color: {{ $color($check['status']) }}">{{ $label($check['status']) }}</span>
                <div class="min-w-0 flex-1">
                    <p class="text-[13.5px] font-medium">{{ $check['title'] }}</p>
                    <p class="break-words text-[12.5px] text-muted">{{ $check['detail'] }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-1.5">
                    @if (($check['action'] ?? null) === 'restart')
                        <button class="btn" type="button" wire:click="restart(@js($check['manager']), @js($check['program']))" wire:confirm="Restart {{ $check['program'] }}?">
                            <x-menu-icon name="refresh" /> Restart
                        </button>
                    @elseif (($check['action'] ?? null) === 'enable-nginx-logs')
                        <button class="btn" type="button" wire:click="enableNginxLogs">Enable nginx logs</button>
                    @endif
                    @if (! empty($check['log']))
                        <button class="btn" type="button" @click="setTab('logs'); Livewire.dispatch('open-log', { key: @js($check['log']) })">
                            <x-menu-icon name="logs" /> View log
                        </button>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</x-layer-card>
