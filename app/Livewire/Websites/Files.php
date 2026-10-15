<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\PanelTask;
use App\Models\TrashItem;
use App\Models\Website;
use App\Services\DisplayTime;
use App\Services\FileOperations;
use App\Services\FileTrash;
use App\Services\PanelTaskRunner;
use App\Services\SitePaths;
use App\Services\UploadLimits;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

class Files extends PanelComponent
{
    use WithFileUploads;

    /**
     * Largest file the editor will load, in bytes. Livewire caps a request at 1 MB
     * and the content travels both in the snapshot and as the update.
     */
    public const int EDITABLE_LIMIT = 256 * 1024;

    public const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'];

    /** The site being browsed, or null for the global file manager. */
    public ?Website $website = null;

    /** The open folder, relative to the site root, kept in the URL as ?path=. */
    #[Url(as: 'path', history: true, except: '')]
    public string $directory = '';

    public string $workingDirectory = '';

    /** @var list<array{name: string, relative: string, directory: bool, link: bool, bytes: int, size: string, timestamp: int, modified: string, kind: string, extension: string, permissions: string}> */
    public array $entries = [];

    public string $listingError = '';

    public string $sortBy = 'name';

    public bool $sortDescending = false;

    /** The trash is shown instead of the folder. */
    public bool $showTrash = false;

    /** @var list<int> Background tasks whose end was already shown. */
    public array $announced = [];

    public string $folderName = '';

    public string $fileName = '';

    public $uploads = [];

    /** The file in the viewer, relative to the site root, kept in the URL as ?file=. */
    #[Url(as: 'file', history: true, except: '')]
    public string $openPath = '';

    public string $openName = '';

    /** One of text, image, binary, large. */
    public string $openKind = '';

    public string $openSize = '';

    public string $openModified = '';

    public string $openContent = '';

    public function mount(SitePaths $paths, ?Website $website = null): void
    {
        $this->website = $website;
        $this->announced = PanelTask::query()->where('scope', $this->taskScope())->whereIn('status', [PanelTask::SUCCESS, PanelTask::FAILED])->pluck('id')->all();
        $this->directory = trim(str_replace('\\', '/', $this->directory), '/');
        $this->refreshList();

        if ($this->listingError !== '' && $this->directory !== '') {
            $this->directory = '';
            $this->refreshList();
        }

        if ($this->openPath !== '') {
            $this->openFile($this->openPath, $paths);
        }
    }

    /**
     * Follow the folder from the URL when the browser goes back or forward.
     */
    public function updatedDirectory(): void
    {
        $this->refreshList();
        $this->dispatch('directory-changed');
    }

    /**
     * Follow the open file from the URL when the browser goes back or forward.
     */
    public function updatedOpenPath(string $value, SitePaths $paths): void
    {
        $value === '' ? $this->closeFile() : $this->openFile($value, $paths);
    }

    /**
     * Go to a typed path. A folder is opened, a file is opened in its folder.
     * Absolute paths inside the site folder are accepted too.
     */
    public function goToPath(string $path, SitePaths $paths): bool
    {
        try {
            $root = $this->rootPath();
            $path = str_replace('\\', '/', trim($path));
            $rootPrefix = rtrim(str_replace('\\', '/', $root), '/');

            if ($path === $rootPrefix || str_starts_with($path, $rootPrefix.'/')) {
                $path = substr($path, strlen($rootPrefix));
            }

            $relative = trim($path, '/');
            $full = $paths->locateIn($this->rootPath(), $relative);

            if (! is_link($full) && is_dir($full)) {
                $this->openDirectory($relative, $paths);

                return true;
            }

            if (is_file($full)) {
                $parent = dirname($relative);
                $this->openDirectory($parent === '.' ? '' : $parent, $paths);
                $this->openFile($relative, $paths);

                return true;
            }

            throw new RuntimeException('That path does not exist.');
        } catch (Throwable) {
            $this->toast('No file or folder at that path.', 'error');

            return false;
        }
    }

