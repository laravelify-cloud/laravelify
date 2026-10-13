{{-- Background tasks of a page (zips, deletes, backup reads and exports) with live progress. --}}
@props([
    'tasks',
    'active' => false,
    'poll' => 'pollTasks',
    'dismiss' => 'dismissTask',
])

@if ($tasks->isNotEmpty())
    <div {{ $attributes->class(['task-tray']) }} @if ($active) wire:poll.1500ms="{{ $poll }}" @endif aria-live="polite">
        @foreach ($tasks as $task)
            <div class="task-row is-{{ $task->status }}" wire:key="task-{{ $task->id }}">
                <span class="task-icon">
                    @if ($task->isActive())
                        <span class="task-spinner" aria-hidden="true"></span>
                    @elseif ($task->status === 'success')
                        <x-menu-icon name="check" class="size-4" />
                    @else
                        <x-menu-icon name="danger" class="size-4" />
                    @endif
                </span>

                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="truncate font-medium">{{ $task->label }}</span>
                        <span class="shrink-0 text-[12px] tabular-nums text-muted">
                            @if ($task->status === 'queued')
                                Waiting
                            @elseif ($task->status === 'running')
                                {{ $task->progress }}%
                            @elseif ($task->status === 'success')
                                Done
                            @else
                                Failed
                            @endif
                        </span>
                    </div>

                    @if ($task->isActive())
                        <div @class(['task-bar', 'is-waiting' => $task->status === 'queued'])><span style="width: {{ max(4, $task->progress) }}%"></span></div>
                    @endif

                    @if ($task->isStuck())
                        <p class="mt-1 text-[12px] text-muted">Waiting for the queue worker. If this does not start, check it on the server: <code>sudo supervisorctl status laravelify-queue</code></p>
                    @elseif ($task->status === 'failed' && $task->message)
                        <p class="mt-1 text-[12px] text-danger">{{ $task->message }}</p>
                    @elseif ($task->status === 'success' && $task->message)
                        <p class="mt-0.5 text-[12px] text-muted">{{ $task->message }}</p>
                    @endif
                </div>

                @if ($task->status === 'success' && $task->resultFile())
                    <a class="btn btn-primary btn-sm" href="{{ route('tasks.download', $task) }}"><x-menu-icon name="download" /> Download</a>
                @endif

                @unless ($task->isActive())
                    <button class="icon-btn" type="button" wire:click="{{ $dismiss }}({{ $task->id }})" title="Dismiss" aria-label="Dismiss"><x-menu-icon name="close" class="size-4" /></button>
                @endunless
            </div>
        @endforeach
    </div>
@endif
