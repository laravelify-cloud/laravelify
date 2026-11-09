<?php

namespace App\Livewire\Monitoring;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\Glitchtip;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Throwable;

/**
 * GlitchTip inside the panel: issues (errors), performance (transactions), and logs, read from
 * GlitchTip's API so nobody has to open its own UI. On Monitoring it covers every website; on a
 * website's GlitchTip tab it shows only that website's project.
 */
class Errors extends PanelComponent
{
    public const SECTIONS = ['issues' => 'Issues', 'performance' => 'Performance', 'logs' => 'Logs'];

    public ?Website $website = null;

    #[Url(as: 'section', except: 'issues', history: true)]
    public string $section = 'issues';

    #[Url(as: 'errors_status', except: 'unresolved')]
    public string $status = 'unresolved';

    #[Url(as: 'errors_q', except: '')]
    public string $search = '';

    #[Url(as: 'errors_project', except: '')]
    public string $project = '';

    public string $sort = '';

    /** Issues: the trend's span (24h or 14d). Performance and logs: the time range. */
    public string $period = '24h';

    /** @var list<string> */
    public array $levels = [];

    /** @var list<string> cursors of the pages before this one, the last is this page's */
    public array $cursors = [];

    /** @var list<string> */
    public array $selected = [];

    #[Url(as: 'errors_issue', except: null)]
    public ?int $issueId = null;

    #[Url(as: 'errors_transaction', except: null)]
    public ?int $transactionId = null;

    public function mount(?Website $website = null, ?string $section = null): void
    {
        $this->website = $website;
        $this->section = array_key_exists((string) $section, self::SECTIONS) ? (string) $section : 'issues';
        $this->status = in_array($this->status, Glitchtip::ISSUE_STATUSES, true) ? $this->status : 'unresolved';
    }

    public function placeholder(): string
    {
        return '<div class="layer-card"><div class="layer-card-body flex items-center gap-3 text-muted"><span class="loader-spinner" style="width:16px;height:16px"></span> Loading error tracking…</div></div>';
    }

