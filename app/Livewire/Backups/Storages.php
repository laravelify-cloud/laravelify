<?php

namespace App\Livewire\Backups;

use App\Livewire\PanelComponent;
use App\Models\BackupStorage;
use App\Services\BackupStorageClient;
use App\Services\HostWriter;
use Illuminate\Validation\Rule;
use Throwable;

class Storages extends PanelComponent
{
    /**
     * Endpoint hints for the providers people use. {region} is replaced with the region field.
     *
     * @var array<string, array{label: string, endpoint: string, region: string, hint: string}>
     */
    public const PROVIDERS = [
        'aws' => ['label' => 'Amazon S3', 'endpoint' => '', 'region' => 'us-east-1', 'hint' => 'Leave the endpoint empty.'],
        'spaces' => ['label' => 'DigitalOcean Spaces', 'endpoint' => 'https://{region}.digitaloceanspaces.com', 'region' => 'nyc3', 'hint' => 'Region is the Space region, like nyc3 or fra1.'],
        'r2' => ['label' => 'Cloudflare R2', 'endpoint' => 'https://ACCOUNT_ID.r2.cloudflarestorage.com', 'region' => 'auto', 'hint' => 'Replace ACCOUNT_ID with your Cloudflare account id. Region is auto.'],
        'b2' => ['label' => 'Backblaze B2', 'endpoint' => 'https://s3.{region}.backblazeb2.com', 'region' => 'us-west-004', 'hint' => 'Region is shown on the bucket, like us-west-004.'],
        'wasabi' => ['label' => 'Wasabi', 'endpoint' => 'https://s3.{region}.wasabisys.com', 'region' => 'us-east-1', 'hint' => 'Use your Wasabi region.'],
        'custom' => ['label' => 'MinIO or other S3', 'endpoint' => 'https://', 'region' => 'us-east-1', 'hint' => 'Any S3-compatible endpoint.'],
        'local' => ['label' => 'This server (a folder)', 'endpoint' => '', 'region' => 'local', 'hint' => 'No bucket needed: backups are saved in a folder on this server, outside /var/www. They are lost with the server, so copy them elsewhere too.'],
    ];

    /**
     * Folders a local storage may not be in: system folders, website folders, and the panel itself.
     */
    private const FORBIDDEN_FOLDERS = ['/var/www', '/etc', '/usr', '/bin', '/sbin', '/lib', '/lib64', '/boot', '/proc', '/sys', '/dev', '/run', '/tmp', '/var/lib', '/var/log', '/root'];

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $provider = 'aws';

    public string $endpoint = '';

    public string $region = 'us-east-1';

    public string $bucket = '';

    public string $path = 'laravelify';

    public string $access_key = '';

