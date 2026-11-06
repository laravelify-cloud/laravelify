<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Free domains from Laravelify Cloud: <client>-<name>.laravelify.cloud, for panels Cloud
 * installed. Cloud checks the name, routes it through this server's tunnel, and adds its DNS;
 * this panel never holds a Cloudflare key, only its server's token.
 */
class CloudDomains
{
    public function configured(): bool
    {
        return filled(Setting::getValue('cloud_api')) && filled(Setting::getValue('cloud_token')) && filled($this->prefix());
    }

    /** "john": free domains look like john-<name>.<domain>. */
    public function prefix(): ?string
    {
        return Setting::getValue('cloud_prefix') ?: null;
    }

    public function domain(): ?string
    {
        return Setting::getValue('cloud_domain') ?: null;
    }

    /** john-shop.laravelify.cloud from "shop". */
    public function hostFor(string $name): string
    {
        return $this->prefix().'-'.strtolower(trim($name)).'.'.$this->domain();
    }

    /** A host under the Cloud's domain: Cloud makes its route, not the panel. */
    public function isFree(string $host): bool
    {
        return $this->configured() && str_ends_with(strtolower($host), '.'.$this->domain());
    }

    /**
     * Ask Cloud for the host. Returns a status line: "added" or "error: <why>".
     */
    public function claim(string $host): string
    {
        return $this->call('post', $host);
    }

    public function release(string $host): string
    {
        return $this->call('delete', $host);
    }

    private function call(string $method, string $host): string
    {
        try {
            $response = Http::withToken((string) Setting::getValue('cloud_token'))
                ->acceptJson()
                ->timeout(30)
                ->{$method}(Setting::getValue('cloud_api').'/domains', ['host' => strtolower($host)]);
        } catch (Throwable $exception) {
            return 'error: Laravelify Cloud did not answer ('.str($exception->getMessage())->limit(80).')';
        }

        if ($response->successful()) {
            return $method === 'post' ? 'added through Laravelify Cloud' : 'removed';
        }

        if ($method === 'delete' && $response->status() === 404) {
            return 'removed';
        }

        return 'error: '.($response->json('message') ?: 'Laravelify Cloud answered HTTP '.$response->status());
    }
}
