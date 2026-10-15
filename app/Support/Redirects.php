<?php

namespace App\Support;

use App\Models\Website;
use App\Services\WebsiteDomains;

/**
 * Redirect rules of a website, and the nginx they become.
 *
 * A rule is one of:
 *  - a domain redirect: from "apply.coupons" to a URL (blank = this website's own address). nginx gets
 *    its own server block for it, and the domain is added to the Cloudflare tunnel and DNS like the
 *    website's other domains.
 *  - a path redirect: from "/old-page" (exact) or "/blog/" (everything under it) to a URL or a path
 *    on this website. nginx gets a location inside the website's server block.
 *
 * @phpstan-type Rule array{from: string, to: string, code: int, keep_path: bool, match: string}
 */
class Redirects
{
    public const CODES = [301 => '301 Permanent', 302 => '302 Temporary', 307 => '307 Temporary (keeps the method)', 308 => '308 Permanent (keeps the method)'];

    public const MAX = 50;

    public const SERVERS_START = '# laravelify-redirects:start (Redirects tab in the panel)';

    public const SERVERS_END = '# laravelify-redirects:end';

    public const PATHS_START = '    # laravelify-path-redirects:start (Redirects tab in the panel)';

    public const PATHS_END = '    # laravelify-path-redirects:end';

    private const PATH = '/^\/[A-Za-z0-9._~\/%+@!,=:-]*$/';

    private const TARGET = '/^[A-Za-z0-9._~:\/?@!&()*+,=%-]+$/';

    public static function isPath(string $from): bool
    {
        return str_starts_with($from, '/');
    }

    /**
     * Clean rows from a form or a tool: trimmed, hosts lower case without http://, empty rows dropped.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{from: string, to: string, code: int, keep_path: bool, match: string}>
     */
    public static function normalize(array $rows): array
    {
        $rules = [];

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            $from = trim((string) ($row['from'] ?? ''));
            $to = trim((string) ($row['to'] ?? ''));

            if ($from === '' && $to === '') {
                continue;
            }

            if (! self::isPath($from)) {
                $from = rtrim(explode('/', (string) preg_replace('#^[a-z]+://#i', '', strtolower($from)))[0], '.');
            }

            $code = (int) ($row['code'] ?? 301);

            $rules[] = [
                'from' => $from,
                'to' => $to,
                'code' => array_key_exists($code, self::CODES) ? $code : 301,
                'keep_path' => ! array_key_exists('keep_path', $row) || filter_var($row['keep_path'], FILTER_VALIDATE_BOOLEAN),
                'match' => ($row['match'] ?? 'exact') === 'prefix' ? 'prefix' : 'exact',
            ];
        }

