<?php

namespace App\Livewire\Groups;

use App\Livewire\PanelComponent;
use App\Models\Group;
use App\Services\WebsiteSwitch;

class Index extends PanelComponent
{
    /**
     * Turn the chosen groups, with all their websites, on or off.
     *
     * @param  list<int>  $ids
     */
    public function setEnabled(WebsiteSwitch $switch, array $ids, bool $enabled): void
    {
        $errors = [];
        $groups = Group::query()->whereKey($ids)->get();

        foreach ($groups as $group) {
            $errors = [...$errors, ...$switch->setGroup($group, $enabled)];
        }

        $this->dispatch('bulk-done');
        $this->toast($errors === []
            ? ($enabled ? 'Turned on ' : 'Turned off ').$groups->count().' '.($groups->count() === 1 ? 'group' : 'groups').' and their websites.'
            : 'Some websites could not be switched: '.implode(' · ', $errors), $errors === [] ? 'success' : 'error');
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
        return view('livewire.groups.index', [
            'groups' => Group::query()->withCount([
                'websites',
                'websites as disabled_count' => fn ($query) => $query->where('enabled', false),
            ])->orderBy('name')->get(),
        ])->layout('layouts.app')->title('Groups');
    }
}
