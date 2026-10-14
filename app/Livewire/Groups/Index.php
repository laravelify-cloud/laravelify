<?php

namespace App\Livewire\Groups;

use App\Livewire\Concerns\SwitchesWebsites;
use App\Livewire\PanelComponent;
use App\Models\Group;

class Index extends PanelComponent
{
    use SwitchesWebsites;

    /**
     * Turn the chosen groups, with all their websites, on or off in the background.
     *
     * @param  list<int>  $ids
     */
    public function setEnabled(array $ids, bool $enabled): void
    {
        $groups = Group::query()->whereKey($ids)->orderBy('name')->get();
        $names = $groups->count() === 1 ? $groups->first()->name : $groups->count().' groups';

        $this->startSwitch($enabled, $groups->modelKeys(), [], ($enabled ? 'Turning on ' : 'Turning off ').$names);
    }

    /**
     * Delete the chosen groups. A group with websites is kept: delete its websites first.
     *
     * @param  list<int>  $ids
     */
    public function deleteMany(array $ids): void
    {
        if (! $this->confirmed('delete')) {
            return;
        }

        $groups = Group::query()->withCount('websites')->whereKey($ids)->get();
        $kept = $groups->where('websites_count', '>', 0);
        $deleted = 0;

        foreach ($groups->where('websites_count', 0) as $group) {
            $group->delete();
            $deleted++;
        }

        $this->dispatch('bulk-done');
        $this->toast(
            ($deleted > 0 ? 'Deleted '.$deleted.' '.($deleted === 1 ? 'group' : 'groups').'.' : 'Nothing was deleted.')
            .($kept->isNotEmpty() ? ' Kept '.$kept->pluck('name')->implode(', ').': delete their websites first.' : ''),
            $kept->isNotEmpty() ? 'warning' : 'success',
        );
    }

    public function render()
    {
        $tasks = $this->switchTasks();

        return view('livewire.groups.index', [
            'tasks' => $tasks,
            'tasksActive' => $tasks->contains(fn ($task) => $task->isActive()),
            'switching' => $this->switchingGroupIds($tasks),
            'groups' => Group::query()->withCount([
                'websites',
                'websites as disabled_count' => fn ($query) => $query->where('enabled', false),
            ])->orderBy('name')->get(),
        ])->layout('layouts.app')->title('Groups');
    }
}
