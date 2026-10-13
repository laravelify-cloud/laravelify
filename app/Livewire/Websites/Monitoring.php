<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Heartbeat;
use App\Models\SiteIssue;
use App\Models\SiteRequest;
use App\Models\Website;
use App\Services\HostWriter;
use App\Services\SiteMetrics;
use App\Services\SiteSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Throwable;

/**
 * Nightwatch-style monitoring for one website: traffic, response times,
 * uptime, grouped Laravel errors, and attack detection with IP blocking.
 */
#[Lazy]
class Monitoring extends PanelComponent
{
    use WithPagination;

    public const VIEWS = ['overview' => 'Overview', 'requests' => 'Requests', 'issues' => 'Issues', 'heartbeats' => 'Heartbeats', 'security' => 'Security'];

    public const FILTERS = ['all' => 'All', 'ok' => '1/2/3xx', '4xx' => '4xx', '5xx' => '5xx', 'slow' => 'Slow', 'threats' => 'Threats'];

    public Website $website;

    #[Url(as: 'range', except: '24h')]
    public string $range = '24h';

    #[Url(as: 'view', except: 'overview')]
    public string $view = 'overview';

    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';

    public string $search = '';

    public string $issueStatus = 'open';

    public ?int $issueId = null;

    public string $retention_days = '7';

    public string $slow_ms = '2000';

    public string $error_threshold = '20';

    public string $heartbeat_name = '';

    public string $heartbeat_interval = '5';

    public function mount(Website $website): void
    {
        $this->website = $website;
        $this->range = SiteMetrics::range($this->range);
        $this->view = array_key_exists($this->view, self::VIEWS) ? $this->view : 'overview';
        $this->filter = array_key_exists($this->filter, self::FILTERS) ? $this->filter : 'all';
        $this->retention_days = (string) ($website->monitor_retention_days ?? 7);
        $this->slow_ms = (string) ($website->monitor_slow_ms ?? 2000);
        $this->error_threshold = (string) ($website->monitor_error_threshold ?? 20);
    }

    public function placeholder(): string
    {
        return '<div class="layer-card"><div class="layer-card-head"><h2>Monitoring</h2></div><div class="layer-card-body flex items-center gap-3 text-muted"><span class="loader-spinner" style="width:16px;height:16px"></span> Loading stats…</div></div>';
    }

    public function setRange(string $range): void
    {
        $this->range = SiteMetrics::range($range);
        $this->resetPage('requests');
    }

