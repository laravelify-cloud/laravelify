<?php

namespace App\Services;

use App\Models\Website;

/**
 * Per-site search-engine blocking, HTTP basic auth, blocked IPs, and the
 * Monitoring tab's request log, applied through nginx.
 *
 * The site's own nginx config stays editable; it only needs one include line.
 * host-apply.sh installs three files per site:
 *   /etc/nginx/conf.d/laravelify-security-{slug}.conf  maps (http level)
 *   /etc/nginx/laravelify-security/{slug}.conf          rules (server level)
 *   /etc/nginx/laravelify-security/{slug}.htpasswd      user:hash
 */
class SiteSecurity
{
    public const SNIPPET_DIRECTORY = '/etc/nginx/laravelify-security';

    /**
     * Crawlers that index or scrape. Link-preview bots (Telegram, WhatsApp,
     * Facebook, Slack, Discord) are left out so shared links still preview.
     */
    public const CRAWLERS = [
        'googlebot', 'google-extended', 'adsbot-google', 'mediapartners-google', 'bingbot', 'bingpreview',
        'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'yandeximages', 'sogou', 'exabot', 'applebot',
        'petalbot', 'seznambot', 'naver', 'ia_archiver', 'archive.org_bot', 'semrushbot', 'ahrefsbot',
        'mj12bot', 'dotbot', 'blexbot', 'dataforseobot', 'serpstatbot', 'gptbot', 'chatgpt-user', 'oai-searchbot',
        'ccbot', 'claudebot', 'claude-web', 'anthropic-ai', 'perplexitybot', 'bytespider', 'amazonbot',
        'cohere-ai', 'diffbot', 'meta-externalagent', 'imagesiftbot', 'omgili', 'youbot',
    ];

    public function active(Website $website): bool
    {
        return $website->hide_from_search || $website->block_crawlers || $website->basic_auth_enabled || $this->blockedIps($website) !== [];
    }

    /**
     * The JSON request log nginx writes for the Monitoring tab.
     */
    public static function metricsLog(Website $website): string
    {
        return rtrim((string) config('laravelify.metrics_log_dir'), '/').'/'.$website->slug.'.metrics.log';
    }

    public function includeLine(Website $website): string
    {
        return '    include '.self::SNIPPET_DIRECTORY.'/'.$website->slug.'.conf; # Laravelify security (Security tab)';
    }

    public function hasInclude(Website $website): bool
    {
        return str_contains((string) $website->nginx_config, self::SNIPPET_DIRECTORY.'/'.$website->slug.'.conf');
    }

    /**
     * Add the include after every server_name line, so every server block of the
     * site (for example an HTTP redirect block and the HTTPS block) is covered.
     */
    public function ensureInclude(Website $website): bool
    {
        if ($this->hasInclude($website)) {
            return false;
        }

        $line = $this->includeLine($website);
        $config = (string) $website->nginx_config;

        // The panel's own redirect servers carry no security rules (a login prompt on a redirect helps no one).
        $redirects = '';

        if (preg_match('/\r?\n*# laravelify-redirects:start.*?# laravelify-redirects:end[ \t]*\r?\n?/s', $config, $found) === 1) {
            $redirects = $found[0];
            $config = str_replace($redirects, '', $config);
        }

        // After the server_name directive (also with a trailing comment or Windows line
        // endings), or, when a block has none, right after its opening "server {".
        foreach (['/^([ \t]*server_name\b[^;]*;[^\r\n]*?)(\r?)$/m', '/^([ \t]*server\s*\{[^\r\n]*?)(\r?)$/m'] as $pattern) {
            $updated = preg_replace_callback(
                $pattern,
                fn (array $match): string => $match[1].$match[2]."\n".$line.$match[2],
                $config,
                -1,
                $count,
            );

            if (is_string($updated) && $count > 0) {
                $website->nginx_config = $updated.$redirects;

                return true;
            }
        }

        return false;
    }

