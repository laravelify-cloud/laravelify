<?php

namespace App\Livewire\Websites;

use App\Livewire\Concerns\FollowsTasks;
use App\Livewire\Concerns\LazyPlaceholder;
use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\HostWriter;
use App\Services\PanelTaskRunner;
use App\Services\PhpExtensions;
use App\Services\Runtimes;
use App\Support\PhpOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Throwable;

/**
 * A PHP website's PHP: its version, the extensions of that version (ticked on and off, saved
 * together), and its own PHP options (on/off flags and values like memory or upload size) in its
 * PHP-FPM pool.
 */
class PhpConfig extends PanelComponent
{
    use FollowsTasks;
    use LazyPlaceholder;

    public Website $website;

    /** version, extensions or options; kept in the address (?php=) so a reload stays on it. */
    #[Url(as: 'php', except: 'version', history: true)]
    public string $section = 'version';

    public string $php_version = '8.4';

    /** @var array<string, string> Value options by form key (PhpOptions::field); empty keeps PHP's default. */
    public array $options = [];

    /** @var array<string, bool> On/off options as they show (the saved value, or PHP's default). */
    public array $flags = [];

    /** @var array<string, bool> The extensions as ticked, saved with saveExtensions(). */
    public array $extensionState = [];

    public string $extensionFilter = '';

    public function mount(Website $website): void
    {
        $this->website = $website;
        $this->php_version = $website->php_version ?: '8.4';
        $this->loadOptions();
        $this->resetExtensions(app(PhpExtensions::class));
    }

    public function saveVersion(HostWriter $writer): void
    {
        $this->validate(['php_version' => ['required', Rule::in(config('laravelify.php_versions'))]]);

        if ($this->php_version === $this->website->php_version) {
            $this->toast("The website already runs on PHP {$this->php_version}.", 'info');

            return;
        }

        if (! app(Runtimes::class)->phpInstalled($this->php_version)) {
            app(PanelTaskRunner::class)->start('runtimes.php', 'website:'.$this->website->id, 'Installing PHP '.$this->php_version.' for '.$this->website->name, [
                'website_id' => $this->website->id,
                'version' => $this->php_version,
            ]);
            $this->toast('PHP '.$this->php_version.' is not on this server yet: installing it (1 to 3 minutes). The website switches to it when it is ready.', 'info');

            return;
        }

        $before = $this->website->php_version;
        $this->website->update(['php_version' => $this->php_version]);

        try {
            $writer->writeWebsite($this->website->fresh());
        } catch (Throwable $exception) {
            $this->website->update(['php_version' => $before]);
            $this->php_version = (string) $before;
            $this->toast('PHP '.$this->php_version.' could not be used: '.$exception->getMessage(), 'error');

            return;
        }

        $this->resetExtensions(app(PhpExtensions::class));
        $this->toast("The website now runs on PHP {$this->php_version}. Running requests finish on the old version.");
    }

    /** Untick everything changed since the page opened: back to what the server has. */
    public function resetExtensions(PhpExtensions $extensions): void
    {
        $this->extensionState = collect($extensions->forVersion($this->version()))->mapWithKeys(fn (array $extension) => [$extension['module'] => $extension['enabled']])->all();
    }

    /**
     * Apply the ticked changes in the background: installing a package takes a while, and every
     * change reloads PHP-FPM for all websites on this version.
     */
    public function saveExtensions(PhpExtensions $extensions): void
    {
        $changes = $this->extensionChanges($extensions);

        if ($changes === []) {
            $this->toast('Nothing to change.', 'info');

            return;
        }

        $this->runInBackground('php.extensions', 'php:'.$this->version(), 'Changing '.count($changes).' PHP '.$this->version().' '.(count($changes) === 1 ? 'extension' : 'extensions'), [
            'version' => $this->version(),
            'changes' => $changes,
        ]);
    }

