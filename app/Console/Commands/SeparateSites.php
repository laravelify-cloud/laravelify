<?php

namespace App\Console\Commands;

use App\Models\TrashItem;
use App\Models\Website;
use App\Services\HostWriter;
use App\Services\SitePaths;
use App\Services\SiteUser;
use App\Services\WebsiteSwitch;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Move websites to their own Unix user (lfy-<slug>), so a hacked site cannot change other sites'
 * files, or give them back to the shared www-data with --undo. Run by scripts/privileges.sh
 * (LARAVELIFY_SITE_USERS=1) and scripts/rollback-site-users.sh, or by hand for one site.
 *
 * Per site: its folder goes to its user, the panel writes the site again (its own PHP-FPM pool,
 * workers, and PM2 daemon), and the site is checked. A site that answered before and does not
 * answer after is given back to www-data straight away.
 */
class SeparateSites extends Command
{
    protected $signature = 'laravelify:separate-sites
        {--site=* : Only these websites (slugs)}
        {--all : Every website}
        {--undo : Give the websites back to the shared user (www-data)}';

    protected $description = 'Move websites to their own Unix user, or back to www-data with --undo';

    public function handle(HostWriter $hostWriter, SiteUser $siteUser, WebsiteSwitch $switch): int
    {
        $slugs = array_values(array_filter((array) $this->option('site')));

        if ($slugs === [] && ! $this->option('all')) {
            $this->error('Name the websites with --site=<slug>, or use --all.');

            return self::FAILURE;
        }

        if (! $siteUser->separate()) {
            $this->error('The panel does not run as its own user yet. Run scripts/privileges.sh first.');

            return self::FAILURE;
        }

        $websites = Website::query()
            ->when($slugs !== [], fn ($query) => $query->whereIn('slug', $slugs))
            ->orderBy('slug')
            ->get();

        foreach (array_diff($slugs, $websites->pluck('slug')->all()) as $missing) {
            $this->warn($missing.': no such website.');
        }

        $failed = 0;

        foreach ($websites as $website) {
            if (! is_dir((string) $website->deploy_path)) {
                $this->line($website->slug.': skipped, its folder does not exist (it gets its own user on its first deploy).');

                continue;
            }

            try {
                $this->option('undo')
                    ? $this->giveBack($website, $hostWriter, $siteUser, $switch)
                    : $this->separate($website, $hostWriter, $siteUser, $switch);
            } catch (Throwable $exception) {
                $failed++;
                $this->warn($website->slug.': '.$exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function separate(Website $website, HostWriter $hostWriter, SiteUser $siteUser, WebsiteSwitch $switch): void
    {
        if ($siteUser->for($website)->isOwn()) {
            $this->line($website->slug.': already runs as '.$siteUser->for($website)->name().'.');

            return;
        }

        $answeredBefore = $this->answers($website);
        $trash = $this->sharedTrashOf($website);

        $this->line(trim($hostWriter->separateSite($website, $trash->modelKeys())));
        TrashItem::query()->whereKey($trash->modelKeys())->update(['site_slug' => $website->slug]);

        try {
            $this->apply($website, $hostWriter, $switch);
        } catch (Throwable $exception) {
            $this->undoAfterFailure($website, $hostWriter, $switch);

            throw $exception;
        }

        if ($answeredBefore && $website->enabled && ! $this->answersSoon($website)) {
            $this->undoAfterFailure($website, $hostWriter, $switch);

            throw new RuntimeException('it stopped answering with its own user, so it runs as www-data again. Check its logs, then try again.');
        }

        $this->info($website->slug.': runs as '.$siteUser->for($website)->name().'.');
    }

    private function giveBack(Website $website, HostWriter $hostWriter, SiteUser $siteUser, WebsiteSwitch $switch): void
    {
        if (! $siteUser->for($website)->isOwn()) {
            $this->line($website->slug.': already runs as '.$siteUser->shared()->name().'.');

            return;
        }

        $this->line(trim($hostWriter->unseparateSite($website)));
        TrashItem::query()->where('site_slug', $website->slug)->update(['site_slug' => null]);
        $this->apply($website, $hostWriter, $switch);
        $this->info($website->slug.': runs as '.$siteUser->shared()->name().' again.');
    }

    /**
     * Write the site again for its current user. A turned-off site stays off.
     */
    private function apply(Website $website, HostWriter $hostWriter, WebsiteSwitch $switch): void
    {
        $website->enabled
            ? $hostWriter->writeWebsite($website)
            : $hostWriter->disableWebsite($website, $switch->disabledNginx($website));
    }

    private function undoAfterFailure(Website $website, HostWriter $hostWriter, WebsiteSwitch $switch): void
    {
        try {
            $hostWriter->unseparateSite($website);
            TrashItem::query()->where('site_slug', $website->slug)->update(['site_slug' => null]);
            $this->apply($website, $hostWriter, $switch);
        } catch (Throwable $exception) {
            $this->warn($website->slug.': could not give it back to www-data either: '.$exception->getMessage());
        }
    }

    /**
     * Items of the shared trash that were deleted from this website's folder.
     *
     * @return Collection<int, TrashItem>
     */
    private function sharedTrashOf(Website $website): Collection
    {
        $globalRoot = rescue(fn () => app(SitePaths::class)->globalRoot(), null, false);
        $sitesRoot = realpath((string) config('laravelify.sites_root')) ?: null;
        $slug = (string) $website->slug;

        return TrashItem::query()
            ->whereNull('site_slug')
            ->where(function ($query) use ($website, $globalRoot, $sitesRoot, $slug): void {
                $query->where('scope', 'files:website:'.$website->id);

                // The global file manager starts in the sites folder: paths begin with the slug.
                if ($globalRoot !== null && $globalRoot === $sitesRoot) {
                    $query->orWhere(fn ($global) => $global->where('scope', 'files:global')
                        ->where(fn ($path) => $path->where('original_path', $slug)->orWhere('original_path', 'like', $slug.'/%')));
                }
            })
            ->get();
    }

    /**
     * Whether the site answers below HTTP 500 (false when it has no address to check).
     */
    /**
     * After the move: PHP-FPM opens the site's new pool a couple of seconds later (its reload
     * waits until the request that asked for it has finished), so give it a moment.
     */
    private function answersSoon(Website $website, int $seconds = 10): bool
    {
        for ($try = 0; $try < $seconds; $try++) {
            if ($this->answers($website)) {
                return true;
            }

            sleep(1);
        }

        return false;
    }

    private function answers(Website $website): bool
    {
        $url = $website->checkUrl();

        if (! $url) {
            return false;
        }

        try {
            return Http::timeout(15)->withoutRedirecting()->withHeaders(['User-Agent' => 'Laravelify health check'])->get($url)->status() < 500;
        } catch (Throwable) {
            return false;
        }
    }
}
