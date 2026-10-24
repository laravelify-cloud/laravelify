<?php

namespace App\Http\Controllers;

use App\Models\CodeWorkspace;
use App\Models\Website;
use App\Services\CodeWorkspaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * A workspace's share link: anyone who has it opens the workspace's VS Code, without a panel
 * account, for as long as the link is turned on. The link is kept in a cookie, which the editor's
 * gate (CodeEditorGateController) checks on every request. Neither route needs the panel's login
 * or its access prompt.
 */
class CodeWorkspaceShareController extends Controller
{
    public function open(string $share, CodeWorkspaces $workspaces): Response|RedirectResponse
    {
        $workspace = CodeWorkspace::query()->with('websites.githubApp')->where('share_token', $share)->first();

        if ($workspace === null) {
            return $this->page('This link is turned off', 'Ask the person who shared it for a new link.', 404);
        }

        try {
            $result = $workspaces->start($workspace, 'Shared link');
        } catch (Throwable $exception) {
            return $this->page('VS Code could not start', $exception->getMessage(), 503);
        }

        // code-server is still being installed on this server (the first time only).
        if ($result['url'] === null) {
            return $this->page('Starting VS Code…', 'VS Code is being installed on the server. This page tries again by itself.', 202, refresh: 5);
        }

        return redirect()->away($result['url'])->withCookie(cookie(
            CodeEditorGateController::SHARE_COOKIE, $share, 60 * 24 * 30, '/', null, null, true, false, 'lax',
        ));
    }

    /**
     * nginx sends an editor that stopped by itself (30 minutes idle) here: its launcher starts it
     * again, for a signed-in panel user, or through the share link for someone who came that way.
     */
    public function restart(Request $request, string $token, CodeWorkspaces $workspaces): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && $user->hasConfirmedTwoFactor()) {
            if ($website = Website::query()->where('code_server_token', $token)->first()) {
                return redirect()->route('websites.vscode', $website);
            }

            if ($workspace = CodeWorkspace::query()->where('code_server_token', $token)->first()) {
                return redirect()->route('workspaces.open', $workspace);
            }

            abort(404);
        }

        $shared = $workspaces->sharedEditor($request->cookie(CodeEditorGateController::SHARE_COOKIE), $token);

        return $shared
            ? redirect()->route('workspaces.shared', $shared->share_token)
            : redirect()->route('login');
    }

    private function page(string $title, string $message, int $status, ?int $refresh = null): Response
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .($refresh ? '<meta http-equiv="refresh" content="'.$refresh.'">' : '')
            .'<title>'.e($title).'</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#1f1f1f;color:#ccc;font:15px/1.5 system-ui,sans-serif}'
            .'main{max-width:420px;padding:24px;text-align:center}h1{font-size:20px;color:#fff;margin:0 0 8px}</style></head>'
            .'<body><main><h1>'.e($title).'</h1><p>'.e($message).'</p></main></body></html>';

        return response($html, $status);
    }
}
