{{-- The change log (checkpoints): each change made here, with Undo for just that row or object. --}}
@php($time = app(\App\Services\DisplayTime::class))
<div class="overflow-x-auto">
    <table class="table">
        <thead>
            <tr><th>Change</th><th>Where</th><th>Who</th><th>When</th><th class="w-56"></th></tr>
        </thead>
        <tbody>
            @forelse ($changes as $change)
                <tr wire:key="change-{{ $change->id }}-{{ $change->reverted_at ? 1 : 0 }}" x-data="{ open: false }">
                    <td class="max-w-md">
                        <button class="text-left" type="button" @click="open = ! open">
                            <span class="font-medium">{{ $change->summary() }}</span>
                            @if ($change->reverts_id)
                                <span class="pill">Undo of #{{ $change->reverts_id }}</span>
                            @endif
                            @if ($change->reverted_at)
                                <span class="pill">Undone</span>
                            @endif
                        </button>
                        <div class="mt-2 grid gap-2" x-show="open" x-cloak>
                            @if ($change->isRow())
                                @if ($change->action === 'update')
                                    @foreach ($change->changedColumns() as $column)
                                        <p class="font-mono text-[12px]"><span class="text-muted">{{ $column }}:</span> <span class="line-through opacity-70">{{ \Illuminate\Support\Str::limit(json_encode($change->before[$column] ?? null, JSON_UNESCAPED_UNICODE), 120) }}</span> → {{ \Illuminate\Support\Str::limit(json_encode($change->after[$column] ?? null, JSON_UNESCAPED_UNICODE), 120) }}</p>
                                    @endforeach
                                @else
                                    <pre class="sql-block max-h-48 overflow-auto whitespace-pre-wrap text-[12px]">{{ json_encode($change->before ?? $change->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                @endif
                            @else
                                <pre class="sql-block max-h-48 overflow-auto whitespace-pre-wrap text-[12px]">{{ $change->sql_before ?? $change->sql_after }}</pre>
                            @endif
                        </div>
                    </td>
                    <td class="font-mono text-[12.5px] text-muted">
                        <a class="hover:text-ink" href="{{ $change->table ? route('database.table', [$change->database, $change->table]) : route('database.show', $change->database) }}" wire:navigate>{{ $change->database }}{{ $change->table ? '.'.$change->table : '' }}</a>
                    </td>
                    <td class="text-muted">{{ $change->user?->name ?? '—' }}</td>
                    <td class="text-muted">{{ $time->datetime($change->created_at) }}</td>
                    <td>
                        <div class="row-actions w-full">
                            @if ($change->snapshot)
                                <button class="btn btn-sm" type="button" wire:click="restoreCopy({{ $change->id }})" wire:confirm="Put the table {{ $change->table }} back exactly as it was before this delete? Changes made to it since then are lost.">Restore table copy</button>
                            @endif
                            @unless ($change->reverted_at)
                                <button class="btn btn-sm" type="button" wire:click="undoChange({{ $change->id }})" wire:confirm="Undo: {{ $change->summary() }}? Only this {{ $change->isRow() ? 'row' : $change->object_type }} changes.">
                                    <x-menu-icon name="restore" class="size-4" /> Undo
                                </button>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="table-empty">No changes yet. Rows you add, edit, or delete here, and triggers, events, procedures, or functions you change, are listed here so you can undo them.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-modal-frame class="modal-medium" open="$wire.undoConflict !== null" close="$wire.set('undoConflict', null)">
    <div class="grid gap-4">
        <div class="modal-head">
            <h2>The row changed since</h2>
            <button class="icon-btn" type="button" wire:click="$set('undoConflict', null)" aria-label="Close"><x-menu-icon name="close" /></button>
        </div>
        <p class="text-[13.5px]">{{ $undoConflict['message'] ?? '' }}</p>
        <div class="flex justify-end gap-2">
            <button class="btn" type="button" wire:click="$set('undoConflict', null)">Keep it as it is</button>
            <button class="btn btn-danger" type="button" wire:click="undoAnyway">Undo anyway</button>
        </div>
    </div>
</x-modal-frame>
