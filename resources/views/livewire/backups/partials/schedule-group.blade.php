{{--
    One file schedule in the list: its row, the archives of its newest run (one per website or folder)
    under it, and its older runs (each with its archives) behind "older runs".
--}}
@php
    $batches = $group->groupBy(fn ($run) => $run->batch ?? 'run-'.$run->id)->values();
    $latest = $batches->first();
    $schedule = $group->first()->schedule;
    $ids = $group->filter->restorable()->pluck('id')->values()->all();
    $summary = function ($runs) {
        $running = $runs->whereIn('status', ['queued', 'running'])->count();
        $failed = $runs->where('status', 'failed')->count();

        return [
            'status' => $running > 0 ? 'running' : ($failed > 0 ? 'failed' : 'success'),
            'running' => $running,
            'failed' => $failed,
            'bytes' => (int) $runs->sum('size_bytes'),
            'first' => $runs->sortBy('id')->first(),
            'finished' => $runs->max('finished_at'),
        ];
    };
    $now = $summary($latest);
    $unit = ! empty($schedule?->targets['websites']) ? 'website' : 'folder';
@endphp
<tbody x-data="{ open: {{ $first ? 'true' : 'false' }}, older: false }" wire:key="schedule-group-{{ $group->first()->backup_schedule_id }}-{{ $group->first()->id }}">
    <tr class="run-batch" wire:key="schedule-row-{{ $group->first()->backup_schedule_id }}-{{ $now['status'] }}">
        <td>
            @if ($ids !== [])
                <input type="checkbox" aria-label="Select every backup of {{ $schedule?->name }}" :checked="@js($ids).every((id) => selected.includes(id))" @change="selected = $event.target.checked ? [...new Set([...selected, ...@js($ids)])] : selected.filter((id) => !@js($ids).includes(id))">
            @endif
        </td>
        <td class="max-w-xs">
            <button class="flex items-center gap-2 text-left" type="button" @click="open = ! open" :aria-expanded="open">
                <x-menu-icon name="chevron-right" class="size-4 shrink-0 text-muted transition-transform" ::class="open && 'rotate-90'" />
                <x-menu-icon name="backup" class="size-4 shrink-0 text-muted" />
                <span class="truncate text-[12.5px] font-semibold uppercase tracking-wide">{{ $schedule?->name ?? 'Deleted schedule' }}</span>
            </button>
            <span class="block truncate pl-6 text-[12px] text-muted">
                {{ $latest->count() }} {{ $latest->count() === 1 ? 'archive' : 'archives' }}{{ $latest->count() > 1 ? ', one per '.$unit : '' }}
                · {{ $batches->count() }} {{ $batches->count() === 1 ? 'run' : 'runs' }}
                · {{ $now['first']->trigger === 'manual' ? 'Run now' : 'Scheduled' }}
            </span>
        </td>
        <td>
            @if ($now['status'] === 'running')
                <span class="flex items-center gap-2 text-[12.5px]"><span class="task-spinner shrink-0"></span> {{ $latest->count() - $now['running'] }} of {{ $latest->count() }} done</span>
            @else
                <x-status-badge :status="$now['status']" />
                @if ($now['failed'] > 0)
                    <span class="block text-[11.5px] text-muted">{{ $now['failed'] }} of {{ $latest->count() }} failed</span>
                @endif
            @endif
        </td>
        <td class="text-muted">{{ $now['bytes'] > 0 ? \Illuminate\Support\Number::fileSize($now['bytes'], precision: 1) : '—' }}</td>
        <td class="text-muted">
            {{ $time->datetime($now['first']->created_at) }}
            <span class="block text-[12px]">Latest run</span>
        </td>
        <td>
            <div class="row-actions w-full">
                <button class="btn btn-sm" type="button" @click="open = ! open" x-text="open ? 'Hide' : 'Show'"></button>
            </div>
        </td>
    </tr>

    @foreach ($latest->sortBy('target') as $run)
        @include('livewire.backups.partials.run-row', ['run' => $run, 'older' => 0, 'nested' => true, 'show' => 'open'])
    @endforeach

    @if ($batches->count() > 1)
        <tr class="run-older" x-show="open" x-cloak wire:key="older-toggle-{{ $group->first()->backup_schedule_id }}">
            <td></td>
            <td colspan="5">
                <button class="run-older-toggle" type="button" @click="older = ! older" :aria-expanded="older">
                    <x-menu-icon name="chevron-right" class="size-3.5" ::class="older && 'rotate-90'" />
                    <span x-text="older ? 'Hide older runs' : '{{ $batches->count() - 1 }} older {{ $batches->count() === 2 ? 'run' : 'runs' }}'"></span>
                </button>
            </td>
        </tr>

        @foreach ($batches->slice(1) as $batch)
            @php($info = $summary($batch))
            <tr class="run-older run-older-head" x-show="open && older" x-cloak wire:key="older-run-{{ $info['first']->id }}-{{ $info['status'] }}">
                <td></td>
                <td class="text-[12.5px] font-medium">Run of {{ $time->datetime($info['first']->created_at) }} <span class="font-normal text-muted">· {{ $batch->count() }} {{ $batch->count() === 1 ? 'archive' : 'archives' }}</span></td>
                <td><x-status-badge :status="$info['status']" /></td>
                <td class="text-muted">{{ $info['bytes'] > 0 ? \Illuminate\Support\Number::fileSize($info['bytes'], precision: 1) : '—' }}</td>
                <td></td>
                <td></td>
            </tr>
            @foreach ($batch->sortBy('target') as $run)
                @include('livewire.backups.partials.run-row', ['run' => $run, 'older' => 0, 'nested' => true, 'show' => 'open && older'])
            @endforeach
        @endforeach
    @endif
</tbody>
