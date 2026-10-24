{{-- Background tasks this page started (App\Livewire\Concerns\FollowsTasks), until each is done. --}}
@if ($followed)
    <div class="followed-tasks" wire:poll.2s="pollFollowedTasks" role="status">
        @foreach ($this->followedTasks() as $task)
            <div class="followed-task">
                <span class="task-spinner"></span>
                <span class="min-w-0 truncate">
                    <b>{{ $task['label'] }}</b>
                    <span class="text-muted">· {{ $task['queued'] ? 'waiting for the background worker' : ($task['message'] ?: 'running in the background') }}</span>
                </span>
            </div>
        @endforeach
    </div>
@endif
