<?php

namespace App\Services;

use App\Jobs\RunPanelTask;
use App\Models\CloudflareTunnel;
use App\Models\PanelTask;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Starts background tasks and runs them in the queue worker.
 */
class PanelTaskRunner
{
    public function __construct(
        private FileOperations $files,
        private SitePaths $paths,
        private BackupInspector $backups,
        private WebsiteSwitch $switch,
        private DatabaseImporter $importer,
    ) {}

    /**
     * Save a task and send it to the queue.
     *
     * @param  array<string, mixed>  $payload
     */
    public function start(string $kind, string $scope, string $label, array $payload): PanelTask
    {
        $task = PanelTask::query()->create([
            'kind' => $kind,
            'scope' => $scope,
            'label' => Str::limit($label, 180),
            'payload' => $payload,
        ]);

        RunPanelTask::dispatch($task->id);

        return $task->refresh();
    }

    public function run(PanelTask $task): void
    {
        $task->forceFill(['status' => PanelTask::RUNNING, 'started_at' => now(), 'progress' => 0])->save();
        $progress = fn (int $done, int $total) => $task->report((int) floor($done / max(1, $total) * 100));

        try {
            $result = $this->handle($task, $progress);
            $task->forceFill([
                'status' => empty($result['failed']) ? PanelTask::SUCCESS : PanelTask::FAILED,
                'progress' => 100,
                'result' => $result,
                'message' => $result['message'] ?? null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $task->forceFill([
                'status' => PanelTask::FAILED,
                'message' => Str::limit($exception->getMessage(), 500),
                'finished_at' => now(),
            ])->save();
        }
    }

    /**
     * The folder a file manager task works in, from its scope. Never taken from the payload.
     */
    public function rootFor(string $scope): string
    {
        if ($scope === 'files:global') {
            return $this->paths->globalRoot();
        }

        if (preg_match('/^files:website:(\d+)$/', $scope, $match)) {
            $website = Website::query()->find((int) $match[1]) ?? throw new RuntimeException('That website no longer exists.');

            return $this->paths->root($website);
        }

        throw new RuntimeException('Unknown task scope.');
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(PanelTask $task, callable $progress): array
    {
        $payload = $task->payload ?? [];

        if ($task->kind === 'database.import') {
            $website = Website::query()->find((int) ($payload['website_id'] ?? 0)) ?? throw new RuntimeException('That website no longer exists.');

            return ['message' => $this->importer->import(
                $website,
                (string) ($payload['path'] ?? ''),
                (bool) ($payload['reset'] ?? false),
                fn (int $percent, string $message) => $task->report($percent, $message, true),
            )];
        }

        if (str_starts_with($task->kind, 'files.')) {
            $root = $this->rootFor($task->scope);
            $relatives = array_values(array_map('strval', (array) ($payload['relatives'] ?? [])));

            return match ($task->kind) {
                'files.compress' => ['path' => $path = $this->files->compress($root, (string) ($payload['directory'] ?? ''), $relatives, $progress, array_values(array_map('strval', (array) ($payload['skip'] ?? []))), isset($payload['name']) ? (string) $payload['name'] : null), 'message' => 'Created '.basename($path).'.'],
                'files.extract' => ['path' => $path = $this->files->extract($root, $relatives[0] ?? '', $progress), 'message' => 'Extracted to '.basename($path).'.'],
                'files.copy' => ['message' => $this->countLabel($this->files->copy($root, $relatives, (string) ($payload['destination'] ?? ''), $progress)).' copied to /'.($payload['destination'] ?? '').'.'],
                'files.delete' => ['message' => $this->countLabel($this->files->delete($root, $relatives, $progress)).' deleted.'],
                'files.download' => $this->prepareDownload($task, $root, $relatives[0] ?? '', $progress),
                default => throw new RuntimeException('Unknown task.'),
            };
        }

        return match ($task->kind) {
            'backup.inspect' => $this->backups->inspectTask($task, $progress),
            'backup.export' => $this->backups->exportTask($task, $progress),
            'websites.switch' => $this->switch->switchTask($task),
            'websites.delete' => $this->switch->deleteTask($task),
            'websites.own-user' => $this->ownUserTask($task),
            'websites.domains' => $this->domainsTask($task),
            'websites.redirects' => $this->redirectsTask($task),
            'ai.install' => $this->aiInstallTask($task),
            'glitchtip.install' => ['message' => app(Glitchtip::class)->install(User::query()->findOrFail((int) ($task->payload['user_id'] ?? 0)))],
            'glitchtip.remove' => ['message' => app(Glitchtip::class)->uninstall()],
            'glitchtip.sdk' => app(GlitchtipSdk::class)->setUp(Website::query()->findOrFail((int) ($task->payload['website_id'] ?? 0))),
            default => throw new RuntimeException('Unknown task.'),
        };
    }

    /**
     * A website's domains on nginx, the tunnel, and Cloudflare DNS (several Cloudflare API calls),
     * after the website page saved them; routes left on a tunnel it moved away from go too.
     *
     * @return array{message: string, failed?: bool}
     */
    private function domainsTask(PanelTask $task): array
    {
        $payload = $task->payload ?? [];
        $website = Website::query()->find((int) ($payload['website_id'] ?? 0)) ?? throw new RuntimeException('That website no longer exists.');
        $left = ['configured' => false, 'lines' => [], 'note' => null];

        if ($previous = CloudflareTunnel::query()->find((int) ($payload['previous_tunnel_id'] ?? 0))) {
            app(ConfigRenderer::class)->refreshTunnel($previous);
            $left = app(CloudflareRoutes::class)->forget($previous, array_values((array) ($payload['previous_domains'] ?? [])));
        }

        $domain = isset($payload['domain']) ? (string) $payload['domain'] : null;
        $report = app(WebsiteDomains::class)->apply($website, $domain, array_values(array_map('strval', (array) ($payload['aliases'] ?? []))));
        $problems = [...CloudflareRoutes::problems($left), ...CloudflareRoutes::problems($report)];

        return $problems === []
            ? ['message' => $report['lines'] !== [] ? $website->name.': domains set in nginx, the tunnel, and Cloudflare DNS.' : $website->name.': domains applied.']
            : ['message' => $website->name.': Cloudflare needs attention: '.implode(' ', $problems), 'failed' => true];
    }

    /**
     * Install an agent's CLI for the panel AI chat's user.
     *
     * @return array{message: string, failed?: bool}
     */
    private function aiInstallTask(PanelTask $task): array
    {
        $agent = (string) ($task->payload['agent'] ?? '');
        $assistant = app(AiAssistant::class);

        if (! isset(AiAssistant::AGENTS[$agent])) {
            throw new RuntimeException('Unknown agent.');
        }

        $assistant->prepare(User::query()->findOrFail((int) ($task->payload['user_id'] ?? 0)));
        $output = $assistant->install($agent);
        $product = AiAssistant::AGENTS[$agent]['product'];

        return $assistant->status($agent)['installed']
            ? ['message' => $product.' is installed for the AI chat.']
            : ['message' => $product.' could not be installed: '.Str::limit($output, 300), 'failed' => true];
    }

    /**
     * A website's redirects on nginx and, for other domains, the tunnel and Cloudflare DNS.
     *
     * @return array{message: string, failed?: bool}
     */
    private function redirectsTask(PanelTask $task): array
    {
        $payload = $task->payload ?? [];
        $website = Website::query()->find((int) ($payload['website_id'] ?? 0)) ?? throw new RuntimeException('That website no longer exists.');

        try {
            $report = app(WebsiteRedirects::class)->apply($website, array_values((array) ($payload['rows'] ?? [])));
        } catch (ValidationException $exception) {
            return ['message' => $website->name.': '.collect($exception->errors())->flatten()->first(), 'failed' => true];
        }

        $problems = CloudflareRoutes::problems($report);

        return $problems === []
            ? ['message' => $website->name.': redirects applied.']
            : ['message' => $website->name.': redirects applied, but Cloudflare needs attention: '.implode(' ', $problems), 'failed' => true];
    }

    /**
     * Move one website to its own user (for VS Code), as laravelify:separate-sites does: the
     * website is written again and checked, and goes back to www-data if it stops answering.
     * A Node app restarting under PM2 can take a while, so this runs here, not in a page request.
     *
     * @return array{message: string, failed?: bool}
     */
    private function ownUserTask(PanelTask $task): array
    {
        $website = Website::query()->find((int) ($task->payload['website_id'] ?? 0)) ?? throw new RuntimeException('That website no longer exists.');
        $task->report(10, 'Moving '.$website->name.' to its own user…', true);

        $exit = Artisan::call('laravelify:separate-sites', ['--site' => [$website->slug]]);
        $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output()))));
        $moved = app(SiteUser::class)->for($website)->isOwn();

        return $exit === 0 && $moved
            ? ['message' => $website->name.' runs as its own user now.']
            : ['message' => end($lines) ?: 'The website could not be moved to its own user.', 'failed' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareDownload(PanelTask $task, string $root, string $relative, callable $progress): array
    {
        $file = 'task-'.$task->id.'-'.Str::random(12).'.zip';
        $name = $this->files->zipForDownload($root, $relative, PanelTask::directory().DIRECTORY_SEPARATOR.$file, $task->scope !== 'files:global', $progress);

        return ['file' => $file, 'download_name' => $name, 'message' => $name.' is ready to download.'];
    }

    private function countLabel(int $count): string
    {
        return $count === 1 ? '1 item' : $count.' items';
    }
}
