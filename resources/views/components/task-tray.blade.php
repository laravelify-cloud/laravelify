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
            @php
                $state = match ($task->status) {
                    'success' => ['label' => 'Done', 'icon' => 'check'],
                    'failed' => ['label' => 'Failed', 'icon' => 'danger'],
                    'queued' => ['label' => 'Waiting', 'icon' => null],
                    default => ['label' => $task->progress.'%', 'icon' => null],
                };
            @endphp

            <div class="task-row is-{{ $task->status }}" wire:key="task-{{ $task->id }}">
                <span class="task-icon">
                    @if ($state['icon'])
                        <x-menu-icon name="{{ $state['icon'] }}" class="size-4" />
                    @else
                        <span class="task-spinner" aria-hidden="true"></span>
                    @endif
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ $task->label }}</p>

                    @if ($task->isActive())
                        <div @class(['task-bar', 'is-waiting' => $task->status === 'queued'])><span style="width: {{ max(4, $task->progress) }}%"></span></div>
                    @endif

                    @if ($task->isStuck())
                        <p class="task-note">Waiting for the queue worker. If it does not start, check it on the server: <code>sudo supervisorctl status laravelify-queue</code></p>
                    @elseif ($task->status === 'failed' && $task->message)
                        <p class="task-note is-danger">{{ $task->message }}</p>
                    @elseif ($task->status === 'success' && $task->message)
                        <p class="task-note">{{ $task->message }}</p>
                    @endif
                </div>

                <div class="task-side">
                    <span class="task-state">{{ $state['label'] }}</span>
                    @if ($task->finished_at)
                        <span class="task-time">{{ app(\App\Services\DisplayTime::class)->ago($task->finished_at, true) }}</span>
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
