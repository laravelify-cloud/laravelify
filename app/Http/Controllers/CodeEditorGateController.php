<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Asked by nginx (auth_request) before every request to a VS Code editor at /code/<token>/:
 * 204 lets it through when a signed-in panel user (with two-factor confirmed) asks for an
 * editor that exists, 401 sends the visitor to the login page.
 */
class CodeEditorGateController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $uri = (string) $request->header('X-Original-URI');
        $user = $request->user();

        $allowed = $user !== null
            && $user->hasConfirmedTwoFactor()
            && preg_match('#^/code/([a-z0-9]{32})/#', $uri, $match) === 1
            && Website::query()->where('code_server_token', $match[1])->exists();

        return response('', $allowed ? 204 : 401);
    }
}
