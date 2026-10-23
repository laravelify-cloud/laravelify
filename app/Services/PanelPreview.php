<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\Website;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A preview of a website before its own address is ready, in one of two ways:
 *
 * - subdomain (when a Cloudflare API token is saved and a tunnel exists): a random host name on
 *   one of the account's zones, routed by the tunnel straight to the site with the Host header
 *   its nginx expects. The app runs at "/" on its own host, exactly as on its real domain.
 * - path: the panel's own address at /p/<random token>/, proxied by nginx (see host-apply.sh
 *   panel-preview). Works without Cloudflare; apps that build paths at run time are sent back
 *   into the preview by their Referer.
 */
class PanelPreview
{
    public function __construct(
        private HostWriter $host,
        private CloudflareRoutes $routes,
        private CloudflareApi $cloudflare,
        private DomainSuggestions $domains,
    ) {}

    public function url(Website $website): ?string
    {
        if ($website->preview_host) {
            return 'https://'.$website->preview_host.'/';
        }

        return $website->preview_token ? rtrim((string) config('app.url'), '/').'/p/'.$website->preview_token.'/' : null;
    }

    /**
     * 'subdomain', 'path', or null when the preview is off.
     */
    public function mode(Website $website): ?string
    {
        return $website->preview_host ? 'subdomain' : ($website->preview_token ? 'path' : null);
    }

    /**
     * Whether previews can get their own subdomain here: a Cloudflare token and a tunnel.
     */
    public function canUseSubdomain(Website $website): bool
    {
        return $this->cloudflare->configured() && $this->tunnelFor($website) !== null;
    }

    /**
     * Turn the preview on, or give it a new address (the old one stops working). A subdomain is
     * used when possible; $mode 'path' asks for the panel's /p/ address instead.
     */
    public function enable(Website $website, bool $newAddress = false, ?string $mode = null): string
    {
        $mode ??= $website->preview_token && ! $website->preview_host ? 'path' : ($this->canUseSubdomain($website) ? 'subdomain' : 'path');

        return $mode === 'subdomain' ? $this->enableSubdomain($website, $newAddress) : $this->enablePath($website, $newAddress);
    }

    public function disable(Website $website): void
    {
        $this->removeSubdomain($website);

        if ($website->preview_token) {
            $this->host->removePanelPreview($website);
        }

        $website->update(['preview_token' => null, 'preview_host' => null, 'preview_tunnel_id' => null]);
    }

    /**
     * Where the preview sends requests: the site's HTTP port, or port 80 with the site's domain.
     *
     * @return array{int, string}
     */
    public function upstream(Website $website): array
    {
        if ($website->http_port) {
            return [(int) $website->http_port, 'localhost'];
        }

        return [80, strtolower((string) $website->domain)];
    }

    private function enablePath(Website $website, bool $newAddress): string
    {
        $this->removeSubdomain($website);

        if ($newAddress || ! $website->preview_token) {
            $website->update(['preview_token' => Str::lower(Str::random(32))]);
        }

        [$port, $host] = $this->upstream($website);
        $this->host->writePanelPreview($website, (string) $website->preview_token, $port, $host);
        $website->update(['preview_host' => null, 'preview_tunnel_id' => null]);

        return (string) $this->url($website);
    }

    private function enableSubdomain(Website $website, bool $newAddress): string
    {
        $tunnel = $this->tunnelFor($website) ?? throw new RuntimeException('There is no Cloudflare tunnel to route a preview subdomain through.');
        $zone = $this->zoneFor($website) ?? throw new RuntimeException('The Cloudflare token does not see any zone (domain) to make a preview subdomain on.');

        $host = $newAddress || ! $website->preview_host ? $this->domains->random($zone) : (string) $website->preview_host;

        if ($website->preview_host && $website->preview_host !== $host) {
            $this->removeSubdomain($website);
        }

        [$port, $hostHeader] = $this->upstream($website);
        $report = $this->routes->route($tunnel, $host, 'http://127.0.0.1:'.$port, $website->http_port ? null : $hostHeader);
        $problems = CloudflareRoutes::problems($report);

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        // A subdomain preview replaces a /p/ one.
        if ($website->preview_token) {
            $this->host->removePanelPreview($website);
        }

        $website->update(['preview_host' => $host, 'preview_tunnel_id' => $tunnel->id, 'preview_token' => null]);

        return (string) $this->url($website);
    }

    private function removeSubdomain(Website $website): void
    {
        if (! $website->preview_host) {
            return;
        }

        $tunnel = CloudflareTunnel::query()->find($website->preview_tunnel_id) ?? $this->tunnelFor($website);

        if ($tunnel) {
            $this->routes->route($tunnel, (string) $website->preview_host, '', null, remove: true);
        }

        $website->update(['preview_host' => null, 'preview_tunnel_id' => null]);
    }

    /**
     * The site's own tunnel, or the one the panel itself is reached through.
     */
    public function tunnelFor(Website $website): ?CloudflareTunnel
    {
        return $website->tunnel ?? CloudflareTunnel::query()->oldest('id')->first();
    }

    /**
     * The zone for the preview name: the site's own domain's, then the panel's, then the first.
     */
    public function zoneFor(Website $website): ?string
    {
        $zones = array_column($this->domains->options()['zones'], 'name');
        $panelHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ([strtolower((string) $website->domain), strtolower($panelHost)] as $host) {
            foreach ($zones as $zone) {
                if ($host !== '' && ($host === $zone || str_ends_with($host, '.'.$zone))) {
                    return $zone;
                }
            }
        }

        return $zones[0] ?? null;
    }
}
