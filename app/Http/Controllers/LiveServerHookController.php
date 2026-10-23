<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\LiveServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * VS Code's Live Server asks here (from inside the website's editor, with the key in its home)
 * to put its dev server on a public address, or to take it away.
 */
class LiveServerHookController extends Controller
{
    public function __invoke(Request $request, Website $website, string $action, LiveServer $live): JsonResponse
    {
        if (! $live->validKey($website, $request->bearerToken())) {
            abort(401);
        }

        try {
            if ($action === 'stop') {
                $live->stop($website);

                return response()->json(['ok' => true]);
            }

            return response()->json(['ok' => true, 'url' => $live->start($website, (int) $request->input('port'))]);
        } catch (Throwable $exception) {
            return response()->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
    }
}
