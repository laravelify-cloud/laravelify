<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A website's domains: the main one and any number of extra ones (www, a second domain) that
 * open the same site. Changing them keeps nginx's server_name, the Cloudflare tunnel's ingress,
 * and (with an API token) Cloudflare's tunnel routes and DNS in step.
 */
class WebsiteDomains
{
    public const MAX_ALIASES = 20;

    public const HOST = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/';

    public function __construct(
        private ConfigRenderer $renderer,
        private HostWriter $host,
        private CloudflareRoutes $cloudflare,
    ) {}

    /**
     * Domains from a list or from text (one per line, or separated by commas and spaces): lower case,
     * no http://, no path, no duplicates.
     *
     * @param  array<int, mixed>|string|null  $input
     * @return list<string>
     */
    public static function normalize(array|string|null $input): array
    {
        $items = is_array($input) ? $input : (preg_split('/[\s,;]+/', (string) $input) ?: []);
        $hosts = [];

        foreach ($items as $item) {
            $host = strtolower(trim((string) $item));
            $host = (string) preg_replace('#^[a-z]+://#', '', $host);
            $host = rtrim(explode('/', $host)[0], '.');

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    public static function wwwOf(string $domain): string
    {
        return 'www.'.preg_replace('/^www\./', '', $domain);
    }

    /**
     * Check a domain and its extra domains before saving. Every domain must be new on this
     * server: no other website may already answer for it.
     *
     * @param  list<string>  $aliases
     *
     * @throws ValidationException
     */
    public function validate(?Website $current, ?string $domain, array $aliases): void
    {
        $errors = [];

        if ($domain !== null && preg_match(self::HOST, $domain) !== 1) {
            $errors['domain'] = 'Use a domain like example.com (no http://, no spaces).';
        }

        if ($domain === null && $aliases !== []) {
            $errors['aliases'] = 'Extra domains need a main domain.';
        }

        if (count($aliases) > self::MAX_ALIASES) {
            $errors['aliases'] = 'At most '.self::MAX_ALIASES.' extra domains.';
        }

        foreach ($aliases as $alias) {
            if (preg_match(self::HOST, $alias) !== 1) {
                $errors['aliases'] = '"'.$alias.'" is not a domain. Use one like www.example.com, a line each.';

                break;
            }

            if ($current && in_array($alias, $current->redirectHosts(), true)) {
                $errors['aliases'] = $alias.' is a redirect of this website (Redirects tab). Remove the redirect to serve it instead.';

                break;
            }

            if ($alias === $domain) {
                $errors['aliases'] = $alias.' is already the main domain.';

                break;
            }
        }

        foreach (array_filter([$domain, ...$aliases]) as $host) {
            $owner = $this->owner($host, $current);

            if ($owner !== null) {
                $errors[$host === $domain ? 'domain' : 'aliases'] = $host.' already belongs to the website '.$owner->name.'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The other website that already answers for a domain.
     */
    private function owner(string $host, ?Website $current): ?Website
    {
        return Website::query()
            ->when($current?->exists, fn ($query) => $query->whereKeyNot($current->id))
            ->get(['id', 'name', 'domain', 'aliases', 'redirects'])
            ->first(fn (Website $website): bool => in_array($host, $website->hostnames(), true));
    }

    /**
     * Save new domains on an existing website and apply them everywhere.
     *
     * @param  list<string>  $aliases
     * @return array{configured: bool, lines: list<array{host: string, route: string, dns: string, ok: bool}>, note: ?string}
     *
     * @throws ValidationException When a domain is not valid or belongs to another website.
     */
    public function apply(Website $website, ?string $domain, array $aliases): array
    {
        $domain = $domain !== null && $domain !== '' ? $domain : null;
        $aliases = array_values(array_diff($aliases, [$domain]));
        $this->validate($website, $domain, $aliases);

        $old = $website->hostnames();
        $previous = ['domain' => $website->domain, 'aliases' => $website->aliases, 'nginx_config' => $website->nginx_config];

        $website->domain = $domain;
        $website->aliases = $aliases === [] ? null : $aliases;
        $website->nginx_config = $this->renderer->withServerNames($website, (string) $website->nginx_config);
        $website->save();

        if ($old !== $website->hostnames() || $website->wasChanged('nginx_config')) {
            try {
                $this->host->writeNginx($website);
            } catch (Throwable $exception) {
                $website->forceFill($previous)->save();

                try {
                    $this->host->writeNginx($website);
                } catch (Throwable) {
                    // The previous file was live before; nginx keeps serving it.
                }

                throw ValidationException::withMessages(['domain' => 'nginx did not accept the domains, so nothing changed: '.$exception->getMessage()]);
            }
        }

        $this->renderer->refreshTunnel($website->tunnel);

        return $this->cloudflare->sync($website, array_values(array_diff($old, $website->hostnames())));
    }
}
