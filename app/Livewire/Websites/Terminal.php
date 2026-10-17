<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\ProcessCommands;
use App\Services\SiteCommand;
use App\Services\SitePaths;
use App\Services\SiteUser;
use App\Services\TerminalSessions;
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

    /** The size of the terminal on screen, sent with the next command. */
    public int $columns = 120;

    public int $rows = 30;

    public string $workingDirectory = '';

    /** Global terminal only: the folder under the sites root, kept in the URL as ?dir=. */
    #[Url(as: 'dir', except: '')]
    public string $directory = '';

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

    public function run(SiteCommand $commands, TerminalSessions $sessions): void
    {
        $this->validate([
            'command' => ['required', 'string', 'max:4000'],
        ]);

        $command = $this->command;

        try {
            $handled = app(ProcessCommands::class)->handle($command);

            if ($handled === null && ! $this->website) {
                // Fails early, with a message, when the folder is not one the terminal may use.
                $commands->interactiveArguments(null, $this->directory, $command);
            }

            $session = $handled === null
                ? $sessions->start($this->website, $this->directory, $command, auth()->id(), $this->columns, $this->rows)
                : null;
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }

        $this->lastCommand = $command;
        $this->command = '';
        $this->dispatch('terminal-ran', command: $command);

        if ($session === null) {
            // PM2 and Supervisor commands are answered by the panel itself: show the answer.
            $this->dispatch('terminal-text', command: $command, text: $handled->output, exit: $handled->exitCode);

            return;
        }

        $this->dispatch('terminal-session', id: $session, command: $command);
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
            'runsAs' => app(SiteUser::class)->terminalUserFor($this->workingDirectory),
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
            $this->toast($e->getMessage(), 'error');
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
