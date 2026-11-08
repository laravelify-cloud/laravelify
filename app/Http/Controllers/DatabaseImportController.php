<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBrowser;
use App\Services\PanelTaskRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Upload a .sql or .sql.gz dump in pieces (8 MB each, so files of many GB pass any upload limit),
 * then import it into a database in the background (SqlImporter). Behind the panel's sign-in.
 */
class DatabaseImportController extends Controller
{
    /** The largest dump: 20 GB. */
    public const MAX_BYTES = 20 * 1024 * 1024 * 1024;

    public const PIECE_BYTES = 8 * 1024 * 1024;

    public function chunk(Request $request, string $database): JsonResponse
    {
        abort_unless(DatabaseBrowser::validDatabase($database), 404);
        $data = $request->validate([
            'upload' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32}$/'],
            'name' => ['required', 'string', 'max:200', 'regex:/\.(sql|sql\.gz|gz)$/i'],
            'total' => ['required', 'integer', 'min:1', 'max:'.self::MAX_BYTES],
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:'.(int) (self::PIECE_BYTES / 1024 + 1024)],
        ], ['name.regex' => 'Choose a .sql or .sql.gz file.']);

        $path = $this->partPath($data['upload']);
        $have = is_file($path) ? (int) filesize($path) : 0;

        if ($have === 0 && (int) $data['offset'] === 0 && disk_free_space(dirname($path)) < (int) $data['total'] * 1.1) {
            return response()->json(['message' => 'Not enough free disk on the server for this file.'], 507);
        }

        // A piece sent again (after a dropped connection) is skipped; one out of order is refused.
        if ((int) $data['offset'] < $have) {
            return response()->json(['received' => $have]);
        }

        if ((int) $data['offset'] > $have) {
            return response()->json(['message' => 'A piece is missing. Upload the file again.', 'received' => $have], 409);
        }

        $in = fopen($request->file('chunk')->getRealPath(), 'rb');
        $out = fopen($path, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        clearstatcache(true, $path);

        if (filesize($path) > (int) $data['total']) {
            @unlink($path);

            return response()->json(['message' => 'The file came out larger than it should. Upload it again.'], 422);
        }

        return response()->json(['received' => filesize($path)]);
    }

    public function start(Request $request, string $database, PanelTaskRunner $tasks): JsonResponse
    {
        abort_unless(DatabaseBrowser::validDatabase($database), 404);
        $data = $request->validate([
            'upload' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32}$/'],
            'name' => ['required', 'string', 'max:200'],
            'total' => ['required', 'integer', 'min:1'],
            'reset' => ['boolean'],
        ]);

        $path = $this->partPath($data['upload']);

        if (! is_file($path) || filesize($path) !== (int) $data['total']) {
            return response()->json(['message' => 'The upload is not complete. Upload the file again.'], 422);
        }

        if ($running = PanelTaskRunner::running('database.sql-import', 'db-import:'.$database)) {
            return response()->json(['message' => 'An import into '.$database.' is already running.', 'task' => $running->id], 409);
        }

        $ready = dirname($path).'/'.$data['upload'].'.dump';
        rename($path, $ready);

        $task = $tasks->start('database.sql-import', 'db-import:'.$database, 'Importing '.basename($data['name']).' into '.$database, [
            'database' => $database,
            'path' => $ready,
            'name' => basename($data['name']),
            'reset' => (bool) ($data['reset'] ?? false),
        ]);

        return response()->json(['task' => $task->id]);
    }

    private function partPath(string $upload): string
    {
        $directory = storage_path('app/db-imports');
        File::ensureDirectoryExists($directory, 0700);

        return $directory.'/'.$upload.'.part';
    }
}
