<?php

namespace App\Services;

use App\Models\Group;
use App\Models\PanelTask;
use App\Models\Website;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turning websites (or every website of a group) off and on, and deleting them.
 *
 * Off: the address shows a "turned off" page, queue workers and the Node process stop,
 * deploys and pushes are refused, and down alerts pause. Files, database, environment, and
 * settings stay, so turning it on brings back exactly what was there.
 */
class WebsiteSwitch
{
    public function __construct(private HostWriter $host, private ConfigRenderer $renderer, private CloudflareRoutes $cloudflare) {}

    public function disable(Website $website): void
    {
        $this->host->disableWebsite($website, $this->disabledNginx($website));
        $website->forceFill(['enabled' => false])->save();
    }

    public function enable(Website $website): void
    {
        $website->forceFill(['enabled' => true])->save();

        try {
            $this->host->writeWebsite($website);
        } catch (Throwable $exception) {
            $website->forceFill(['enabled' => false])->save();

            throw $exception;
        }
    }

    /**
     * Turn websites, and whole groups with every website in them, off or on in a background task.
     * The task shows which website it is on, and lists every website with how it went.
     *
     * @return array{message: string, failed: bool, lines: list<array{id: int, label: string, ok: bool, note: string}>}
     */
    public function switchTask(PanelTask $task): array
    {
        $payload = $task->payload ?? [];
        $enabled = (bool) ($payload['enabled'] ?? false);
        $groups = Group::query()->whereKey(array_map('intval', (array) ($payload['group_ids'] ?? [])))->get();
        $websiteIds = array_map('intval', (array) ($payload['website_ids'] ?? []));
        $websites = Website::query()
            ->where(fn ($query) => $query->whereIn('group_id', $groups->modelKeys())->orWhereIn('id', $websiteIds))
            ->orderBy('name')->get();
        $verb = $enabled ? 'Turning on' : 'Turning off';
        $lines = [];

        foreach ($websites->values() as $index => $website) {
            $task->report((int) floor($index / max(1, $websites->count()) * 100), $verb.' '.$website->name.' ('.($index + 1).' of '.$websites->count().')', true);

            if ($website->enabled === $enabled) {
                $lines[] = ['id' => $website->id, 'label' => $website->name, 'ok' => true, 'note' => $enabled ? 'Already on' : 'Already off'];

                continue;
            }

            try {
                $enabled ? $this->enable($website) : $this->disable($website);
                $lines[] = ['id' => $website->id, 'label' => $website->name, 'ok' => true, 'note' => $enabled ? 'Turned on' : 'Turned off'];
            } catch (Throwable $exception) {
                $lines[] = ['id' => $website->id, 'label' => $website->name, 'ok' => false, 'note' => Str::limit($exception->getMessage(), 300)];
            }

            $task->forceFill(['result' => ['lines' => $lines]])->save();
        }

        foreach ($groups as $group) {
            $group->forceFill(['enabled' => $enabled])->save();
        }

        if ($enabled && $websiteIds !== []) {
            Group::query()->whereKey($websites->whereIn('id', $websiteIds)->pluck('group_id')->unique()->all())
                ->where('enabled', false)->update(['enabled' => true]);
        }

        $failed = count(array_filter($lines, fn (array $line) => ! $line['ok']));
        $count = count($lines);
        $sites = $count === 1 ? '1 website' : $count.' websites';

        return [
            'message' => match (true) {
                $count === 0 => 'No websites to switch.',
                $failed === 0 => ($enabled ? 'Turned on ' : 'Turned off ').$sites.'.',
                default => $failed.' of '.$sites.' could not be switched.',
            },
            'failed' => $failed > 0,
            'lines' => $lines,
        ];
    }

    /**
     * Remove a website: its nginx, worker, and PM2 files, then the website itself. The folder
     * in /var/www stays, as when one website is deleted from its own page.
     */
    public function delete(Website $website): void
    {
        $tunnel = $website->tunnel;
        $domains = $website->hostnames();
        $this->removeLocally($website);

        if ($tunnel) {
            $this->cloudflare->forget($tunnel, $domains);
            $this->renderer->refreshTunnel($tunnel->fresh());
        }
    }

