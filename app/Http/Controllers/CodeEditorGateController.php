<?php

namespace App\Http\Controllers;

use App\Models\CodeWorkspace;
use App\Models\Website;
use App\Services\CodeWorkspaces;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Asked by nginx (auth_request) before every request to a VS Code editor at /code/<token>/:
 * 204 lets it through when a signed-in panel user (with two-factor confirmed) asks for an
 * editor that exists (a website's or a workspace's), or when the visitor opened a workspace's
 * share link that is still turned on (its cookie) and asks for that workspace's editor. 401 sends
 * anyone else to the login page.
 */
class CodeEditorGateController extends Controller
{
    /** The share link a visitor opened, kept for its editor's requests. */
    public const SHARE_COOKIE = 'laravelify_code_share';

    public function __invoke(Request $request, CodeWorkspaces $workspaces): Response
    {
        $uri = (string) $request->header('X-Original-URI');

        if (preg_match('#^/code/([a-z0-9]{32})/#', $uri, $match) !== 1) {
            return response('', 401);
        }

        $user = $request->user();
        $exists = Website::query()->where('code_server_token', $match[1])->exists()
            || CodeWorkspace::query()->where('code_server_token', $match[1])->exists();

        $allowed = ($user !== null && $user->hasConfirmedTwoFactor() && $exists)
            || $workspaces->sharedEditor($request->cookie(self::SHARE_COOKIE), $match[1]) !== null;

        return response('', $allowed ? 204 : 401);
    }
}
