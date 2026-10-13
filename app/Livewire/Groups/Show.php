<?php

namespace App\Livewire\Groups;

use App\Livewire\PanelComponent;
use App\Models\Group;

class Show extends PanelComponent
{
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

    public function render()
    {
        return view('livewire.groups.show', [
            'websites' => $this->group->websites()->orderBy('name')->get(),
        ])->layout('layouts.app')->title($this->group->name);
    }
}