    public function openDirectory(string $relative, SitePaths $paths): void
    {
        try {
            $full = $paths->resolveIn($this->rootPath(), $relative);

            if (is_link($full) || ! is_dir($full)) {
                throw new RuntimeException('That folder does not exist.');
            }

            $this->directory = $paths->relative($this->rootPath(), $full);
            $this->refreshList();
            $this->dispatch('directory-changed');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function up(SitePaths $paths): void
    {
        if ($this->directory === '') {
            return;
        }

        $parent = dirname(str_replace('\\', '/', $this->directory));
        $this->openDirectory($parent === '.' ? '' : $parent, $paths);
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ['name', 'size', 'modified'], true)) {
            return;
        }

        $this->sortDescending = $this->sortBy === $column ? ! $this->sortDescending : false;
        $this->sortBy = $column;
        $this->entries = $this->sorted($this->entries);
    }

    public function refreshList(): void
    {
        $this->listingError = '';
        $this->entries = [];

        try {
            $paths = app(SitePaths::class);
            $root = $this->rootPath();
            $dir = $paths->resolveIn($this->rootPath(), $this->directory);

            if (is_link($dir) || ! is_dir($dir)) {
                throw new RuntimeException('That folder does not exist.');
            }

            $this->workingDirectory = $root;
            $this->directory = $paths->relative($root, $dir);
            $names = scandir($dir);

            if ($names === false) {
                throw new RuntimeException('Could not read that folder.');
            }

            $rows = [];

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $full = $dir.DIRECTORY_SEPARATOR.$name;
                $isLink = is_link($full);
                $isDir = ! $isLink && is_dir($full);
                $bytes = (! $isDir && is_file($full)) ? @filesize($full) : false;
                $modified = @filemtime($full);
                $perms = $isLink ? false : @fileperms($full);

                $rows[] = [
                    'name' => $name,
                    'relative' => ltrim($this->directory.'/'.$name, '/'),
                    'directory' => $isDir,
                    'link' => $isLink,
                    'bytes' => $bytes === false ? 0 : (int) $bytes,
                    'size' => $isDir ? '' : $this->sizeLabel($bytes === false ? 0 : (int) $bytes),
                    'timestamp' => $modified === false ? 0 : (int) $modified,
                    'modified' => $modified === false ? '' : app(DisplayTime::class)->datetime((int) $modified),
                    'kind' => $this->kind($isDir, $name),
                    'extension' => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                    'permissions' => $perms === false ? '' : substr(sprintf('%o', $perms), -3),
                ];
            }

            $this->entries = $this->sorted($rows);
        } catch (Throwable $e) {
            $this->listingError = $e->getMessage();
        }
    }

    public function openFile(string $relative, SitePaths $paths): void
    {
        try {
            $full = $paths->locateIn($this->rootPath(), $relative);

            if (is_link($full) || ! is_file($full) || ! is_readable($full)) {
                throw new RuntimeException('That file cannot be opened.');
            }

            $bytes = (int) @filesize($full);
            $extension = strtolower(pathinfo($full, PATHINFO_EXTENSION));

            $this->openPath = $paths->relative($this->rootPath(), $full);
            $this->openName = basename($full);
            $this->openSize = $this->sizeLabel($bytes);
            $this->openModified = app(DisplayTime::class)->datetime((int) @filemtime($full));
            $this->openContent = '';

            if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                $this->openKind = 'image';

                return;
            }

            if ($bytes > self::EDITABLE_LIMIT) {
                $this->openKind = 'large';

                return;
            }

            $content = file_get_contents($full);

            if ($content === false) {
                throw new RuntimeException('Could not read that file.');
            }

            if (str_contains(substr($content, 0, 8000), "\0") || ! mb_check_encoding($content, 'UTF-8')) {
                $this->openKind = 'binary';

                return;
            }

            $this->openKind = 'text';
            $this->openContent = $content;
        } catch (Throwable $e) {
            $this->closeFile();
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function closeFile(): void
    {
        $this->reset('openPath', 'openName', 'openKind', 'openSize', 'openModified', 'openContent');
    }

    public function saveFile(SitePaths $paths): void
    {
        if ($this->openPath === '' || $this->openKind !== 'text') {
            return;
        }

        $this->validate([
            'openContent' => ['present', 'nullable', 'string', 'max:'.self::EDITABLE_LIMIT],
        ]);

        try {
            $full = $paths->locateIn($this->rootPath(), $this->openPath);

            if (is_link($full) || ! is_file($full)) {
                throw new RuntimeException('That file is no longer there.');
            }

            if (file_put_contents($full, (string) $this->openContent, LOCK_EX) === false) {
                throw new RuntimeException('Could not save that file.');
            }

            clearstatcache(true, $full);
            $this->openSize = $this->sizeLabel((int) @filesize($full));
            $this->openModified = app(DisplayTime::class)->datetime((int) @filemtime($full));
            $this->refreshList();
            $this->dispatch('file-saved');
            $this->toast($this->openName.' saved.'.($this->clearConfigCache($full) ? ' Cleared the cached Laravel config so the new values apply.' : ''));
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function createFolder(SitePaths $paths): void
    {
        $this->validate([
            'folderName' => ['required', 'string', 'max:120'],
        ]);

        try {
            $target = $this->newEntryPath($this->folderName, $paths);

            if (! mkdir($target, 0755)) {
                throw new RuntimeException('Could not create that folder.');
            }

            $this->folderName = '';
            $this->refreshList();
            $this->dispatch('entry-created');
            $this->toast('Folder created.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function createFile(SitePaths $paths): void
    {
        $this->validate([
            'fileName' => ['required', 'string', 'max:120'],
        ]);

        try {
            $target = $this->newEntryPath($this->fileName, $paths);

            if (file_put_contents($target, '') === false) {
                throw new RuntimeException('Could not create that file.');
            }

            @chmod($target, 0644);
            $this->fileName = '';
            $this->refreshList();
            $this->dispatch('entry-created');
            $this->openFile($paths->relative($this->rootPath(), $target), $paths);
            $this->toast('File created.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function renameEntry(string $relative, string $newName, SitePaths $paths): void
    {
        try {
            $name = $this->safeName($newName);
            $full = $paths->locateIn($this->rootPath(), $relative);
            $root = $this->rootPath();

            if ($full === $root) {
                throw new RuntimeException('The site folder cannot be renamed.');
            }

            if (basename($full) === $name) {
                return;
            }

            $target = dirname($full).DIRECTORY_SEPARATOR.$name;

            if (! $paths->inside($root, dirname($target))) {
                throw new RuntimeException('That path is outside the site folder.');
            }

            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('That name already exists.');
            }

            if (! rename($full, $target)) {
                throw new RuntimeException('Could not rename that entry.');
            }

            if ($this->openPath === $relative) {
                $this->closeFile();
            }

            $this->refreshList();
            $this->toast('Renamed to '.$name.'.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    /**
     * Move the files uploaded through the browser into a folder of the site.
     */
    public function storeUploads(string $target, SitePaths $paths): void
    {
        $this->validate([
            'uploads' => ['required', 'array', 'min:1'],
            'uploads.*' => ['file', 'max:'.(UploadLimits::MEGABYTES * 1024)],
        ]);

        try {
            $dir = $paths->resolveIn($this->rootPath(), $target);

            if (is_link($dir) || ! is_dir($dir)) {
                throw new RuntimeException('That folder does not exist.');
            }

            $count = 0;

            foreach ($this->uploads as $upload) {
                if (! $upload instanceof TemporaryUploadedFile) {
                    continue;
                }

                $this->writeUpload($dir, $upload);
                $count++;
            }

            if ($count === 0) {
                throw new RuntimeException('Choose a file to upload.');
            }

            $relative = $paths->relative($this->rootPath(), $dir);
            $where = $relative === '' ? 'the site folder' : $relative;

            $this->refreshList();
            $this->toast(($count === 1 ? 'File uploaded' : $count.' files uploaded').' to '.$where.'.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        } finally {
            $this->reset('uploads');
        }
    }

    public function deleteEntry(string $relative): void
    {
        $this->deleteEntries([$relative]);
    }

    /**
     * Move entries of the current folder to the trash. They can be restored from there.
     *
     * @param  list<string>  $relatives
     */
    public function deleteEntries(array $relatives): void
    {
        $selected = $this->selectedEntries($relatives);

        if ($selected === []) {
            $this->toast('Those files are no longer there.', 'error');

            return;
        }

        try {
            $relatives = array_column($selected, 'relative');

            if (in_array($this->openPath, $relatives, true)) {
                $this->closeFile();
            }

            $moved = app(FileTrash::class)->move($this->taskScope(), $this->rootPath(), $relatives, auth()->id());
            $this->refreshList();
            $this->dispatch('entry-deleted');
            $this->toast(($moved === 1 ? $selected[0]['name'] : $moved.' items').' moved to the trash. You can restore '.($moved === 1 ? 'it' : 'them').' from Trash.');
        } catch (Throwable $e) {
            $this->refreshList();
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function openTrash(): void
    {
        $this->showTrash = true;
        $this->closeFile();
    }

    public function closeTrash(): void
    {
        $this->showTrash = false;
        $this->refreshList();
    }

    public function restoreTrash(int $id): void
    {
        try {
            $item = TrashItem::query()->where('scope', $this->taskScope())->findOrFail($id);
            $restored = app(FileTrash::class)->restore($item, $this->rootPath());
            $this->refreshList();
            $this->toast('Restored to /'.$restored.'.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    /**
     * Restore the newest version of each chosen path.
     *
     * @param  list<string>  $paths
     */
    public function restoreTrashPaths(array $paths): void
    {
        $restored = [];
        $errors = [];

        foreach (array_unique(array_map('strval', $paths)) as $path) {
            $item = TrashItem::query()->where('scope', $this->taskScope())->where('original_path', $path)->latest('id')->first();

            if (! $item) {
                continue;
            }

            try {
                $restored[] = app(FileTrash::class)->restore($item, $this->rootPath());
            } catch (Throwable $e) {
                $errors[] = $item->name.': '.$e->getMessage();
            }
        }

        $this->refreshList();
        $this->dispatch('bulk-done');
        $this->toast($errors === []
            ? 'Restored '.count($restored).' '.(count($restored) === 1 ? 'item' : 'items').': /'.implode(', /', $restored).'.'
            : 'Some items could not be restored: '.implode(' · ', $errors), $errors === [] ? 'success' : 'error');
    }

    /**
     * Delete every version of the chosen paths for good, after the authenticator code.
     *
     * @param  list<string>  $paths
     */
    public function purgeTrashPaths(array $paths, string $code): bool
    {
        return $this->withCode($code, function () use ($paths): string {
            $items = TrashItem::query()->where('scope', $this->taskScope())->whereIn('original_path', array_map('strval', $paths))->get();

            foreach ($items as $item) {
                app(FileTrash::class)->purge($item);
            }

            $this->dispatch('bulk-done');

            return $items->count().' '.($items->count() === 1 ? 'item was' : 'items were').' deleted for good.';
        });
    }

    /**
     * Delete one trashed item for good, after the authenticator code.
     */
    public function purgeTrash(int $id, string $code): bool
    {
        return $this->withCode($code, function () use ($id): string {
            $item = TrashItem::query()->where('scope', $this->taskScope())->findOrFail($id);
            app(FileTrash::class)->purge($item);

            return $item->name.' was deleted for good.';
        });
    }

    /**
     * Delete everything in the trash for good, after the authenticator code.
     */
    public function emptyTrash(string $code): bool
    {
        return $this->withCode($code, function (): string {
            $items = TrashItem::query()->where('scope', $this->taskScope())->get();

            foreach ($items as $item) {
                app(FileTrash::class)->purge($item);
            }

            return 'The trash is empty. '.$items->count().' '.($items->count() === 1 ? 'item was' : 'items were').' deleted for good.';
        });
    }

    /**
     * Run $action only with a valid authenticator code. Errors go to the "code" field.
     */
    private function withCode(string $code, callable $action): bool
    {
        $this->resetErrorBag('code');

        try {
            app(FileTrash::class)->confirmCode(auth()->user(), $code);
        } catch (Throwable $e) {
            $this->addError('code', $e->getMessage());

            return false;
        }

        try {
            $this->toast($action());

            return true;
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');

            return false;
        }
    }

    public function duplicateEntry(string $relative, SitePaths $paths): void
    {
        try {
            $entry = $this->selectedEntries([$relative])[0] ?? throw new RuntimeException('That file is no longer there.');

            if ($entry['link']) {
                throw new RuntimeException('Links cannot be duplicated.');
            }

            if (! $this->operations()->isSmall($this->rootPath(), [$entry['relative']])) {
                $this->startTask('files.copy', 'Duplicating '.$entry['name'], ['relatives' => [$entry['relative']], 'destination' => $this->directory]);

                return;
            }

            $this->operations()->copy($this->rootPath(), [$entry['relative']], $this->directory);
            $this->refreshList();
            $this->toast('Duplicated '.$entry['name'].'.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    /**
     * Copy or move entries of the current folder into another folder of the site.
     *
     * @param  list<string>  $relatives
     */
    public function transferEntries(array $relatives, string $destination, string $mode, SitePaths $paths): void
    {
        if (! in_array($mode, ['copy', 'move'], true)) {
            return;
        }

        $selected = $this->selectedEntries($relatives);

        if ($selected === []) {
            $this->toast('Choose something to '.$mode.'.', 'error');

            return;
        }

        $done = 0;

        try {
            $root = $this->rootPath();
            $destinationPath = $paths->resolveIn($this->rootPath(), $destination);

            if (is_link($destinationPath) || ! is_dir($destinationPath)) {
                throw new RuntimeException('That folder does not exist.');
            }

            if ($mode === 'copy') {
                $relatives = array_column($selected, 'relative');
                $where = $paths->relative($root, $destinationPath);

                if (! $this->operations()->isSmall($root, $relatives)) {
                    $this->startTask('files.copy', 'Copying '.$this->describe($selected).' to /'.$where, ['relatives' => $relatives, 'destination' => $where]);
                    $this->dispatch('entries-transferred');

                    return;
                }

                $done = $this->operations()->copy($root, $relatives, $where);
                $this->refreshList();
                $this->dispatch('entries-transferred');
                $this->toast(($done === 1 ? '1 item' : $done.' items').' copied to /'.$where.'.');

                return;
            }

            foreach ($selected as $entry) {
                $source = $paths->locateIn($this->rootPath(), $entry['relative']);

                if ($source === $root) {
                    throw new RuntimeException('The site folder cannot be '.($mode === 'move' ? 'moved' : 'copied').'.');
                }

                if (! is_link($source) && is_dir($source) && $paths->inside($source, $destinationPath)) {
                    throw new RuntimeException($entry['name'].' cannot go inside itself.');
                }

                $target = $destinationPath.DIRECTORY_SEPARATOR.$entry['name'];

                if (dirname($source) === $destinationPath) {
                    continue;
                }

                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException($entry['name'].' already exists there.');
                }

                if (! rename($source, $target)) {
                    throw new RuntimeException('Could not move '.$entry['name'].'.');
                }

                if ($this->openPath === $entry['relative']) {
                    $this->closeFile();
                }

                $done++;
            }

            $where = $paths->relative($root, $destinationPath);
            $this->refreshList();
            $this->dispatch('entries-transferred');
            $this->toast($done === 0 ? 'Already in /'.$where.'.' : ($done === 1 ? '1 item' : $done.' items').' moved to /'.$where.'.');
        } catch (Throwable $e) {
            $this->refreshList();
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function chmodEntry(string $relative, string $mode, SitePaths $paths): void
    {
        try {
            if (preg_match('/^[0-7]{3}$/', $mode) !== 1) {
                throw new RuntimeException('Use three octal digits, like 644 or 755.');
            }

            $entry = $this->selectedEntries([$relative])[0] ?? throw new RuntimeException('That file is no longer there.');
            $full = $paths->locateIn($this->rootPath(), $entry['relative']);

            if (is_link($full)) {
                throw new RuntimeException('Permissions of a link cannot be changed.');
            }

            if (! chmod($full, (int) octdec($mode))) {
                throw new RuntimeException('Could not change the permissions.');
            }

            clearstatcache(true, $full);
            $this->refreshList();
            $this->dispatch('entry-chmodded');
            $this->toast($entry['name'].' is now '.$mode.'.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    /**
     * Zip entries of the current folder into an archive next to them, in the background.
     *
     * @param  list<string>  $relatives
     */
    public function compressEntries(array $relatives, array $skip = [], string $name = ''): void
    {
        try {
            if (! FileOperations::canZip()) {
                throw new RuntimeException('The PHP zip extension is not installed.');
            }

            $selected = $this->selectedEntries($relatives);

            if ($selected === []) {
                throw new RuntimeException('Choose something to compress.');
            }

            // Only folders inside what is being zipped can be left out.
            $roots = array_column($selected, 'relative');
            $skip = array_values(array_filter(array_map('strval', $skip), fn (string $path) => collect($roots)->contains(fn (string $root) => str_starts_with($path, $root.'/'))));

            $this->startTask('files.compress', 'Compressing '.$this->describe($selected).($skip === [] ? '' : ' without '.count($skip).' '.(count($skip) === 1 ? 'folder' : 'folders')), [
                'relatives' => $roots,
                'directory' => $this->directory,
                'skip' => $skip,
                'name' => trim($name),
            ]);
            $this->dispatch('entries-queued');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    /**
     * For the compress dialog: the folders inside the selection a zip usually does not need
     * (node_modules, vendor, .git, .nuxt ...), and a name for the zip.
     *
     * @param  list<string>  $relatives
     * @return array{name: string, folders: list<array{path: string, name: string, checked: bool}>, error: string}
     */
    #[Renderless]
    public function compressPlan(array $relatives): array
    {
        try {
            $selected = $this->selectedEntries($relatives);

            if ($selected === []) {
                throw new RuntimeException('Choose something to compress.');
            }

            return [
                'name' => count($selected) === 1 ? $selected[0]['name'].'.zip' : 'archive.zip',
                'folders' => $this->operations()->skippable($this->rootPath(), array_column($selected, 'relative')),
                'error' => '',
            ];
        } catch (Throwable $e) {
            return ['name' => 'archive.zip', 'folders' => [], 'error' => $e->getMessage()];
        }
    }

    public function extractEntry(string $relative): void
    {
        try {
            $entry = $this->selectedEntries([$relative])[0] ?? throw new RuntimeException('That file is no longer there.');

            if ($entry['link'] || $entry['directory'] || $entry['extension'] !== 'zip') {
                throw new RuntimeException('Only .zip archives can be extracted.');
            }

            $this->startTask('files.extract', 'Extracting '.$entry['name'], ['relatives' => [$entry['relative']]]);
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    /**
     * Zip a folder in the background; the browser downloads it when it is ready.
     */
    public function prepareDownload(string $relative): void
    {
        try {
            if (! FileOperations::canZip()) {
                throw new RuntimeException('The PHP zip extension is not installed.');
            }

            $relative = trim(str_replace('\\', '/', $relative), '/');

            if ($relative === '' && $this->website === null) {
                throw new RuntimeException('Open a folder to download it. The whole root is not zipped.');
            }

            $full = app(SitePaths::class)->locateIn($this->rootPath(), $relative);

            if (is_link($full) || ! is_dir($full)) {
                throw new RuntimeException('That folder does not exist.');
            }

            $this->startTask('files.download', 'Preparing '.(basename($full) ?: 'folder').'.zip', ['relatives' => [$relative]], 'Zipping '.(basename($full) ?: 'the folder').'. The download starts when it is ready.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    /**
     * Called while background tasks run: tell about the ones that finished since the last call.
     */
    public function pollTasks(): void
    {
        $finished = PanelTask::query()
            ->where('scope', $this->taskScope())
            ->whereIn('status', [PanelTask::SUCCESS, PanelTask::FAILED])
            ->whereNotIn('id', $this->announced ?: [0])
            ->where('updated_at', '>', now()->subHour())
            ->get();

        foreach ($finished as $task) {
            $this->announced[] = $task->id;

            if ($task->status === PanelTask::FAILED) {
                $this->toast($task->label.' failed: '.$task->message, 'error');

                continue;
            }

            if ($task->kind === 'files.download') {
                $this->dispatch('download-ready', url: route('tasks.download', $task));
            }

            $this->toast((string) ($task->message ?: $task->label.' finished.'));
        }

        if ($finished->isNotEmpty()) {
            $this->refreshList();
        }
    }

    public function dismissTask(int $id): void
    {
        $task = PanelTask::query()->where('scope', $this->taskScope())->find($id);

        if ($task && ! $task->isActive()) {
            if ($file = $task->resultFile()) {
                @unlink($file);
            }

            $task->delete();
        }
    }

    /**
     * Sub folders of a site folder, for the copy and move picker.
     *
     * @return array{path: string, folders: list<string>, error: string}
     */
    #[Renderless]
    public function folderTree(string $relative, SitePaths $paths): array
    {
        try {
            $dir = $paths->resolveIn($this->rootPath(), $relative);

            if (is_link($dir) || ! is_dir($dir)) {
                throw new RuntimeException('That folder does not exist.');
            }

            $folders = [];

            foreach (scandir($dir) ?: [] as $name) {
                $full = $dir.DIRECTORY_SEPARATOR.$name;

                if ($name !== '.' && $name !== '..' && ! is_link($full) && is_dir($full)) {
                    $folders[] = $name;
                }
            }

            natcasesort($folders);

            return ['path' => $paths->relative($this->rootPath(), $dir), 'folders' => array_values($folders), 'error' => ''];
        } catch (Throwable $e) {
            return ['path' => $relative, 'folders' => [], 'error' => $e->getMessage()];
        }
    }

    public function render()
    {
        $crumbs = [];
        $prefix = '';

        foreach ($this->directory === '' ? [] : explode('/', $this->directory) as $part) {
            $prefix = ltrim($prefix.'/'.$part, '/');
            $crumbs[] = ['label' => $part, 'relative' => $prefix];
        }

        $folders = collect($this->entries)->where('directory', true)->count();

        return view('livewire.websites.files', [
            'crumbs' => $crumbs,
            'folderCount' => $folders,
            'fileCount' => count($this->entries) - $folders,
            'canZip' => FileOperations::canZip(),
            'tasks' => $tasks = PanelTask::query()->where('scope', $this->taskScope())->where('updated_at', '>', now()->subHours(6))->latest('id')->limit(5)->get(),
            'tasksActive' => $tasks->contains(fn (PanelTask $task) => $task->isActive()),
            'trashCount' => TrashItem::query()->where('scope', $this->taskScope())->count(),
            'trashGroups' => $this->showTrash ? $this->trashGroups() : collect(),
            'downloadBase' => $this->website
                ? route('websites.files.download', $this->website, false)
                : route('files.download', [], false),
            'configuredRoot' => $this->website ? (string) $this->website->deploy_path : (string) (config('laravelify.files_root') ?: config('laravelify.sites_root')),
        ]);
    }

    /**
     * Laravel ignores .env while bootstrap/cache/config.php exists, so editing .env
     * would silently have no effect. Remove that cache when a Laravel .env is saved.
     */
    private function clearConfigCache(string $savedFile): bool
    {
        if (basename($savedFile) !== '.env') {
            return false;
        }

        $cached = dirname($savedFile).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';

        return is_file($cached) && ! is_link($cached) && @unlink($cached);
    }

    /**
     * Trashed items grouped by the path they were deleted from, newest first; every delete of
     * the same path is one version.
     *
     * @return Collection<int, array{path: string, name: string, dir: bool, versions: Collection<int, TrashItem>}>
     */
    private function trashGroups(): Collection
    {
        return TrashItem::query()->where('scope', $this->taskScope())->latest('id')->get()
            ->groupBy('original_path')
            ->map(fn ($versions, string $path) => [
                'path' => $path,
                'name' => $versions->first()->name,
                'dir' => $versions->first()->is_dir,
                'versions' => $versions->values(),
            ])
            ->values();
    }

    /**
     * Start a background task for this file manager and say so.
     *
     * @param  array<string, mixed>  $payload
     */
    private function startTask(string $kind, string $label, array $payload, ?string $toast = null): void
    {
        $task = app(PanelTaskRunner::class)->start($kind, $this->taskScope(), $label, $payload);

        if ($task->isFinished()) {
            // The sync queue (tests, or a server without a worker) already ran it.
            $this->pollTasks();

            return;
        }

        $this->toast($toast ?? $label.'. This runs in the background; keep working.');
    }

    private function taskScope(): string
    {
        return $this->website ? 'files:website:'.$this->website->id : 'files:global';
    }

    private function operations(): FileOperations
    {
        return app(FileOperations::class);
    }

    /**
     * "app.css" for one entry, "3 items" for more.
     *
     * @param  list<array<string, mixed>>  $selected
     */
    private function describe(array $selected): string
    {
        return count($selected) === 1 ? (string) $selected[0]['name'] : count($selected).' items';
    }

    /**
     * The folder every path is relative to: the site's deploy folder, or the global root.
     */
    private function rootPath(): string
    {
        $paths = app(SitePaths::class);

        return $this->website ? $paths->root($this->website) : $paths->globalRoot();
    }

    /**
     * Entries of the current listing that match the given paths.
     *
     * @param  array<int, mixed>  $relatives
     * @return list<array<string, mixed>>
     */
    private function selectedEntries(array $relatives): array
    {
        $wanted = array_map('strval', $relatives);

        return array_values(array_filter($this->entries, fn (array $entry): bool => in_array($entry['relative'], $wanted, true)));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            if ($a['directory'] !== $b['directory']) {
                return $a['directory'] ? -1 : 1;
            }

            $result = match ($this->sortBy) {
                'size' => $a['bytes'] <=> $b['bytes'],
                'modified' => $a['timestamp'] <=> $b['timestamp'],
                default => 0,
            };

            if ($result === 0) {
                $result = strnatcasecmp($a['name'], $b['name']);
            }

            return $this->sortDescending ? -$result : $result;
        });

        return array_values($rows);
    }

    private function newEntryPath(string $name, SitePaths $paths): string
    {
        $name = $this->safeName($name);
        $dir = $paths->resolveIn($this->rootPath(), $this->directory);

        if (is_link($dir) || ! is_dir($dir)) {
            throw new RuntimeException('That folder does not exist.');
        }

        $target = $dir.DIRECTORY_SEPARATOR.$name;

        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('That name already exists.');
        }

        return $target;
    }

    private function writeUpload(string $dir, TemporaryUploadedFile $upload): void
    {
        $name = $this->safeName(basename(str_replace('\\', '/', $upload->getClientOriginalName())));
        $source = $upload->getRealPath();

        if ($source === false || ! is_file($source)) {
            throw new RuntimeException('The upload did not arrive.');
        }

        $target = $dir.DIRECTORY_SEPARATOR.$name;

        if (is_link($target)) {
            if (! unlink($target)) {
                throw new RuntimeException('Could not replace that link.');
            }
        } elseif (is_dir($target)) {
            throw new RuntimeException('A folder with that name already exists.');
        }

        if (! copy($source, $target)) {
            throw new RuntimeException('Could not write that file.');
        }

        @chmod($target, 0644);
    }

    private function safeName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new RuntimeException('That name is not allowed.');
        }

        return $name;
    }

    private function kind(bool $isDir, string $name): string
    {
        if ($isDir) {
            return 'folder';
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, self::IMAGE_EXTENSIONS, true) => 'image',
            in_array($ext, ['zip', 'gz', 'tar', 'tgz', 'rar', '7z', 'bz2'], true) => 'archive',
            in_array($ext, ['php', 'js', 'ts', 'jsx', 'tsx', 'mjs', 'cjs', 'css', 'scss', 'vue', 'json', 'html', 'xml', 'yml', 'yaml', 'lock', 'sh', 'sql', 'py'], true) => 'code',
            in_array($ext, ['md', 'txt', 'log', 'env', 'ini', 'conf'], true), str_starts_with($name, '.') => 'text',
            default => 'file',
        };
    }

    private function sizeLabel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 1).' GB';
    }
}