    public function setView(string $view): void
    {
        $this->view = array_key_exists($view, self::VIEWS) ? $view : 'overview';
        $this->issueId = null;
        $this->resetPage('requests');
    }

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTERS) ? $filter : 'all';
        $this->resetPage('requests');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('requests');
    }

    public function showRequests(string $filter): void
    {
        $this->view = 'requests';
        $this->setFilter($filter);
    }

    public function setIssueStatus(string $status): void
    {
        $this->issueStatus = in_array($status, SiteIssue::STATUSES, true) ? $status : 'open';
        $this->issueId = null;
    }

    public function openIssue(int $id): void
    {
        $this->view = 'issues';
        $this->issueId = $this->issue($id)?->id;
    }

    public function closeIssue(): void
    {
        $this->issueId = null;
    }

    public function markIssue(int $id, string $status): void
    {
        $issue = $this->issue($id);

        if ($issue === null || ! in_array($status, SiteIssue::STATUSES, true)) {
            return;
        }

        $issue->update(['status' => $status, 'resolved_at' => $status === 'resolved' ? now() : null]);
        $this->toast(match ($status) {
            'resolved' => 'Marked as resolved. It reopens and alerts if it happens again.',
            'ignored' => 'Ignored. New occurrences are counted without alerts.',
            default => 'Issue reopened.',
        });
    }

    public function blockIp(string $ip, HostWriter $hostWriter): void
    {
        $ip = trim($ip);

        if (! SiteSecurity::validIp($ip) || in_array($ip, ['127.0.0.1', '::1'], true)) {
            $this->toast('That is not an IP that can be blocked.', 'error');

            return;
        }

        $blocked = SiteSecurity::parseIps((string) $this->website->blocked_ips);

        if (in_array($ip, $blocked, true)) {
            $this->toast($ip.' is already blocked.', 'warning');

            return;
        }

        $this->applyBlockedIps([...$blocked, $ip], $hostWriter, $ip.' is blocked. nginx answers it with 403.');
    }

    public function unblockIp(string $ip, HostWriter $hostWriter): void
    {
        $blocked = SiteSecurity::parseIps((string) $this->website->blocked_ips);
        $this->applyBlockedIps(array_values(array_diff($blocked, [trim($ip)])), $hostWriter, trim($ip).' is unblocked.');
    }

    public function addHeartbeat(): void
    {
        $this->validate([
            'heartbeat_name' => ['required', 'string', 'max:80'],
            'heartbeat_interval' => ['required', 'integer', Rule::in(array_keys(Heartbeat::INTERVALS))],
        ], [], ['heartbeat_name' => 'name']);

        $this->website->heartbeats()->create([
            'name' => trim($this->heartbeat_name),
            'interval_minutes' => (int) $this->heartbeat_interval,
        ]);

        $this->reset('heartbeat_name');
        $this->toast('Heartbeat added. Call its URL when your job finishes.');
    }

    public function deleteHeartbeat(int $id): void
    {
        $this->website->heartbeats()->whereKey($id)->delete();
        $this->toast('Heartbeat removed.');
    }

    public function saveSettings(): void
    {
        $this->validate([
            'retention_days' => ['required', 'integer', 'in:1,3,7,14,30,60,90'],
            'slow_ms' => ['required', 'integer', 'min:100', 'max:60000'],
            'error_threshold' => ['required', 'integer', 'min:1', 'max:100000'],
        ], [
            'retention_days.in' => 'Pick 1, 3, 7, 14, 30, 60, or 90 days.',
        ]);

        $this->website->update([
            'monitor_retention_days' => (int) $this->retention_days,
            'monitor_slow_ms' => (int) $this->slow_ms,
            'monitor_error_threshold' => (int) $this->error_threshold,
        ]);

        $this->dispatch('saved');
        $this->toast('Monitoring settings saved.');
    }

    /**
     * Re-applies the site's nginx file with the include that writes the request log.
     */
    public function enableTracking(HostWriter $hostWriter, SiteSecurity $security): void
    {
        if (! $security->hasInclude($this->website) && ! $security->ensureInclude($this->website)) {
            $this->toast('Could not find a server_name line in the Nginx tab. Add this inside each server block: '.trim($security->includeLine($this->website)), 'error');

            return;
        }

        try {
            $hostWriter->writeNginx($this->website);
            $this->toast('Request tracking is on. Stats appear within a minute of the next visit.');
        } catch (Throwable $exception) {
            $this->toast('Applying nginx failed: '.$exception->getMessage(), 'error');
        }
    }

    public function render(SiteMetrics $metrics, SiteSecurity $security)
    {
        $this->website->refresh();
        $data = [
            'tracking' => $security->hasInclude($this->website) && is_file(SiteSecurity::metricsLog($this->website)),
            'blockedIps' => $security->blockedIps($this->website),
            'openIssues' => $this->website->issues()->where('status', 'open')->count(),
        ];

        if ($this->view === 'overview') {
            $data += [
                'traffic' => $metrics->traffic($this->website, $this->range),
                'uptime' => $metrics->uptime($this->website, $this->range),
                'routes' => $metrics->routes($this->website, $this->range, 8),
                'countries' => $metrics->countries($this->website, $this->range, 6),
                'threats' => $metrics->threats($this->website, $this->range),
                'recentIssues' => $this->website->issues()->where('status', 'open')->latest('last_seen_at')->limit(5)->get(),
            ];
        }

        if ($this->view === 'requests') {
            $data += [
                'traffic' => $metrics->traffic($this->website, $this->range),
                'routes' => $metrics->routes($this->website, $this->range, 15),
                'requests' => $this->requestQuery()->latest('at')->latest('id')->paginate(25, pageName: 'requests'),
            ];
        }

        if ($this->view === 'issues') {
            $data += [
                'issues' => $this->website->issues()->where('status', $this->issueStatus)->latest('last_seen_at')->limit(100)->get(),
                'issueCounts' => $this->website->issues()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'issue' => $this->issueId ? $this->issue($this->issueId) : null,
            ];
        }

        if ($this->view === 'heartbeats') {
            $data['heartbeats'] = $this->website->heartbeats()->orderBy('name')->get();
        }

        if ($this->view === 'security') {
            $data += [
                'threats' => $metrics->threats($this->website, $this->range),
                'attackers' => $metrics->attackers($this->website, $this->range),
                'threatRequests' => SiteRequest::query()
                    ->where('website_id', $this->website->id)
                    ->where('at', '>=', SiteMetrics::since($this->range))
                    ->where('risk', '>', 0)
                    ->latest('at')->latest('id')
                    ->paginate(20, pageName: 'requests'),
            ];
        }

        return view('livewire.websites.monitoring', $data);
    }

    /**
     * @return Builder<SiteRequest>
     */
    private function requestQuery(): Builder
    {
        $query = SiteRequest::query()
            ->where('website_id', $this->website->id)
            ->where('at', '>=', SiteMetrics::since($this->range));

        match ($this->filter) {
            'ok' => $query->where('status', '<', 400),
            '4xx' => $query->whereBetween('status', [400, 499]),
            '5xx' => $query->where('status', '>=', 500),
            'slow' => $query->where('duration_ms', '>=', (int) $this->website->monitor_slow_ms),
            'threats' => $query->where('risk', '>', 0),
            default => null,
        };

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(fn (Builder $inner) => $inner
                ->where('uri', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                ->orWhere('ip', $search));
        }

        return $query;
    }

    private function issue(int $id): ?SiteIssue
    {
        return $this->website->issues()->whereKey($id)->first();
    }

    /**
     * @param  list<string>  $ips
     */
    private function applyBlockedIps(array $ips, HostWriter $hostWriter, string $message): void
    {
        $this->website->update(['blocked_ips' => implode("\n", $ips) ?: null]);

        try {
            $hostWriter->writeNginx($this->website);
            $this->toast($message);
        } catch (Throwable $exception) {
            $this->toast('Saved, but applying nginx failed: '.$exception->getMessage(), 'error');
        }

        $this->dispatch('blocked-ips-changed', ips: implode("\n", $ips));
    }
}