    public function setSection(string $section): void
    {
        $this->section = array_key_exists($section, self::SECTIONS) ? $section : 'issues';
        $this->reset('sort', 'cursors', 'selected', 'issueId', 'transactionId', 'search', 'levels');
        $this->period = '24h';
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, Glitchtip::ISSUE_STATUSES, true) ? $status : 'unresolved';
        $this->reset('cursors', 'selected');
    }

    public function setPeriod(string $period): void
    {
        $allowed = $this->section === 'issues' ? ['24h', '14d'] : array_keys(Glitchtip::PERIODS);
        $this->period = in_array($period, $allowed, true) ? $period : '24h';
        $this->reset('cursors');
    }

    public function toggleLevel(string $level): void
    {
        if (! in_array($level, Glitchtip::LOG_LEVELS, true)) {
            return;
        }

        $this->levels = in_array($level, $this->levels, true)
            ? array_values(array_diff($this->levels, [$level]))
            : [...$this->levels, $level];
        $this->reset('cursors');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'project', 'sort'], true)) {
            $this->reset('cursors', 'selected');
        }
    }

    public function nextPage(string $cursor): void
    {
        $this->cursors[] = $cursor;
        $this->selected = [];
    }

    public function previousPage(): void
    {
        array_pop($this->cursors);
        $this->selected = [];
    }

    public function openIssue(int $id): void
    {
        $this->issueId = $id;
    }

    public function openTransaction(int $id): void
    {
        $this->transactionId = $id;
    }

    public function back(): void
    {
        $this->reset('issueId', 'transactionId');
    }

    /**
     * Resolve, ignore, or reopen the ticked issues, or the open one.
     */
    public function markIssues(string $status, ?int $id = null): void
    {
        $ids = $id !== null ? [$id] : array_map('intval', $this->selected);

        if ($ids === [] || ! in_array($status, Glitchtip::ISSUE_STATUSES, true)) {
            return;
        }

        if ($this->website !== null && ! $this->ownsIssues($ids)) {
            $this->toast('Those issues are not this website\'s.', 'error');

            return;
        }

        try {
            app(Glitchtip::class)->setIssueStatus($ids, $status);
        } catch (Throwable $exception) {
            $this->toast('GlitchTip did not take it: '.$exception->getMessage(), 'error');

            return;
        }

        $this->selected = [];
        $count = count($ids);
        $this->toast(match ($status) {
            'resolved' => ($count === 1 ? 'Resolved' : $count.' issues resolved').'. It comes back if the error happens again.',
            'ignored' => ($count === 1 ? 'Ignored' : $count.' issues ignored').'. New events are still counted.',
            default => $count === 1 ? 'Reopened.' : $count.' issues reopened.',
        });
    }

    public function render(Glitchtip $glitchtip)
    {
        $data = [
            'installed' => $glitchtip->installed(),
            'connected' => $this->website === null || $this->website->glitchtip_project_id !== null,
            'projects' => [],
            'error' => null,
            'page' => null,
            'issue' => null,
            'transaction' => null,
            'logStats' => null,
        ];

        if ($data['installed'] && $data['connected']) {
            try {
                $data['projects'] = $this->website === null
                    ? Cache::remember('glitchtip.projects', 60, fn () => $glitchtip->projects())
                    : [(int) $this->website->glitchtip_project_id => $this->website->name];
                $data += $this->load($glitchtip);
            } catch (Throwable $exception) {
                $data['error'] = $exception->getMessage();
            }
        }

        $view = view('livewire.monitoring.errors', $data);

        return $this->website === null
            ? $view->layout('layouts.app')->title(self::SECTIONS[$this->section].' · Monitoring')
            : $view;
    }

    /**
     * What the open section shows.
     *
     * @return array<string, mixed>
     */
    private function load(Glitchtip $glitchtip): array
    {
        $cursor = $this->cursors === [] ? null : end($this->cursors);
        $filters = ['search' => $this->search, 'projects' => $this->projectIds(), 'sort' => $this->sort, 'period' => $this->period];

        if ($this->section === 'issues') {
            if ($this->issueId !== null) {
                $issue = $glitchtip->issueDetail($this->issueId);

                if ($this->website === null || $issue['projectId'] === (int) $this->website->glitchtip_project_id) {
                    return ['issue' => $issue + ['trend' => $glitchtip->issueTrends([$this->issueId], $this->period)[$this->issueId] ?? []]];
                }

                $this->issueId = null;
            }

            return ['page' => $glitchtip->issuePage($filters + ['status' => $this->status], $cursor)];
        }

        if ($this->section === 'performance') {
            if ($this->transactionId !== null) {
                $detail = $glitchtip->transactionDetail($this->transactionId, $this->period);

                if ($this->website === null || $detail['group']['projectId'] === (int) $this->website->glitchtip_project_id) {
                    return ['transaction' => $detail];
                }

                $this->transactionId = null;
            }

            return ['page' => $glitchtip->transactionPage($filters, $cursor)];
        }

        return [
            'page' => $glitchtip->logPage($filters + ['levels' => $this->levels], $cursor),
            'logStats' => $glitchtip->logStats($filters),
        ];
    }

    /**
     * The projects to show: the website's own, or the one picked on Monitoring (all when none).
     *
     * @return list<int>
     */
    private function projectIds(): array
    {
        if ($this->website !== null) {
            return [(int) $this->website->glitchtip_project_id];
        }

        return ctype_digit($this->project) ? [(int) $this->project] : [];
    }

    /**
     * Whether every one of these issues belongs to this website's project.
     *
     * @param  list<int>  $ids
     */
    private function ownsIssues(array $ids): bool
    {
        $projects = app(Glitchtip::class)->issueProjects($ids);

        return count($projects) === count(array_unique($ids))
            && collect($projects)->every(fn (int $project) => $project === (int) $this->website?->glitchtip_project_id);
    }
}
