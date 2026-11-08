<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\AccessGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asked by nginx (auth_request) before every phpMyAdmin request: 204 lets it through,
 * 401 shows the browser prompt, 403 means the IP is blocked for a while.
 */
class AccessGateController extends Controller
{
    public function __invoke(Request $request, AccessGate $gate): Response
    {
        return $gate->check($request, 'phpmyadmin') ?? response('', 204);
    }

    /** The same before a website whose basic auth asks for the authenticator code. */
    public function site(Request $request, AccessGate $gate, int $website): Response
    {
        // This route has no web middleware, so the website is looked up here (by id), not bound.
        $site = Website::query()->find($website);

        // An unknown website is refused: nginx only asks here for a site that turned this on.
        return $site === null ? response('', 403) : ($gate->checkSite($request, $site) ?? response('', 204));
    }
}
