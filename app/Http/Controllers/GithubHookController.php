<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\GithubWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubHookController extends Controller
{
    public function __invoke(Request $request, Website $website, GithubWebhook $webhook): JsonResponse
    {
        if (! $webhook->valid($website->webhook_secret, $request->getContent(), $request->header('X-Hub-Signature-256'))) {
            $website->recordWebhook('Rejected a delivery with a wrong signature. Check the webhook secret.');

            abort(401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        return response()->json($webhook->handle($website, $payload, $request->header('X-GitHub-Event')));
    }
}
