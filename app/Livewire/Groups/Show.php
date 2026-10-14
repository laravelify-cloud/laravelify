<?php

namespace App\Livewire\Groups;

use App\Livewire\PanelComponent;
use App\Models\Group;
use App\Services\WebsiteSwitch;
use Throwable;

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

    /**
     * Turn the whole group, with every website in it, off or on.
     */
    public function setGroupEnabled(WebsiteSwitch $switch, bool $enabled): void
    {
        $errors = $switch->setGroup($this->group, $enabled);

        $this->toast($errors === []
            ? ($enabled ? 'The group and its websites are on.' : 'The group and its websites are turned off.')
            : 'Some websites could not be switched: '.implode(' · ', $errors), $errors === [] ? 'success' : 'error');
    }

    /**
     * @param  list<int>  $ids
     */
    public function setWebsitesEnabled(WebsiteSwitch $switch, array $ids, bool $enabled): void
    {
        $done = 0;
        $errors = [];

        foreach ($this->group->websites()->whereKey($ids)->get() as $website) {
            if ($website->enabled === $enabled) {
                continue;
            }

            try {
                $enabled ? $switch->enable($website) : $switch->disable($website);
                $done++;
            } catch (Throwable $exception) {
                $errors[] = $website->name.': '.$exception->getMessage();
            }
        }

        if ($enabled && $done > 0 && ! $this->group->enabled) {
            $this->group->forceFill(['enabled' => true])->save();
        }

        $this->dispatch('bulk-done');
        $this->toast($errors === []
            ? ($enabled ? 'Turned on ' : 'Turned off ').$done.' '.($done === 1 ? 'website' : 'websites').'.'
            : 'Some websites could not be switched: '.implode(' · ', $errors), $errors === [] ? 'success' : 'error');
    }

    /**
     * Delete the chosen websites, after typing "delete".
     *
     * @param  list<int>  $ids
     */
    public function deleteWebsites(WebsiteSwitch $switch, array $ids): void
    {
        if (! $this->confirmed('delete')) {
            return;
        }

        $deleted = 0;
        $errors = [];

        foreach ($this->group->websites()->whereKey($ids)->get() as $website) {
            try {
                $switch->delete($website);
                $deleted++;
            } catch (Throwable $exception) {
                $errors[] = $website->name.': '.$exception->getMessage();
            }
        }

        $this->dispatch('bulk-done');
        $this->toast($errors === []
            ? 'Deleted '.$deleted.' '.($deleted === 1 ? 'website' : 'websites').'. Their folders in /var/www are kept.'
            : 'Some websites could not be deleted: '.implode(' · ', $errors), $errors === [] ? 'success' : 'error');
    }

    public function render()
    {
        return view('livewire.groups.show', [
            'websites' => $this->group->websites()->orderBy('name')->get(),
        ])->layout('layouts.app')->title($this->group->name);
    }
}