    /** One extension at once (kept for the MCP tools and older pages). */
    public function toggleExtension(string $module, PhpExtensions $extensions): void
    {
        $current = collect($extensions->forVersion($this->version()))->firstWhere('module', $module);

        if (! $current) {
            return;
        }

        try {
            $extensions->set($this->version(), $module, ! $current['enabled']);
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->extensionState[$module] = ! $current['enabled'];
        $this->toast(($current['enabled'] ? 'Turned off ' : 'Turned on ').$module." for every website on PHP {$this->version()}.");
    }

    public function saveOptions(HostWriter $writer): void
    {
        $rules = [];

        foreach (PhpOptions::values() as $key => $option) {
            $rules['options.'.PhpOptions::field($key)] = ['nullable', 'string', 'max:4000', 'regex:'.$option['pattern']];
        }

        $this->validate($rules, ['options.*.regex' => 'This value is not in the right format.']);

        $values = [];

        foreach (PhpOptions::values() as $key => $option) {
            $values[$key] = $this->options[PhpOptions::field($key)] ?? '';
        }

        foreach (PhpOptions::flags() as $key => $flag) {
            $value = ($this->flags[PhpOptions::field($key)] ?? false) ? 'on' : 'off';
            $values[$key] = $value === $flag['default'] && ! isset($this->website->php_options[$key]) ? '' : $value;
        }

        $clean = PhpOptions::clean($values);

        if (isset($clean['upload_max_filesize'], $clean['post_max_size'])
            && PhpOptions::megabytes($clean['post_max_size']) < PhpOptions::megabytes($clean['upload_max_filesize'])) {
            $this->addError('options.post_max_size', 'Make it at least the largest upload, or uploads fail.');

            return;
        }

        $before = $this->website->php_options;
        $this->website->update(['php_options' => $clean ?: null]);

        try {
            $writer->writeWebsite($this->website->fresh());
        } catch (Throwable $exception) {
            $this->website->update(['php_options' => $before]);
            $this->toast('The PHP options were not applied: '.$exception->getMessage(), 'error');

            return;
        }

        $this->toast('PHP options saved. New requests use them.');
    }

    /** Back to the saved options. */
    public function resetOptions(): void
    {
        $this->resetErrorBag();
        $this->loadOptions();
    }

    public function render(PhpExtensions $extensions): View
    {
        $all = $extensions->forVersion($this->version());
        $filter = strtolower(trim($this->extensionFilter));

        return view('livewire.websites.php-config', [
            'phpVersions' => config('laravelify.php_versions'),
            'installedVersions' => collect(config('laravelify.php_versions'))->filter(fn (string $version) => is_file("/usr/sbin/php-fpm{$version}"))->values()->all(),
            'extensions' => collect($all)->filter(fn (array $extension) => $filter === '' || str_contains($extension['module'], $filter) || str_contains(strtolower($extension['help']), $filter))->values()->all(),
            'builtIn' => $this->section === 'extensions' ? $extensions->builtIn($this->version()) : [],
            'pendingChanges' => count($this->extensionChanges($extensions, $all)),
            'flagList' => PhpOptions::flags(),
            'optionList' => PhpOptions::values(),
            'choices' => PhpOptions::choices(),
        ]);
    }

    private function version(): string
    {
        return $this->website->php_version ?: '8.4';
    }

    private function loadOptions(): void
    {
        $saved = (array) $this->website->php_options;
        $this->options = collect(PhpOptions::values())->mapWithKeys(fn (array $option, string $key) => [PhpOptions::field($key) => (string) ($saved[$key] ?? '')])->all();
        $this->flags = collect(PhpOptions::flags())->mapWithKeys(fn (array $flag, string $key) => [PhpOptions::field($key) => ($saved[$key] ?? $flag['default']) === 'on'])->all();
    }

    /**
     * The ticked extensions that differ from the server, module => on.
     *
     * @param  list<array{module: string, enabled: bool}>|null  $current
     * @return array<string, bool>
     */
    private function extensionChanges(PhpExtensions $extensions, ?array $current = null): array
    {
        $changes = [];

        foreach ($current ?? $extensions->forVersion($this->version()) as $extension) {
            $wanted = (bool) ($this->extensionState[$extension['module']] ?? $extension['enabled']);

            if ($wanted !== $extension['enabled']) {
                $changes[$extension['module']] = $wanted;
            }
        }

        return $changes;
    }
}
