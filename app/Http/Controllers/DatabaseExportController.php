<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBrowser;
use App\Services\DatabaseDumper;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

/**
 * A plain .sql download. The file is written under the panel's temp storage and removed after it is sent.
 */
class DatabaseExportController extends Controller
{
    public function __invoke(Request $request, DatabaseBrowser $browser, DatabaseDumper $dumper): BinaryFileResponse|RedirectResponse
    {
        $database = (string) $request->input('database');

        if (! DatabaseBrowser::validDatabase($database)) {
            abort(404);
        }

        $contents = (string) $request->input('contents', 'both');

        if (! in_array($contents, ['both', 'structure', 'data'], true)) {
            return back()->with('export_error', 'Choose structure, data, or both.');
        }

        try {
            $known = array_column($browser->tables($database), 'name');
        } catch (Throwable $exception) {
            return back()->with('export_error', $exception->getMessage());
        }

        $chosen = array_values(array_filter(
            (array) $request->input('tables', []),
            fn ($name): bool => is_string($name) && in_array($name, $known, true),
        ));

        if ($request->boolean('single')) {
            if (count($chosen) !== 1) {
                return back()->with('export_error', 'Choose one table.');
            }

            $tables = $chosen;
            $routines = false;
        } elseif ($known !== [] && $chosen === []) {
            return back()->with('export_error', 'Choose at least one table.');
        } else {
            $whole = $known === [] || (count($chosen) === count($known) && array_diff($known, $chosen) === []);
            $tables = $whole ? [] : $chosen;
            $routines = $request->boolean('routines') && $whole;
        }

        $dir = storage_path('app/tmp');
        $file = $dir.'/export-'.bin2hex(random_bytes(8)).'.sql';
        $name = $database.(count($tables) === 1 ? '-'.$tables[0] : '').'-'.now()->format('Y-m-d').'.sql';

        try {
            $dumper->exportSql($database, $file, $tables, $contents, $routines);
        } catch (Throwable $exception) {
            @unlink($file);

            return back()->with('export_error', $exception->getMessage());
        }

        return response()->download($file, $name, [
            'Content-Type' => 'application/sql; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }
}
