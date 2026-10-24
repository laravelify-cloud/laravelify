<?php

namespace App\Http\Controllers;

use App\Models\CodeWorkspace;
use App\Services\CodeWorkspaces;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * A workspace's projects as one zip: their code from VS Code's clones, without installed packages,
 * builds, and .env files. The zip is made for this download and deleted once it is sent.
 */
class CodeWorkspaceDownloadController extends Controller
{
    public function __invoke(CodeWorkspace $workspace, CodeWorkspaces $workspaces): BinaryFileResponse
    {
        try {
            $path = $workspaces->zip($workspace);
        } catch (Throwable $exception) {
            abort(503, 'Could not make the zip: '.$exception->getMessage());
        }

        return response()->download($path, Str::slug($workspace->name).'.zip')->deleteFileAfterSend();
    }
}