        return $rules;
    }

    /**
     * What is wrong with each rule, by row number (1 is the first). Empty when all is well.
     *
     * @param  list<array{from: string, to: string, code: int, keep_path: bool, match: string}>  $rules
     * @param  list<string>  $ownHosts  The website's own domains: they are served, never redirected.
     * @param  list<string>  $otherHosts  Hostnames other websites answer for.
     * @return array<int, string>
     */
    public static function errors(array $rules, array $ownHosts, array $otherHosts, ?string $mainDomain): array
    {
        $errors = [];
        $seen = [];
        $sources = array_map(fn (array $rule): string => $rule['from'], array_filter($rules, fn (array $rule): bool => ! self::isPath($rule['from'])));

        if (count($rules) > self::MAX) {
            return [1 => 'At most '.self::MAX.' redirects.'];
        }

        foreach ($rules as $index => $rule) {
            $row = $index + 1;
            $key = $rule['from'].'|'.(self::isPath($rule['from']) ? $rule['match'] : '');

            if (isset($seen[$key])) {
                $errors[$row] = $rule['from'].' is redirected twice.';

                continue;
            }

            $seen[$key] = true;
            $message = self::isPath($rule['from'])
                ? self::pathError($rule)
                : self::hostError($rule, $ownHosts, $otherHosts, $mainDomain, $sources);

            if ($message !== null) {
                $errors[$row] = $message;
            }
        }

        return $errors;
    }

    /**
     * @param  array{from: string, to: string, code: int, keep_path: bool, match: string}  $rule
     */
    private static function pathError(array $rule): ?string
    {
        if (preg_match(self::PATH, $rule['from']) !== 1) {
            return $rule['from'].' is not a path nginx can match. Use letters, numbers, and - _ . / only, like /old-page.';
        }

        if ($rule['to'] === '') {
            return 'Where should '.$rule['from'].' go? Give a path on this site (/new-page) or a full URL.';
        }

        if (preg_match(self::TARGET, $rule['to']) !== 1 || (! str_starts_with($rule['to'], '/') && preg_match('#^https?://[^/]+#i', $rule['to']) !== 1)) {
            return 'The destination of '.$rule['from'].' must be a path like /new-page or a URL like https://example.com/page (no spaces or quotes).';
        }

        return null;
    }

    /**
     * @param  array{from: string, to: string, code: int, keep_path: bool, match: string}  $rule
     * @param  list<string>  $ownHosts
     * @param  list<string>  $otherHosts
     * @param  list<string>  $sources
     */
    private static function hostError(array $rule, array $ownHosts, array $otherHosts, ?string $mainDomain, array $sources): ?string
    {
        if (preg_match(WebsiteDomains::HOST, $rule['from']) !== 1) {
            return '"'.$rule['from'].'" is not a domain. Use one like apply.coupons, or a path starting with /.';
        }

        if (in_array($rule['from'], $ownHosts, true)) {
            return $rule['from'].' is one of this website\'s own domains, so it opens the site and cannot redirect. Remove it from the domains first.';
        }

        if (in_array($rule['from'], $otherHosts, true)) {
            return $rule['from'].' already belongs to another website.';
        }

        if ($rule['to'] === '') {
            return $mainDomain === null ? 'Give a destination URL: this website has no main domain to send visitors to.' : null;
        }

        $target = preg_replace('#^https?://#i', '', $rule['to']);

        if (preg_match(self::TARGET, $rule['to']) !== 1 || preg_match(WebsiteDomains::HOST, strtolower(explode('/', (string) $target)[0])) !== 1) {
            return 'The destination of '.$rule['from'].' must be a domain or URL like https://supercell.apply.coupons (no spaces or quotes).';
        }

        $targetHost = strtolower(explode('/', (string) $target)[0]);

        if (in_array($targetHost, $sources, true)) {
            return $rule['from'].' would redirect to '.$targetHost.', which redirects again. Point it at the final address.';
        }

        return null;
    }

    /**
     * The destination of a domain redirect: the website's own address when blank, with a scheme
     * (https behind a Cloudflare tunnel, http otherwise).
     */
    public static function target(Website $website, array $rule): string
    {
        $scheme = $website->cloudflare_tunnel_id ? 'https' : 'http';
        $to = $rule['to'] !== '' ? $rule['to'] : (string) $website->domain;
        $to = preg_match('#^https?://#i', $to) === 1 ? $to : $scheme.'://'.$to;

        return rtrim($to, '/');
    }

    /**
     * The nginx text of the website's redirects.
     *
     * @return array{paths: string, servers: string} Each empty when there is nothing to write.
     */
    public static function nginx(Website $website): array
    {
        $rules = self::normalize((array) ($website->redirects ?? []));
        $port = $website->http_port ?: 80;
        $paths = [];
        $groups = [];

        foreach ($rules as $rule) {
            if (self::isPath($rule['from'])) {
                $location = $rule['match'] === 'prefix' ? '^~ '.rtrim($rule['from'], '/').'/' : '= '.$rule['from'];
                $paths[] = '    location '.$location.' { return '.$rule['code'].' '.$rule['to'].'; }';

                continue;
            }

            $target = self::target($website, $rule);
            $groups[$target."\n".$rule['code']."\n".(int) $rule['keep_path']][] = $rule['from'];
        }

        $servers = [];

        foreach ($groups as $key => $hosts) {
            [$target, $code, $keep] = explode("\n", $key);
            $servers[] = "server {\n    listen {$port};\n    listen [::]:{$port};\n    server_name ".implode(' ', $hosts).";\n    return {$code} ".$target.($keep === '1' ? '$request_uri' : '').";\n}";
        }

        return [
            'paths' => $paths === [] ? '' : self::PATHS_START."\n".implode("\n", $paths)."\n".self::PATHS_END,
            'servers' => $servers === [] ? '' : self::SERVERS_START."\n".implode("\n\n", $servers)."\n".self::SERVERS_END,
        ];
    }
}
