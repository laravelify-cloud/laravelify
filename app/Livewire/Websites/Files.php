<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\DisplayTime;
use App\Services\SitePaths;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;
use ZipArchive;

class Files extends PanelComponent
{
    use WithFileUploads;

    /**
     * Largest file the editor will load, in bytes. Livewire caps a request at 1 MB
     * and the content travels both in the snapshot and as the update.
     */
    public const int EDITABLE_LIMIT = 256 * 1024;

    /**
     * Largest total size an archive may unpack to, in bytes.
     */
    public const int EXTRACT_LIMIT = 2 * 1024 * 1024 * 1024;

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
            'uploads.*' => ['file', 'max:32768'],
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

    public function deleteEntry(string $relative, SitePaths $paths): void
    {
        $this->deleteEntries([$relative], $paths);
    }

    /**
     * Delete entries of the current folder. One entry is confirmed by its name,
     * several by typing "delete".
     *
     * @param  list<string>  $relatives
     */
    public function deleteEntries(array $relatives, SitePaths $paths): void
    {
        $selected = $this->selectedEntries($relatives);

        if ($selected === []) {
            $this->toast('Those files are no longer there.', 'error');

            return;
        }

        if (! $this->confirmed(count($selected) === 1 ? $selected[0]['name'] : 'delete')) {
            return;
        }

        try {
            $root = $this->rootPath();

            foreach ($selected as $entry) {
                $full = $paths->locateIn($this->rootPath(), $entry['relative']);

                if ($full === $root) {
                    throw new RuntimeException('The site folder cannot be deleted.');
                }

                $this->remove($full, $root, $paths);

                if ($this->openPath === $entry['relative']) {
                    $this->closeFile();
                }
            }

            $this->refreshList();
            $this->dispatch('entry-deleted');
            $this->toast(count($selected) === 1 ? $selected[0]['name'].' deleted.' : count($selected).' items deleted.');
        } catch (Throwable $e) {
            $this->refreshList();
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function duplicateEntry(string $relative, SitePaths $paths): void
    {
        try {
            $entry = $this->selectedEntries([$relative])[0] ?? throw new RuntimeException('That file is no longer there.');
            $source = $paths->locateIn($this->rootPath(), $entry['relative']);

            if (is_link($source)) {
                throw new RuntimeException('Links cannot be duplicated.');
            }

            $target = $this->uniquePath(dirname($source), $entry['name'], ! $entry['directory']);
            $this->copyRecursive($source, $target);
            $this->refreshList();
            $this->toast('Created '.basename($target).'.');
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

            foreach ($selected as $entry) {
                $source = $paths->locateIn($this->rootPath(), $entry['relative']);

                if ($source === $root) {
                    throw new RuntimeException('The site folder cannot be '.($mode === 'move' ? 'moved' : 'copied').'.');
                }

                if (! is_link($source) && is_dir($source) && $paths->inside($source, $destinationPath)) {
                    throw new RuntimeException($entry['name'].' cannot go inside itself.');
                }

                $target = $destinationPath.DIRECTORY_SEPARATOR.$entry['name'];

                if ($mode === 'move') {
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
                } else {
                    if (is_link($source)) {
                        throw new RuntimeException('Links cannot be copied.');
                    }

                    if (file_exists($target) || is_link($target)) {
                        $target = $this->uniquePath($destinationPath, $entry['name'], ! $entry['directory']);
                    }

                    $this->copyRecursive($source, $target);
                }

                $done++;
            }

            $where = $paths->relative($root, $destinationPath);
            $this->refreshList();
            $this->dispatch('entries-transferred');
            $this->toast(($done === 1 ? '1 item' : $done.' items').' '.($mode === 'move' ? 'moved' : 'copied').' to /'.$where.'.');
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
     * Zip entries of the current folder into an archive next to them.
     *
     * @param  list<string>  $relatives
     */
    public function compressEntries(array $relatives, SitePaths $paths): void
    {
        try {
            if (! class_exists(ZipArchive::class)) {
                throw new RuntimeException('The PHP zip extension is not installed.');
            }

            $selected = $this->selectedEntries($relatives);

            if ($selected === []) {
                throw new RuntimeException('Choose something to compress.');
            }

            $dir = $paths->resolveIn($this->rootPath(), $this->directory);
            $name = count($selected) === 1 ? $selected[0]['name'].'.zip' : 'archive.zip';
            $target = $this->uniquePath($dir, $name, true);
            $zip = new ZipArchive;

            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Could not create the archive.');
            }

            foreach ($selected as $entry) {
                $full = $paths->locateIn($this->rootPath(), $entry['relative']);

                if (is_link($full)) {
                    continue;
                }

                is_dir($full) ? $this->zipDirectory($zip, $full, $entry['name']) : $zip->addFile($full, $entry['name']);
            }

            if ($zip->numFiles === 0) {
                $zip->addEmptyDir(pathinfo($name, PATHINFO_FILENAME));
            }

            $zip->close();
            @chmod($target, 0644);
            $this->refreshList();
            $this->toast('Created '.basename($target).'.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
        }
    }

    public function extractEntry(string $relative, SitePaths $paths): void
    {
        try {
            if (! class_exists(ZipArchive::class)) {
                throw new RuntimeException('The PHP zip extension is not installed.');
            }

            $entry = $this->selectedEntries([$relative])[0] ?? throw new RuntimeException('That file is no longer there.');
            $full = $paths->locateIn($this->rootPath(), $entry['relative']);

            if (is_link($full) || ! is_file($full) || $entry['extension'] !== 'zip') {
                throw new RuntimeException('Only .zip archives can be extracted.');
            }

            $zip = new ZipArchive;

            if ($zip->open($full, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('That archive cannot be read.');
            }

            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                $stat = $zip->statIndex($i);
                $total += is_array($stat) ? (int) $stat['size'] : 0;

                if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0") || preg_match('/^[A-Za-z]:/', $name) === 1 || in_array('..', explode('/', $name), true)) {
                    $zip->close();

                    throw new RuntimeException('That archive contains unsafe paths and was not extracted.');
                }
            }

            if ($total > self::EXTRACT_LIMIT) {
                $zip->close();

                throw new RuntimeException('That archive is larger than 2 GB when extracted.');
            }

            $target = $this->uniquePath(dirname($full), pathinfo($entry['name'], PATHINFO_FILENAME), false);

            if (! mkdir($target, 0755) || ! $zip->extractTo($target)) {
                $zip->close();

                throw new RuntimeException('Could not extract that archive.');
            }

            $zip->close();
            $this->refreshList();
            $this->toast('Extracted to '.basename($target).'.');
        } catch (Throwable $e) {
            $this->toast($e->getMessage(), 'error');
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
            'canZip' => class_exists(ZipArchive::class),
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
     * A free path in the folder, adding " copy", " copy 2"... before the extension.
     */
    private function uniquePath(string $dir, string $name, bool $keepExtension): string
    {
        $candidate = $dir.DIRECTORY_SEPARATOR.$name;

        if (! file_exists($candidate) && ! is_link($candidate)) {
            return $candidate;
        }

        $extension = $keepExtension ? pathinfo($name, PATHINFO_EXTENSION) : '';
        $base = $extension !== '' ? substr($name, 0, -strlen($extension) - 1) : $name;
        $suffix = $extension !== '' ? '.'.$extension : '';

        for ($i = 1; $i < 1000; $i++) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$base.($i === 1 ? ' copy' : ' copy '.$i).$suffix;

            if (! file_exists($candidate) && ! is_link($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not find a free name.');
    }

    private function copyRecursive(string $source, string $target): void
    {
        if (is_link($source)) {
            return;
        }

        if (is_file($source)) {
            if (! copy($source, $target)) {
                throw new RuntimeException('Could not copy '.basename($source).'.');
            }

            @chmod($target, @fileperms($source) & 0777);

            return;
        }

        if (! mkdir($target, @fileperms($source) & 0777 ?: 0755)) {
            throw new RuntimeException('Could not create '.basename($target).'.');
        }

        foreach (scandir($source) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->copyRecursive($source.DIRECTORY_SEPARATOR.$name, $target.DIRECTORY_SEPARATOR.$name);
            }
        }
    }

    private function zipDirectory(ZipArchive $zip, string $directory, string $base): void
    {
        $zip->addEmptyDir($base);

        foreach (scandir($directory) ?: [] as $name) {
            $full = $directory.DIRECTORY_SEPARATOR.$name;

            if ($name === '.' || $name === '..' || is_link($full)) {
                continue;
            }

            is_dir($full) ? $this->zipDirectory($zip, $full, $base.'/'.$name) : $zip->addFile($full, $base.'/'.$name);
        }
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

    private function remove(string $full, string $root, SitePaths $paths): void
    {
        if (is_link($full)) {
            $parent = realpath(dirname($full));

            if ($parent === false || ! $paths->inside($root, $parent)) {
                throw new RuntimeException('That path is outside the site folder.');
            }

            if (! unlink($full)) {
                throw new RuntimeException('Could not delete that link.');
            }

            return;
        }

        $resolved = realpath($full);

        if ($resolved === false || ! $paths->inside($root, $resolved) || $resolved === $root) {
            throw new RuntimeException('That path is outside the site folder.');
        }

        if (is_file($resolved)) {
            if (! unlink($resolved)) {
                throw new RuntimeException('Could not delete that file.');
            }

            return;
        }

        if (! is_dir($resolved)) {
            throw new RuntimeException('That path cannot be deleted.');
        }

        $items = scandir($resolved);

        if ($items === false) {
            throw new RuntimeException('Could not read that folder.');
        }

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $this->remove($resolved.DIRECTORY_SEPARATOR.$name, $root, $paths);
        }

        if (! rmdir($resolved)) {
            throw new RuntimeException('Could not delete that folder.');
        }
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
