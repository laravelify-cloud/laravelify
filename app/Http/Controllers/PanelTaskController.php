<?php

namespace App\Http\Controllers;

use App\Models\PanelTask;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PanelTaskController extends Controller
{
    /**
     * The file a finished background task produced: a zipped folder or a backup export.
     */
    public function download(PanelTask $panelTask): BinaryFileResponse
    {
        $file = $panelTask->status === PanelTask::SUCCESS ? $panelTask->resultFile() : null;

        abort_if($file === null, 404);

        return response()->download($file, $panelTask->downloadName(), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }
}