    public string $secret_key = '';

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'endpoint', 'bucket', 'access_key', 'secret_key']);
        $this->provider = 'aws';
        $this->region = 'us-east-1';
        $this->path = 'laravelify';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $storage = BackupStorage::query()->findOrFail($id);
        $this->editingId = $storage->id;
        $this->name = $storage->name;
        $this->provider = $storage->isLocal() ? 'local' : (filled($storage->endpoint) ? 'custom' : 'aws');
        $this->endpoint = (string) $storage->endpoint;
        $this->region = (string) $storage->region;
        $this->bucket = $storage->bucket;
        $this->path = (string) $storage->path;
        $this->access_key = '';
        $this->secret_key = '';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function updatedProvider(string $provider): void
    {
        $preset = self::PROVIDERS[$provider] ?? null;

        if ($preset) {
            $this->path = $provider === 'local' ? '/backups' : ($this->path !== '' && ! str_starts_with($this->path, '/') ? $this->path : 'laravelify');
            $this->region = $preset['region'];
            $this->endpoint = str_replace('{region}', $preset['region'], $preset['endpoint']);
        }
    }

    public function updatedRegion(string $region): void
    {
        $template = self::PROVIDERS[$this->provider]['endpoint'] ?? '';

        if (str_contains($template, '{region}')) {
            $this->endpoint = str_replace('{region}', trim($region), $template);
        }
    }

    public function save(BackupStorageClient $client, HostWriter $host): void
    {
        $editing = $this->editingId ? BackupStorage::query()->findOrFail($this->editingId) : null;

        if ($this->provider === 'local') {
            $this->saveLocal($client, $host, $editing);

            return;
        }

        $keysRequired = $editing === null;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('backup_storages', 'name')->ignore($editing?->id)],
            'endpoint' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/)?[A-Za-z0-9.\-]+(:\d+)?\/?$/'],
            'region' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'bucket' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-_]+$/'],
            'path' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9._\-\/]*$/', 'not_regex:/\.\./'],
            'access_key' => [$keysRequired ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^\S+$/'],
            'secret_key' => [$keysRequired ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^\S+$/'],
        ], [
            'endpoint.regex' => 'Use the host name only, like nyc3.digitaloceanspaces.com, without a path.',
            'bucket.regex' => 'A bucket name has letters, numbers, dots, dashes, and underscores.',
            'access_key.regex' => 'The key has no spaces.',
            'secret_key.regex' => 'The key has no spaces.',
        ]);

        $attributes = [
            'name' => $validated['name'],
            'driver' => BackupStorage::DRIVER_S3,
            'endpoint' => filled($validated['endpoint']) ? trim($validated['endpoint']) : null,
            'region' => $validated['region'],
            'bucket' => $validated['bucket'],
            'path' => trim((string) $validated['path'], '/'),
            'checked_at' => null,
            'last_error' => null,
        ];

        if (filled($validated['access_key'])) {
            $attributes['access_key'] = $validated['access_key'];
        }

        if (filled($validated['secret_key'])) {
            $attributes['secret_key'] = $validated['secret_key'];
        }

        $storage = $editing ?? new BackupStorage(['is_default' => ! BackupStorage::query()->exists()]);
        $storage->fill($attributes)->save();

        $this->showForm = false;
        $this->runTest($client, $storage);
    }

    /**
     * A folder on this server: made (owned by the panel's user) and tested like a bucket.
     */
    private function saveLocal(BackupStorageClient $client, HostWriter $host, ?BackupStorage $editing): void
    {
        $this->path = '/'.trim(trim($this->path), '/');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('backup_storages', 'name')->ignore($editing?->id)],
            'path' => ['required', 'string', 'max:190', 'regex:/^\/[A-Za-z0-9._\-\/]+$/', 'not_regex:/\.\./', function (string $attribute, mixed $value, \Closure $fail): void {
                $folder = rtrim((string) $value, '/').'/';

                foreach ([...self::FORBIDDEN_FOLDERS, rtrim(base_path(), '/')] as $forbidden) {
                    if (str_starts_with($folder, $forbidden.'/')) {
                        $fail('Choose a folder outside '.$forbidden.', like /backups or /home/backups.');

                        return;
                    }
                }
            }],
        ], [
            'path.required' => 'Choose a folder, like /backups.',
            'path.regex' => 'Use a full path like /backups, with letters, numbers, dots, dashes, and slashes.',
        ]);

        try {
            $host->backupFolder($validated['path']);
        } catch (Throwable $exception) {
            $this->addError('path', $exception->getMessage());

            return;
        }

        $storage = $editing ?? new BackupStorage(['is_default' => ! BackupStorage::query()->exists()]);
        $storage->fill([
            'name' => $validated['name'],
            'driver' => BackupStorage::DRIVER_LOCAL,
            'endpoint' => null,
            'region' => 'local',
            'bucket' => '',
            'path' => trim($validated['path'], '/'),
            'access_key' => '',
            'secret_key' => '',
            'checked_at' => null,
            'last_error' => null,
        ])->save();

        $this->showForm = false;
        $this->runTest($client, $storage);
    }

    public function test(BackupStorageClient $client, int $id): void
    {
        $this->runTest($client, BackupStorage::query()->findOrFail($id));
    }

    public function makeDefault(int $id): void
    {
        BackupStorage::query()->update(['is_default' => false]);
        BackupStorage::query()->whereKey($id)->update(['is_default' => true]);
        $this->toast('Default storage changed.');
    }

    public function delete(int $id): void
    {
        $storage = BackupStorage::query()->withCount('schedules')->findOrFail($id);

        if ($storage->schedules_count > 0) {
            $this->toast('Move or delete the '.$storage->schedules_count.' schedule(s) using this storage first.', 'error');

            return;
        }

        $storage->delete();

        if (! BackupStorage::query()->where('is_default', true)->exists() && ($first = BackupStorage::query()->first())) {
            $first->update(['is_default' => true]);
        }

        $this->toast('Storage removed. Files already in the bucket are not deleted.');
    }

    private function runTest(BackupStorageClient $client, BackupStorage $storage): void
    {
        try {
            $client->test($storage);
            $storage->forceFill(['checked_at' => now(), 'last_error' => null])->save();
            $this->toast('Connected: a test file was written to '.$storage->location().' and removed.');
        } catch (Throwable $exception) {
            $storage->forceFill(['checked_at' => null, 'last_error' => $exception->getMessage()])->save();
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function render()
    {
        return view('livewire.backups.storages', [
            'storages' => BackupStorage::query()->withCount('schedules')->orderBy('name')->get(),
            'providers' => self::PROVIDERS,
        ])->layout('layouts.app')->title('Backup storage');
    }
}
