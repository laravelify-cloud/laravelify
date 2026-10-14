<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Website;
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
    public function __construct(private HostWriter $host, private ConfigRenderer $renderer) {}

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
     * Turn a whole group off or on, with every website in it.
     *
     * @return list<string> Errors, one per website that could not be switched.
     */
    public function setGroup(Group $group, bool $enabled): array
    {
        $errors = [];

        foreach ($group->websites()->get() as $website) {
            if ($website->enabled === $enabled) {
                continue;
            }

            try {
                $enabled ? $this->enable($website) : $this->disable($website);
            } catch (Throwable $exception) {
                $errors[] = $website->name.': '.$exception->getMessage();
            }
        }

        $group->forceFill(['enabled' => $enabled])->save();

        return $errors;
    }

    /**
     * Remove a website: its nginx, worker, and PM2 files, then the website itself. The folder
     * in /var/www stays, as when one website is deleted from its own page.
     */
    public function delete(Website $website): void
    {
        $tunnel = $website->tunnel;
        $this->host->removeWebsite($website);
        $website->delete();

        if ($tunnel) {
            $this->renderer->refreshTunnel($tunnel);
        }
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
