<?php

namespace App\Livewire\Concerns;

use App\Models\Group;
use App\Models\PanelTask;
use App\Models\Website;
use App\Services\PanelTaskRunner;
use Illuminate\Support\Collection;

/**
 * Turning websites and groups off and on in the queue, with the progress on the page.
 */
trait SwitchesWebsites
{
    /** @var list<int> Switch tasks whose end was already shown. */
    public array $announcedSwitches = [];

    public function mountSwitchesWebsites(): void
    {
        $this->announcedSwitches = PanelTask::query()->where('scope', self::switchScope())
            ->whereIn('status', [PanelTask::SUCCESS, PanelTask::FAILED])->pluck('id')->all();
    }

    public static function switchScope(): string
    {
        return 'websites';
    }

    /**
     * @param  list<int>  $groupIds
     * @param  list<int>  $websiteIds
     */
    protected function startSwitch(bool $enabled, array $groupIds, array $websiteIds, string $label): void
    {
        if ($groupIds === [] && $websiteIds === []) {
            $this->toast('Nothing selected.', 'warning');

            return;
        }

        $task = app(PanelTaskRunner::class)->start('websites.switch', self::switchScope(), $label, [
            'enabled' => $enabled,
            'group_ids' => array_values($groupIds),
            'website_ids' => array_values($websiteIds),
        ]);

        $this->dispatch('bulk-done');
        $task->isFinished() ? $this->pollTasks() : $this->toast($label.' in the background. Follow each website below.', 'info');
    }

    public function pollTasks(): void
    {
        $finished = PanelTask::query()->where('scope', self::switchScope())
            ->whereIn('status', [PanelTask::SUCCESS, PanelTask::FAILED])
            ->whereNotIn('id', $this->announcedSwitches ?: [0])
            ->get();

        foreach ($finished as $task) {
            $this->announcedSwitches[] = $task->id;
            $this->toast((string) $task->message, $task->status === PanelTask::FAILED ? 'error' : 'success');
        }
    }

    public function dismissTask(int $id): void
    {
        $task = PanelTask::query()->where('scope', self::switchScope())->find($id);

        if ($task && ! $task->isActive()) {
            $task->delete();
        }
    }

    /**
     * @return Collection<int, PanelTask>
     */
    protected function switchTasks(): Collection
    {
        return PanelTask::query()->where('scope', self::switchScope())
            ->where('updated_at', '>', now()->subHours(6))->latest('id')->limit(4)->get();
    }

    /**
     * Websites a running task has not finished with yet, so their rows show it.
     *
     * @param  Collection<int, PanelTask>  $tasks
     * @return list<int>
     */
    protected function switchingWebsiteIds(Collection $tasks): array
    {
        $active = $tasks->filter->isActive();

        if ($active->isEmpty()) {
            return [];
        }

        $groupIds = $active->flatMap(fn (PanelTask $task) => (array) ($task->payload['group_ids'] ?? []))->all();
        $websiteIds = $active->flatMap(fn (PanelTask $task) => (array) ($task->payload['website_ids'] ?? []))->all();
        $done = $active->flatMap(fn (PanelTask $task) => array_column((array) ($task->result['lines'] ?? []), 'id'))->all();

        return Website::query()
            ->where(fn ($query) => $query->whereIn('group_id', $groupIds)->orWhereIn('id', $websiteIds))
            ->whereNotIn('id', $done)->pluck('id')->all();
    }

    /**
     * @param  Collection<int, PanelTask>  $tasks
     * @return list<int>
     */
    protected function switchingGroupIds(Collection $tasks): array
    {
        $ids = $tasks->filter->isActive()->flatMap(fn (PanelTask $task) => (array) ($task->payload['group_ids'] ?? []))->all();

        return Group::query()->whereKey($ids)->pluck('id')->all();
    }
}
