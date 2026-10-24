<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\CodeServer;
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
            // A sign-in or sign-out in this editor: every website gets it now, not within a minute.
            if ($action === 'auth') {
                app(CodeServer::class)->shareAgentSignIns();

                return response()->json(['ok' => true]);
            }

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
