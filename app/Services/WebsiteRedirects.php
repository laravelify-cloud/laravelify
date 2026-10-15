<?php

namespace App\Services;

use App\Models\Website;
use App\Support\Redirects;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves a website's redirects (Redirects tab, MCP set-redirects): nginx gets the rules, and the
 * domains that redirect are added to the tunnel and Cloudflare DNS like the website's own domains.
 */
class WebsiteRedirects
{
    public function __construct(
        private ConfigRenderer $renderer,
        private HostWriter $host,
        private CloudflareRoutes $cloudflare,
    ) {}

    /**
     * Rows as they would be saved, and what is wrong with them (by row number).
     *
     * @param  array<int, mixed>  $rows
     * @return array{rules: list<array{from: string, to: string, code: int, keep_path: bool, match: string}>, errors: array<int, string>}
     */
    public function check(Website $website, array $rows): array
    {
        $rules = Redirects::normalize($rows);
        $others = Website::query()->whereKeyNot($website->id)->get(['id', 'domain', 'aliases', 'redirects'])
            ->flatMap(fn (Website $other): array => $other->hostnames())->all();

        return ['rules' => $rules, 'errors' => Redirects::errors($rules, $website->domains(), $others, $website->domain)];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array{configured: bool, lines: list<array{host: string, route: string, dns: string, ok: bool}>, note: ?string}
     *
     * @throws ValidationException When a rule is not valid, or nginx did not accept the result (nothing changed).
     */
    public function apply(Website $website, array $rows): array
    {
        ['rules' => $rules, 'errors' => $errors] = $this->check($website, $rows);

        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $message, int $row): array => ['redirects.'.($row - 1) => $message])->all());
        }

        $old = $website->hostnames();
        $previous = ['redirects' => $website->redirects, 'nginx_config' => $website->nginx_config];

        $website->redirects = $rules === [] ? null : $rules;
        $website->nginx_config = $this->renderer->withRedirects($website, (string) $website->nginx_config);
        $website->save();

        try {
            $this->host->writeNginx($website);
        } catch (Throwable $exception) {
            $website->forceFill($previous)->save();

            try {
                $this->host->writeNginx($website);
            } catch (Throwable) {
                // The previous file was live before; nginx keeps serving it.
            }

            throw ValidationException::withMessages(['redirects' => 'nginx did not accept the redirects, so nothing changed: '.$exception->getMessage()]);
        }

        $this->renderer->refreshTunnel($website->tunnel);

        return $this->cloudflare->sync($website, array_values(array_diff($old, $website->hostnames())));
    }
}
