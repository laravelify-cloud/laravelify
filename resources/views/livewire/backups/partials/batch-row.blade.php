{{-- One run of a file schedule that made several archives (one per website or folder). The archives hang under it. --}}
@php
    $first = $group->sortBy('id')->first();
    $ids = $group->filter->restorable()->pluck('id')->values()->all();
    $running = $group->whereIn('status', ['queued', 'running'])->count();
    $failed = $group->where('status', 'failed')->count();
    $status = $running > 0 ? 'running' : ($failed > 0 ? 'failed' : 'success');
    $bytes = $group->sum('size_bytes');
    $finished = $group->max('finished_at');
@endphp
<tr wire:key="batch-{{ $first->batch }}-{{ $status }}" class="run-batch">
    <td>
        @if ($ids !== [])
            <input type="checkbox" aria-label="Select every backup of this run" :checked="@js($ids).every((id) => selected.includes(id))" @change="selected = $event.target.checked ? [...new Set([...selected, ...@js($ids)])] : selected.filter((id) => !@js($ids).includes(id))">
        @endif
    </td>
    <td class="max-w-xs">
        <button class="flex items-center gap-2 text-left" type="button" @click="open = ! open" :aria-expanded="open">
            <x-menu-icon name="chevron-right" class="size-4 shrink-0 text-muted transition-transform" ::class="open && 'rotate-90'" />
            <x-menu-icon name="backup" class="size-4 shrink-0 text-muted" />
            <span class="truncate font-semibold uppercase tracking-wide text-[12.5px]">{{ $first->schedule?->name ?? 'Deleted schedule' }}</span>
        </button>
        <span class="block truncate pl-6 text-[12px] text-muted">
            {{ $group->count() }} {{ $group->count() === 1 ? 'archive' : 'archives' }}, one per {{ ! empty($first->schedule?->targets['websites']) ? 'website' : 'folder' }} · {{ $first->trigger === 'manual' ? 'Run now' : 'Scheduled' }}
        </span>
    </td>
    <td>
        @if ($status === 'running')
            <span class="flex items-center gap-2 text-[12.5px]"><span class="task-spinner shrink-0"></span> {{ $group->count() - $running }} of {{ $group->count() }} done</span>
        @else
            <x-status-badge :status="$status" />
            @if ($failed > 0)
                <span class="block text-[11.5px] text-muted">{{ $failed }} of {{ $group->count() }} failed</span>
            @endif
        @endif
    </td>
    <td class="text-muted">{{ $bytes > 0 ? \Illuminate\Support\Number::fileSize($bytes, precision: 1) : '—' }}</td>
    <td class="text-muted">
        {{ $time->datetime($first->created_at) }}
        @if ($finished && $status !== 'running')
            <span class="block text-[12px]">{{ max(1, (int) $first->created_at->diffInSeconds($finished)) }}s</span>
        @endif
    </td>
    <td>
        <div class="row-actions w-full">
            <button class="btn btn-sm" type="button" @click="open = ! open" x-text="open ? 'Hide' : 'Show {{ $group->count() }}'"></button>
        </div>
    </td>
</tr>
