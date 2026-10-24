<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\Website;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * VS Code's Live Server: the dev server a website's editor runs (npm run dev, php artisan serve,
 * php -S...) on a port of 127.0.0.1, shown on a random host name of the Cloudflare account while
 * it runs. The tunnel routes that name straight to the port (no nginx), with "localhost" as the
 * Host header, which dev servers accept. Stopping it takes the route and its DNS record away.
 *
 * Only that website's editor can ask (with the key CodeServer writes into its home), only for a
 * port in the Live Server range, and only when the site's own user is the one listening on it:
 * no other site's app, editor, or service can be put on the internet this way.
 */
class LiveServer
{
    /** The ports a Live Server may use; the editor picks a free one. */
    public const PORTS = [14000, 14999];

    public function __construct(
        private CloudflareRoutes $routes,
        private CloudflareApi $cloudflare,
        private DomainSuggestions $domains,
        private PanelPreview $preview,
    ) {}

    public function url(Website $website): ?string
    {
        return $website->live_host ? 'https://'.$website->live_host.'/' : null;
    }

    /** Running: the name routes to a dev server. Stopped, the website keeps its name for next time. */
    public function running(Website $website): bool
    {
        return $website->live_host !== null && $website->live_port !== null;
    }

    /**
     * The key the website's editor proves itself with (made the first time).
     */
    public function key(Website $website): string
    {
        if (! $website->live_key) {
            $website->forceFill(['live_key' => Str::random(48)])->save();
        }

        return (string) $website->live_key;
    }

    public function validKey(Website $website, ?string $key): bool
    {
        return $website->live_key !== null && is_string($key) && hash_equals((string) $website->live_key, $key);
    }

    /**
     * Put the dev server on port $port on the internet; the same name again while it runs.
     */
    public function start(Website $website, int $port): string
    {
        if ($port < self::PORTS[0] || $port > self::PORTS[1]) {
            throw new RuntimeException('The Live Server port must be between '.self::PORTS[0].' and '.self::PORTS[1].'.');
        }

        $uid = $this->siteUid($website);

        if ($uid === null || $this->listenerUid($port) !== $uid) {
            throw new RuntimeException('Nothing of this website listens on port '.$port.' yet. Start the dev server first.');
        }

        if (! $this->cloudflare->configured()) {
            throw new RuntimeException('The Live Server needs a Cloudflare API token (Settings → Cloudflare) to give it an address.');
        }

        $tunnel = $this->preview->tunnelFor($website) ?? throw new RuntimeException('There is no Cloudflare tunnel to route the Live Server through.');
        $zone = $this->preview->zoneFor($website) ?? throw new RuntimeException('The Cloudflare token does not see any zone (domain) to give the Live Server a name on.');

        $host = $website->live_host ?: $this->domains->random($zone);
        $problems = CloudflareRoutes::problems($this->routes->route($tunnel, $host, 'http://127.0.0.1:'.$port, 'localhost'));

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $website->forceFill(['live_host' => $host, 'live_port' => $port, 'live_tunnel_id' => $tunnel->id])->save();

        return (string) $this->url($website);
    }

    /**
     * Stop routing the name to the dev server. The website keeps the name and its DNS record, so
     * the next Go Live gets the same address at once (a new name takes a while to resolve, and a
     * browser that asked too early remembers that it did not exist).
     */
    public function stop(Website $website): void
    {
        if (! $website->live_host || $website->live_port === null) {
            return;
        }

        if ($tunnel = $this->tunnelOf($website)) {
            $this->routes->route($tunnel, (string) $website->live_host, '', null, remove: true, keepDns: true);
        }

        $website->forceFill(['live_port' => null])->save();
    }

    /**
     * Give the name up: its tunnel route and its DNS record go. The next Go Live gets a new one.
     */
    public function release(Website $website): void
    {
        if (! $website->live_host) {
            return;
        }

        if ($tunnel = $this->tunnelOf($website)) {
            $this->routes->route($tunnel, (string) $website->live_host, '', null, remove: true);
        }

        $website->forceFill(['live_host' => null, 'live_port' => null, 'live_tunnel_id' => null])->save();
    }

    private function tunnelOf(Website $website): ?CloudflareTunnel
    {
        return CloudflareTunnel::query()->find($website->live_tunnel_id) ?? $this->preview->tunnelFor($website);
    }

    /**
     * The Unix user id of whoever listens on 127.0.0.1 (or every address) at $port, from the
     * kernel's socket tables (readable by every user), or null when nobody does.
     */
    protected function listenerUid(int $port): ?int
    {
        $local = ['0100007F', '00000000', '00000000000000000000000001000000', '00000000000000000000000000000000'];

        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $table) {
            foreach (@file($table, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $index => $row) {
                $fields = preg_split('/\s+/', trim($row));

                if ($index === 0 || count($fields) < 8 || $fields[3] !== '0A') {
                    continue;
                }

                [$address, $hexPort] = explode(':', $fields[1]);

                if (hexdec($hexPort) === $port && in_array($address, $local, true)) {
                    return (int) $fields[7];
                }
            }
        }

        return null;
    }

    protected function siteUid(Website $website): ?int
    {
        $account = function_exists('posix_getpwnam') ? posix_getpwnam(SiteUser::userName((string) $website->slug)) : false;

        return is_array($account) ? (int) $account['uid'] : null;
    }
}
