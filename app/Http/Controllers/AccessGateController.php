<?php

namespace App\Http\Controllers;

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
}
