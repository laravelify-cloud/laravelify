<?php

namespace App\Services;

use App\Models\Website;

/**
 * Reads the nginx files of this server (sites-enabled and conf.d) for the Nginx page: every server
 * block with its domains, ports, and where it sends traffic, who owns the file, and the domains that
 * two server blocks both claim on the same port (nginx then warns, and the first one wins).
 *
 * @phpstan-type Server array{file: string, owner: string, website: ?string, names: list<string>, ports: list<int>, targets: list<string>}
 */
class NginxOverview
{
    private const MAX_FILE_BYTES = 200000;

    public function __construct(private string $root = '/etc/nginx') {}

    /**
     * The site and extra files nginx loads, in file order.
     *
     * @return list<array{path: string, name: string, content: string, servers: list<array{names: list<string>, ports: list<int>, targets: list<string>}>}>
     */
    public function files(): array
    {
        $paths = [...(glob($this->root.'/sites-enabled/*') ?: []), ...(glob($this->root.'/conf.d/*.conf') ?: [])];
        $files = [];

        foreach ($paths as $path) {
            $name = basename($path);

            if (! is_file($path) || preg_match('/^(laravelify-security-|laravelify-metrics)/', $name) === 1) {
                continue;
            }

            $content = (string) @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES);
            $files[] = ['path' => $path, 'name' => $name, 'content' => $content, 'servers' => self::parse($content)];
        }

        return $files;
    }

    /**
     * Every server block, with its file's owner (a website of this panel, a panel service, or something else).
     *
     * @return list<Server>
     */
    public function servers(?array $files = null): array
    {
        $sites = Website::query()->get(['id', 'name', 'slug'])->keyBy('slug');
        $rows = [];

        foreach ($files ?? $this->files() as $file) {
            $slug = preg_match('/^laravelify-(.+)\.conf$/', $file['name'], $match) === 1 ? $match[1] : null;
            $website = $slug !== null ? $sites->get($slug) : null;
            $owner = match (true) {
                $website !== null => $website->name,
                $slug === 'phpmyadmin' => 'phpMyAdmin',
                $slug === 'panel' || str_contains($file['name'], 'laravelify') => 'Laravelify panel',
                default => 'Not managed by Laravelify',
            };

            foreach ($file['servers'] as $server) {
                $rows[] = ['file' => $file['name'], 'owner' => $owner, 'website' => $website?->slug, ...$server];
            }
        }

        return $rows;
    }

    /**
     * Domains that more than one server block answers for on the same port.
     *
     * @param  list<Server>  $servers
     * @return list<array{name: string, port: int, files: list<string>}>
     */
    public static function conflicts(array $servers): array
    {
        $claims = [];

        foreach ($servers as $index => $server) {
            foreach ($server['ports'] as $port) {
                foreach ($server['names'] as $name) {
                    if ($name !== '_' && $name !== '') {
                        $claims[$port.'|'.$name][] = $server['file'].'#'.$index;
                    }
                }
            }
        }

        $conflicts = [];

        foreach ($claims as $key => $owners) {
            if (count($owners) > 1) {
                [$port, $name] = explode('|', $key, 2);
                $conflicts[] = ['name' => $name, 'port' => (int) $port, 'files' => array_values(array_unique(array_map(fn (string $owner): string => explode('#', $owner)[0], $owners)))];
            }
        }

        return $conflicts;
    }

    /**
     * The ports nginx listens on, and how many server blocks use each.
     *
     * @param  list<Server>  $servers
     * @return array<int, int>
     */
    public static function ports(array $servers): array
    {
        $ports = [];

        foreach ($servers as $server) {
            foreach ($server['ports'] as $port) {
                $ports[$port] = ($ports[$port] ?? 0) + 1;
            }
        }

        ksort($ports);

        return $ports;
    }

    /**
     * The server blocks of one nginx file: names, ports, and where each sends traffic.
     *
     * @return list<array{names: list<string>, ports: list<int>, targets: list<string>}>
     */
    public static function parse(string $conf): array
    {
        $conf = (string) preg_replace('/#[^\n]*/', '', $conf);
        $servers = [];
        $length = strlen($conf);
        $depth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $conf[$i];

            if ($char === '{') {
                if ($depth === 0 && preg_match('/(?:^|[;}\s])server\s*$/', substr($conf, max(0, $i - 40), min($i, 40))) === 1) {
                    $end = self::closing($conf, $i);
                    $servers[] = self::describe(substr($conf, $i + 1, $end - $i - 1));
                    $i = $end;

                    continue;
                }

                $depth++;
            } elseif ($char === '}') {
                $depth = max(0, $depth - 1);
            }
        }

        return $servers;
    }

    private static function closing(string $conf, int $open): int
    {
        $depth = 0;
        $length = strlen($conf);

        for ($i = $open; $i < $length; $i++) {
            if ($conf[$i] === '{') {
                $depth++;
            } elseif ($conf[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * @return array{names: list<string>, ports: list<int>, targets: list<string>}
     */
    private static function describe(string $body): array
    {
        $names = [];
        $ports = [];
        $targets = [];

        if (preg_match_all('/\bserver_name\s+([^;]+);/', $body, $found) > 0) {
            foreach ($found[1] as $list) {
                array_push($names, ...(preg_split('/\s+/', trim($list)) ?: []));
            }
        }

        if (preg_match_all('/\blisten\s+([^;]+);/', $body, $found) > 0) {
            foreach ($found[1] as $listen) {
                $address = preg_split('/\s+/', trim($listen))[0] ?? '';

                if (str_starts_with($address, 'unix:')) {
                    continue;
                }

                if (preg_match('/^(?:.*:)?(\d{1,5})$/', $address, $port) === 1) {
                    $ports[] = (int) $port[1];
                } elseif (preg_match('/^\d{1,5}$/', $address) === 1) {
                    $ports[] = (int) $address;
                }
            }
        }

        foreach ([['proxy_pass', 'proxy '], ['fastcgi_pass', 'PHP '], ['root', 'files ']] as [$directive, $label]) {
            if (preg_match_all('/\b'.$directive.'\s+([^;]+);/', $body, $found) > 0) {
                foreach ($found[1] as $value) {
                    $targets[] = $label.trim($value);
                }
            }
        }

        if (preg_match_all('/\breturn\s+(30\d)\s+([^;]+);/', $body, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $redirect) {
                $targets[] = 'redirect '.$redirect[1].' → '.trim($redirect[2]);
            }
        }

        return [
            'names' => array_values(array_unique($names)),
            'ports' => array_values(array_unique($ports)),
            'targets' => array_values(array_unique($targets)),
        ];
    }
}