    /**
     * http-level maps. Empty when nothing is enabled.
     */
    public function httpConfig(Website $website): string
    {
        $suffix = $this->suffix($website);
        $lines = ['# Laravelify security for '.$website->slug.'. Managed by the panel; edit in the Security tab.'];

        if ($website->basic_auth_enabled) {
            $lines[] = 'map $uri $laravelify_auth_'.$suffix.' {';
            $lines[] = '    default "Protected site";';
            $lines[] = '    ~^/\.well-known/acme-challenge/ off;';

            foreach ($this->exceptions($website) as $path) {
                $lines[] = '    ~^'.str_replace('.', '\.', $path).' off;';
            }

            $lines[] = '}';
        }

        $blocked = $this->blockedIps($website);

        if ($blocked !== []) {
            // $laravelify_client_ip is the visitor's address, also behind a Cloudflare tunnel
            // (defined in conf.d/laravelify-metrics.conf).
            $lines[] = 'geo $laravelify_client_ip $laravelify_blocked_'.$suffix.' {';
            $lines[] = '    default 0;';

            foreach ($blocked as $ip) {
                $lines[] = '    '.$ip.' 1;';
            }

            $lines[] = '}';
        }

        if ($website->block_crawlers) {
            $lines[] = 'map $http_user_agent $laravelify_crawler_'.$suffix.' {';
            $lines[] = '    default 0;';
            $lines[] = '    "~*('.implode('|', array_map(fn (string $bot): string => str_replace('.', '\.', $bot), self::CRAWLERS)).')" 1;';
            $lines[] = '}';
        }

        return count($lines) > 1 ? implode("\n", $lines)."\n" : '';
    }

    /**
     * server-level rules. Empty (a comment) when nothing is enabled.
     */
    public function serverConfig(Website $website): string
    {
        $suffix = $this->suffix($website);
        $lines = ['# Laravelify security for '.$website->slug.'. Managed by the panel; edit in the Security tab.'];

        // Monitoring tab: one JSON line per request. Next to the site's own access_log.
        $lines[] = 'access_log '.self::metricsLog($website).' laravelify_metrics;';

        if ($this->blockedIps($website) !== []) {
            $lines[] = 'if ($laravelify_blocked_'.$suffix.') {';
            $lines[] = '    return 403;';
            $lines[] = '}';
        }

        if ($website->block_crawlers) {
            $lines[] = 'if ($laravelify_crawler_'.$suffix.') {';
            $lines[] = '    return 403;';
            $lines[] = '}';
        }

        if ($website->hide_from_search) {
            $lines[] = 'add_header X-Robots-Tag "noindex, nofollow, noarchive, nosnippet" always;';

            // The site config may already serve /robots.txt (a duplicate location breaks nginx).
            if (! preg_match('/location\s*=\s*\/robots\.txt/', (string) $website->nginx_config)) {
                $lines[] = 'location = /robots.txt {';
                $lines[] = '    default_type text/plain;';
                $lines[] = '    add_header X-Robots-Tag "noindex, nofollow" always;';
                $lines[] = '    return 200 "User-agent: *\nDisallow: /\n";';
                $lines[] = '}';
            }
        }

        if ($website->basic_auth_enabled && $this->htpasswd($website) !== '') {
            $lines[] = 'auth_basic $laravelify_auth_'.$suffix.';';
            $lines[] = 'auth_basic_user_file '.self::SNIPPET_DIRECTORY.'/'.$website->slug.'.htpasswd;';
        }

        return implode("\n", $lines)."\n";
    }

    public function htpasswd(Website $website): string
    {
        if (! $website->basic_auth_enabled || blank($website->basic_auth_user) || blank($website->basic_auth_hash)) {
            return '';
        }

        return $website->basic_auth_user.':'.$website->basic_auth_hash."\n";
    }

    /**
     * SHA-512 crypt, which nginx checks with the system crypt(). nginx verifies it
     * on every request, so it keeps the standard 5000 rounds rather than a slow cost.
     */
    public function hash(string $password): string
    {
        return crypt($password, '$6$'.substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16).'$');
    }

    public function verify(Website $website, string $password): bool
    {
        $hash = (string) $website->basic_auth_hash;

        return $hash !== '' && hash_equals($hash, crypt($password, $hash));
    }

    /**
     * Paths that skip basic auth, one per line, like /api/ or /hooks/github.
     *
     * @return list<string>
     */
    public function exceptions(Website $website): array
    {
        return self::parseExceptions((string) $website->basic_auth_except);
    }

    /**
     * @return list<string>
     */
    public static function parseExceptions(string $text): array
    {
        return collect(preg_split('/\R/', $text) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => preg_match('#^/[A-Za-z0-9/_.-]*$#', $line) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function blockedIps(Website $website): array
    {
        return self::parseIps((string) $website->blocked_ips);
    }

    /**
     * Valid IPs and CIDR ranges, one per line. Anything else is dropped.
     *
     * @return list<string>
     */
    public static function parseIps(string $text): array
    {
        return collect(preg_split('/[\s,]+/', $text) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => self::validIp($line))
            ->unique()
            ->values()
            ->all();
    }

    public static function validIp(string $value): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix >= 8 && (int) $prefix <= $max;
    }

    private function suffix(Website $website): string
    {
        return str_replace('-', '_', $website->slug);
    }
}
