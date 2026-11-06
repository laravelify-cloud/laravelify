<?php

namespace App\Livewire\Monitoring;

use App\Livewire\PanelComponent;
use App\Services\GlitchtipInsights;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Throwable;

/**
 * GlitchTip inside the Monitoring page: issues (with the stack trace of the latest one), slow
 * transactions, and logs, for every website or one. No second tab, no second sign-in.
 */
#[Lazy]
class Glitchtip extends PanelComponent
{
    /** The page being shown (GlitchTip's cursor) and where it starts, for "26–50 of 120". */
    public ?string $cursor = null;

    public int $offset = 0;

    /** The total from the first page: later pages count only what is left. */
    public ?int $total = null;

    /** issues, performance or logs; kept in the address (?gt=) so a reload stays on it. */
    #[Url(as: 'gt', except: 'issues', history: true)]
    public string $section = 'issues';

    /** On a website's page: only that website, with its own Issues / Performance / Logs switch. */
    public ?int $lockedProject = null;

    #[Url(as: 'site')]
    public ?int $project = null;

    #[Url]
    public string $period = '24h';

    #[Url(as: 'q')]
    public string $query = '';

    #[Url]
    public string $status = 'unresolved';

    #[Url]
    public string $level = '';

    #[Url]
    public string $sort = '-avg_duration';

    /** The issue whose latest event is open. */
    public ?string $open = null;

    /** @var array<string, mixed>|null */
    public ?array $event = null;

    public ?string $eventError = null;

    public function toggleIssue(string $id, GlitchtipInsights $insights): void
    {
        if ($this->open === $id) {
            $this->open = null;
            $this->event = null;

            return;
        }

        $this->open = $id;
        $this->eventError = null;

        try {
            $this->event = $insights->latestEvent($id);
        } catch (Throwable $exception) {
            $this->event = null;
            $this->eventError = 'The latest event could not be loaded: '.$exception->getMessage();
        }
    }

    public function setStatus(string $id, string $status, GlitchtipInsights $insights): void
    {
        try {
            $insights->setStatus($id, $status);
        } catch (Throwable $exception) {
            $this->toast('GlitchTip did not take the change: '.$exception->getMessage(), 'error');

            return;
        }

        $this->open = null;
        $this->event = null;
        $this->toast(['resolved' => 'Marked as resolved.', 'ignored' => 'Ignored: it stays quiet unless it changes.', 'unresolved' => 'Opened again.'][$status]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['project', 'period', 'query', 'status', 'level', 'sort'], true)) {
            $this->firstPage();
        }
    }

    public function goTo(?string $cursor, int $direction): void
    {
        $this->cursor = $cursor;
        $this->offset = max(0, $this->offset + $direction * GlitchtipInsights::PAGE_SIZE);
        $this->open = null;
        $this->event = null;
    }

    private function firstPage(): void
    {
        $this->cursor = null;
        $this->offset = 0;
        $this->total = null;
        $this->open = null;
        $this->event = null;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="flex flex-col gap-3" aria-busy="true">
                <div class="gt-skeleton" style="height: 64px"></div>
                <div class="gt-skeleton" style="height: 44px"></div>
                <div class="gt-skeleton" style="height: 44px"></div>
                <div class="gt-skeleton" style="height: 44px"></div>
                <p class="text-[12.5px] text-muted">Loading from GlitchTip…</p>
            </div>
            HTML;
    }

    public function mount(?int $lockedProject = null): void
    {
        if ($lockedProject !== null) {
            $this->lockedProject = $lockedProject;
            $this->project = $lockedProject;
        }
    }

    public function showSection(string $section): void
    {
        $this->section = in_array($section, ['issues', 'performance', 'logs'], true) ? $section : 'issues';
        $this->firstPage();
    }

    public function render(GlitchtipInsights $insights): View
    {
        if ($this->lockedProject !== null) {
            $this->project = $this->lockedProject;
        }

        $rows = [];
        $page = ['next' => null, 'previous' => null, 'total' => null];
        $counts = [];
        $error = null;
        $available = $insights->available();

        if ($available) {
            $filters = ['project' => $this->project, 'period' => $this->period, 'query' => trim($this->query), 'status' => $this->status, 'level' => $this->level, 'sort' => $this->sort];

            try {
                $page = match ($this->section) {
                    'performance' => $insights->transactions($filters, $this->cursor),
                    'logs' => $insights->logs($filters, $this->cursor),
                    default => $insights->issues($filters, $this->cursor),
                };
                $rows = $page['rows'];

                if ($this->cursor === null || $this->total === null) {
                    $this->total = $page['total'];
                }

                $page['total'] = $this->total;
                $counts = $this->section === 'logs' ? $insights->logCounts($filters) : [];
            } catch (Throwable $exception) {
                $error = 'GlitchTip did not answer: '.$exception->getMessage();
            }
        }

        return view('livewire.monitoring.glitchtip', [
            'available' => $available,
            'rows' => $rows,
            'page' => $page,
            'counts' => $counts,
            'error' => $error,
            'projects' => $available ? $insights->projects() : [],
            'periods' => array_keys(GlitchtipInsights::PERIODS),
            'levels' => GlitchtipInsights::LOG_LEVELS,
            'sorts' => GlitchtipInsights::TRANSACTION_SORTS,
        ]);
    }
}
