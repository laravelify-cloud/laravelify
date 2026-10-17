<?php

namespace App\Http\Controllers;

use App\Services\TerminalSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminalSessionController extends Controller
{
    public function output(Request $request, string $session, TerminalSessions $sessions): JsonResponse
    {
        $result = $sessions->read($session, (int) $request->query('offset', 0), auth()->id());

        abort_if($result === null, 404);

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function input(Request $request, string $session, TerminalSessions $sessions): JsonResponse
    {
        // Keystrokes arrive base64-encoded: Laravel trims and empties plain strings, which would
        // drop Enter and spaces.
        $encoded = $request->validate(['data' => ['required', 'string', 'max:100000']])['data'];
        $data = base64_decode($encoded, true);

        abort_if($data === false || $data === '', 422);
        abort_unless($sessions->write($session, $data, auth()->id()), 404);

        return response()->json(['ok' => true]);
    }

    public function stop(string $session, TerminalSessions $sessions): JsonResponse
    {
        abort_unless($sessions->stop($session, auth()->id()), 404);

        return response()->json(['ok' => true]);
    }
}
