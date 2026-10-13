<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\ProcessCommands;
use App\Services\SiteCommand;
use App\Services\SitePaths;
use App\Support\TerminalCommands;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;

class Terminal extends PanelComponent
{
    /** The site the command runs in, or null for the global terminal. */
    public ?Website $website = null;

    public string $command = '';

    public string $output = '';

    public string $workingDirectory = '';

    /** Global terminal only: the folder under the sites root, kept in the URL as ?dir=. */
    #[Url(as: 'dir', except: '')]
    public string $directory = '';

    public ?int $exitCode = null;

    public string $lastCommand = '';

    public function mount(?Website $website = null): void
    {
        $this->website = $website;
        $this->directory = trim(str_replace('\\', '/', $this->directory), '/');
        $this->locate();
    }

    public function updatedDirectory(): void
    {
        $this->directory = trim(str_replace('\\', '/', $this->directory), '/');
        $this->locate();
        $this->dispatch('terminal-folder-changed');
    }

    public function run(SiteCommand $commands): void
    {
        $this->validate([
            'command' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $result = app(ProcessCommands::class)->handle($this->command) ?? ($this->website
                ? $commands->run($this->website, $this->command)
                : $commands->runGlobal($this->directory, $this->command));
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }

        $this->lastCommand = $this->command;
        $this->output = $result->output;
        $this->exitCode = $result->exitCode;
        $this->command = '';
        $this->dispatch('terminal-ran', command: $this->lastCommand);

        if ($result->skipped) {
            $this->toast($result->output !== '' ? $result->output : 'Host commands are disabled on this machine.', 'error');

            return;
        }

        if (str_contains($result->output, '[output truncated]')) {
            $this->toast('Command finished. Output was truncated.');

            return;
        }

        $this->toast($result->ok ? 'Command finished.' : 'Command failed.', $result->ok ? 'success' : 'error');
    }

    public function clear(): void
    {
        $this->reset('output', 'exitCode', 'lastCommand');
    }

    /**
     * Every artisan command of the Laravel app in the working folder, for the "/" menu.
     * Read from `php artisan list --format=json` and cached for ten minutes.
     *
     * @return list<array{command: string, description: string}>
     */
    #[Renderless]
    public function artisanCommands(SiteCommand $commands): array
    {
        $folder = $this->workingDirectory;

        if ($folder === '' || ! is_file($folder.DIRECTORY_SEPARATOR.'artisan')) {
            return [];
        }

        $key = 'terminal-artisan:'.md5($folder);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $listCommand = 'php artisan list --format=json --no-ansi';
            $result = $this->website
                ? $commands->run($this->website, $listCommand)
                : $commands->runGlobal($this->directory, $listCommand);
        } catch (Throwable) {
            return [];
        }

        $start = strpos($result->output, '{');
        $data = $result->ok && $start !== false ? json_decode(substr($result->output, $start), true) : null;

        if (! is_array($data) || ! is_array($data['commands'] ?? null)) {
            return [];
        }

        $list = collect($data['commands'])
            ->filter(fn ($command): bool => is_array($command) && is_string($command['name'] ?? null) && ! ($command['hidden'] ?? false) && ! str_starts_with($command['name'], '_'))
            ->map(fn (array $command): array => [
                'command' => 'php artisan '.$command['name'],
                'description' => (string) ($command['description'] ?? ''),
            ])
            ->sortBy('command')
            ->values()
            ->all();

        Cache::put($key, $list, now()->addMinutes(10));

        return $list;
    }

    public function render()
    {
        return view('livewire.websites.terminal', [
            'siteFolders' => $this->website ? [] : $this->siteFolders(),
            'suggestions' => TerminalCommands::for($this->website),
        ]);
    }

    private function locate(): void
    {
        $paths = app(SitePaths::class);

        try {
            if ($this->website) {
                $this->workingDirectory = $paths->root($this->website);

                return;
            }

            $sitesRoot = realpath((string) config('laravelify.sites_root'));

            if ($sitesRoot === false) {
                throw new RuntimeException('The sites folder '.config('laravelify.sites_root').' does not exist on this machine.');
            }

            $folder = $paths->resolveIn($sitesRoot, $this->directory);

            if (! is_dir($folder)) {
                throw new RuntimeException('That folder does not exist.');
            }

            $this->workingDirectory = $folder;
        } catch (Throwable $e) {
            if (! $this->website && $this->directory !== '') {
                $this->directory = '';
                $this->toast($e->getMessage(), 'error');
                $this->locate();

                return;
            }

            $this->workingDirectory = $this->website ? (string) $this->website->deploy_path : (string) config('laravelify.sites_root');
            $this->output = $e->getMessage();
        }
    }

    /**
     * Deployed site folders under the sites root, for the folder picker.
     *
     * @return list<array{name: string, relative: string}>
     */
    private function siteFolders(): array
    {
        $paths = app(SitePaths::class);
        $sitesRoot = realpath((string) config('laravelify.sites_root'));

        if ($sitesRoot === false) {
            return [];
        }

        return Website::query()->orderBy('name')->get(['name', 'deploy_path'])
            ->map(function (Website $website) use ($paths, $sitesRoot): ?array {
                $folder = is_string($website->deploy_path) ? realpath($website->deploy_path) : false;

                if ($folder === false || ! $paths->inside($sitesRoot, $folder)) {
                    return null;
                }

                return ['name' => $website->name, 'relative' => $paths->relative($sitesRoot, $folder)];
            })
            ->filter()
            ->values()
            ->all();
    }
}