    /**
     * Delete websites in the queue. Stopping nginx, PM2, and the tunnel can take longer than
     * Cloudflare will wait on the page request, and the panel itself is served through that
     * tunnel, so doing it in the request shows a 502 even when the delete goes on to finish.
     *
     * @return array{message: string, failed: bool, lines: list<array{id: int, label: string, ok: bool, note: string}>}
     */
    public function deleteTask(PanelTask $task): array
    {
        $ids = array_map('intval', (array) ($task->payload['website_ids'] ?? []));
        $websites = Website::query()->whereKey($ids)->orderBy('name')->get();
        $lines = [];
        /** @var array<int, array{tunnel: \App\Models\CloudflareTunnel, domains: list<string>}> $tunnels */
        $tunnels = [];

        foreach ($websites->values() as $index => $website) {
            $task->report((int) floor($index / max(1, $websites->count()) * 100), 'Deleting '.$website->name.' ('.($index + 1).' of '.$websites->count().')', true);

            try {
                $tunnel = $website->tunnel;
                $domains = $website->hostnames();
                $this->removeLocally($website);

                if ($tunnel) {
                    $tunnels[$tunnel->id]['tunnel'] = $tunnel;
                    foreach ($domains as $domain) {
                        $tunnels[$tunnel->id]['domains'][] = $domain;
                    }
                }

                $lines[] = ['id' => $website->id, 'label' => $website->name, 'ok' => true, 'note' => 'Deleted'];
            } catch (Throwable $exception) {
                $lines[] = ['id' => $website->id, 'label' => $website->name, 'ok' => false, 'note' => Str::limit($exception->getMessage(), 300)];
            }

            $task->forceFill(['result' => ['lines' => $lines]])->save();
        }

        foreach ($tunnels as $item) {
            try {
                $domains = array_values(array_unique($item['domains']));
                $this->cloudflare->forget($item['tunnel'], $domains);
                $this->renderer->refreshTunnel($item['tunnel']->fresh());
            } catch (Throwable $exception) {
                $lines[] = ['id' => 0, 'label' => $item['tunnel']->name, 'ok' => false, 'note' => 'Deleted, but the tunnel was not updated: '.Str::limit($exception->getMessage(), 240)];
            }
        }

        $failed = count(array_filter($lines, fn (array $line) => ! $line['ok'] && $line['id'] !== 0));
        $tunnelNote = collect($lines)->first(fn (array $line) => $line['id'] === 0 && ! $line['ok']);
        $count = $websites->count();
        $sites = $count === 1 ? '1 website' : $count.' websites';

        return [
            'message' => match (true) {
                $count === 0 => 'No websites to delete.',
                $failed === 0 && $tunnelNote === null => 'Deleted '.$sites.'. Their folders in /var/www are kept.',
                $failed === 0 => 'Deleted '.$sites.'. '.$tunnelNote['note'],
                default => $failed.' of '.$sites.' could not be deleted.',
            },
            'failed' => $failed > 0,
            'lines' => $lines,
        ];
    }

    /**
     * Take the site off nginx, Supervisor, and PM2, then delete its panel record. The folder stays.
     */
    private function removeLocally(Website $website): void
    {
        $this->host->removeWebsite($website);
        $website->delete();
    }

    /**
     * An nginx server for the same addresses that answers 503 with a short page.
     */
    public function disabledNginx(Website $website): string
    {
        preg_match_all('/^\s*(listen\s+[^;]+;)/m', (string) $website->nginx_config, $listens);
        preg_match('/^\s*(server_name\s+[^;]+;)/m', (string) $website->nginx_config, $names);

        $listen = $listens[1] !== [] ? implode("\n    ", array_unique($listens[1])) : 'listen 80;';
        $serverName = $names[1] ?? 'server_name '.($website->domain ?: '_').';';
        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Turned off</title>'
            .'<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0a0a0a;color:#f4f4f4;font:15px/1.5 ui-sans-serif,system-ui,sans-serif}main{padding:24px;text-align:center}h1{margin:0 0 6px;font-size:20px}p{margin:0;color:#a1a1a1}</style>'
            .'</head><body><main><h1>This site is turned off</h1><p>It will be back when its owner turns it on again.</p></main></body></html>';

        return '# Written by Laravelify: '.$website->slug." is turned off.\n"
            ."server {\n"
            .'    '.$listen."\n"
            .'    '.$serverName."\n"
            ."    default_type text/html;\n"
            ."    add_header Cache-Control \"no-store\" always;\n"
            ."    location / {\n"
            ."        return 503 '".str_replace("'", "\\'", $page)."';\n"
            ."    }\n"
            ."}\n";
    }
}
