<?php

namespace App\Livewire\Groups;

use App\Livewire\PanelComponent;
use App\Models\Group;
use App\Support\Slugs;

class Form extends PanelComponent
{
    public ?Group $group = null;

    public string $name = '';

    public string $description = '';

    public function mount(?Group $group = null): void
    {
        if (! $group) {
            return;
        }

        $this->group = $group;
        $this->name = $group->name;
        $this->description = $group->description ?? '';
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($this->group) {
            $this->group->update([
                'name' => $this->name,
                'description' => $this->description !== '' ? $this->description : null,
            ]);

            $this->toastAfterRedirect('Group saved.');
            $this->redirect(route('groups.show', $this->group));

            return;
        }

        $group = Group::query()->create([
            'name' => $this->name,
            'slug' => Slugs::unique(Group::class, $this->name),
            'description' => $this->description !== '' ? $this->description : null,
        ]);

        $this->toastAfterRedirect('Group created.');
        $this->redirect(route('groups.show', $group));
    }

    public function render()
    {
        return view('livewire.groups.form')
            ->layout('layouts.app')
            ->title($this->group ? 'Edit group' : 'New group');
    }
}
