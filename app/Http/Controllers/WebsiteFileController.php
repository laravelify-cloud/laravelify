<?php

namespace App\Http\Controllers;

use App\Livewire\Websites\Files;
use App\Models\Website;
use App\Services\SitePaths;
use Illuminate\Http\Request;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

class WebsiteFileController extends Controller
{
    /**
     * Download a file, preview an image inline, or download a folder as a zip.
     */
    public function __invoke(Request $request, Website $website, SitePaths $paths): BinaryFileResponse
    {
        try {
            $root = $paths->root($website);
        } catch (Throwable) {
            abort(404);
        }

        return $this->serve($request, $paths, $root, true);
    }

    /**
     * The same, for the global file manager root. The root itself is never zipped.
     */
    public function global(Request $request, SitePaths $paths): BinaryFileResponse
    {
        try {
            $root = $paths->globalRoot();
        } catch (Throwable) {
            abort(404);
        }

        return $this->serve($request, $paths, $root, false);
    }

    private function serve(Request $request, SitePaths $paths, string $root, bool $zipRoot): BinaryFileResponse
    {
        $relative = (string) $request->query('path', '');

        try {
            $full = $paths->locateIn($root, $relative);
        } catch (Throwable) {
            abort(404);
        }

        if (is_link($full) || ! is_readable($full)) {
            abort(404);
        }

        if (is_dir($full)) {
            abort_if(! $zipRoot && $full === $root, 404);

            return $this->zip($full, $root);
        }

        if (! is_file($full)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($full, PATHINFO_EXTENSION));

        if ($request->boolean('inline') && in_array($extension, Files::IMAGE_EXTENSIONS, true)) {
            return response()->file($full, [
                'Content-Type' => $this->imageType($extension),
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        }

        return response()->download($full, basename($full), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function zip(string $directory, string $root): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 404);

        $archive = tempnam(sys_get_temp_dir(), 'laravelify-zip-');
        $zip = new ZipArchive;

        if ($archive === false || $zip->open($archive, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the archive.');
        }

        $base = $directory === $root ? basename($root) : basename($directory);
        $zip->addEmptyDir($base);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            $path = $item->getPathname();

            if (is_link($path)) {
                continue;
            }

            $name = $base.'/'.str_replace('\\', '/', substr($path, strlen($directory) + 1));

            if ($item->isDir()) {
                $zip->addEmptyDir($name);
            } elseif ($item->isFile() && $item->isReadable()) {
                $zip->addFile($path, $name);
            }
        }

        $zip->close();

        return response()->download($archive, $base.'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend();
    }

    private function imageType(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'image/'.$extension,
        };
    }
}
