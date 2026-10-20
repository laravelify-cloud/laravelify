<?php

namespace App\Livewire\Groups;

use App\Livewire\Concerns\SwitchesWebsites;
use App\Livewire\PanelComponent;
use App\Models\Group;

class Show extends PanelComponent
{
    use SwitchesWebsites;

    public Group $group;

    public function delete(): void
    {
        if (! $this->confirmed($this->group->name)) {
            return;
        }

        if ($this->group->websites()->exists()) {
            $this->addError('group', 'Remove the websites in this group first.');
            $this->toast('Remove the websites in this group first.', 'error');

            return;
        }

        $this->group->delete();
        $this->toastAfterRedirect('Group deleted.');

        $this->redirect(route('groups.index'));
    }

    /**
     * Turn the whole group, with every website in it, off or on in the background.
     */
    public function setGroupEnabled(bool $enabled): void
    {
        $this->startSwitch($enabled, [$this->group->id], [], ($enabled ? 'Turning on ' : 'Turning off ').$this->group->name);
        $this->group->refresh();
    }

    /**
     * Turn the chosen websites of this group off or on in the background.
     *
     * @param  list<int>  $ids
     */
    public function setWebsitesEnabled(array $ids, bool $enabled): void
    {
        $websites = $this->group->websites()->whereKey($ids)->orderBy('name')->get();
        $names = $websites->count() === 1 ? $websites->first()->name : $websites->count().' websites';

        $this->startSwitch($enabled, [], $websites->modelKeys(), ($enabled ? 'Turning on ' : 'Turning off ').$names);
    }

    /**
     * Delete the chosen websites in the background, after typing "delete".
     *
     * @param  list<int>  $ids
     */
    public function deleteWebsites(array $ids): void
    {
        if (! $this->confirmed('delete')) {
            return;
        }

        $websites = $this->group->websites()->whereKey($ids)->orderBy('name')->get();
        $names = $websites->count() === 1 ? $websites->first()->name : $websites->count().' websites';

        $this->startDelete($websites->modelKeys(), 'Deleting '.$names);
    }

    public function render()
    {
        $tasks = $this->switchTasks();

        return view('livewire.groups.show', [
            'tasks' => $tasks,
            'tasksActive' => $tasks->contains(fn ($task) => $task->isActive()),
            'switching' => $this->switchingWebsiteIds($tasks),
            'deleting' => $this->deletingWebsiteIds($tasks),
            'websites' => $this->group->websites()->orderBy('name')->get(),
        ])->layout('layouts.app')->title($this->group->name);
    }
}
