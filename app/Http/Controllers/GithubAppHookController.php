<?php

namespace App\Http\Controllers;

use App\Models\GithubApp;
use App\Models\Website;
use App\Services\GithubWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubAppHookController extends Controller
{
    public function __invoke(Request $request, GithubApp $githubApp, GithubWebhook $webhook): JsonResponse
    {
        if (! $githubApp->webhook_secret || ! $webhook->valid($githubApp->webhook_secret, $request->getContent(), $request->header('X-Hub-Signature-256'))) {
            abort(401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $fullName = strtolower((string) data_get($payload, 'repository.full_name'));
        $queued = [];

        $event = $request->header('X-GitHub-Event');

        // The App also sends installation, pull request, and other events. Only pushes deploy,
        // and the others must not replace each website's last deploy status.
        if ($event !== 'push') {
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'only push events deploy']);
        }

        foreach ($githubApp->websites as $website) {
            if ($fullName === '' || ! $this->repositoryMatches($website, $fullName)) {
                continue;
            }

            $result = $webhook->handle($website, $payload, $event);

            if (isset($result['deployment_id'])) {
                $queued[] = $result['deployment_id'];
            }
        }

        return response()->json([
            'ok' => true,
            'ignored' => $queued === [],
            'deployment_ids' => $queued,
        ]);
    }

    private function repositoryMatches(Website $website, string $fullName): bool
    {
        $repository = strtolower(trim((string) $website->repository));
        $repository = (string) preg_replace('#\.git$#', '', $repository);

        return str_ends_with($repository, '/'.$fullName) || $repository === $fullName;
    }
}
