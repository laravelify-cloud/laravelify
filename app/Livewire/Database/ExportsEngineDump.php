<?php

namespace App\Livewire\Database;

use App\Livewire\PanelComponent;
use App\Services\EngineDatabases;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The Export button for PostgreSQL, SQLite and MongoDB: the dump is written to the panel's temp
 * storage, sent, and removed.
 */
class ExportsEngineDump
{
    public static function download(PanelComponent $component, EngineDatabases $engines, string $engine, string $database): ?BinaryFileResponse
    {
        $extension = EngineDatabases::DUMP_EXTENSIONS[$engine] ?? null;

        if ($extension === null) {
            return null;
        }

        File::ensureDirectoryExists(storage_path('app/tmp'));
        $file = storage_path('app/tmp/export-'.bin2hex(random_bytes(8)).'.'.$extension);

        try {
            $engines->dump($engine, $database, $file);
        } catch (Throwable $exception) {
            $component->dispatch('toast', type: 'error', message: $exception->getMessage());

            return null;
        }

        return response()->download($file, $database.'-'.now()->format('Y-m-d').'.'.$extension)->deleteFileAfterSend();
    }
}
