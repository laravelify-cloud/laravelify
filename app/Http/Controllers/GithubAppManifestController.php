<?php

namespace App\Http\Controllers;

use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GithubAppManifestController extends Controller
{
    public function callback(Request $request, GithubApp $githubApp): RedirectResponse
    {
        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect()->route('sources.github.show', $githubApp)
                ->with('status', 'GitHub did not return an app code.');
        }

        $response = Http::accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withBody(null)
            ->post(rtrim($githubApp->api_url, '/').'/app-manifests/'.$code.'/conversions');

        if (! $response->successful()) {
            return redirect()->route('sources.github.show', $githubApp)
                ->with('status', 'GitHub could not finish the app: '.$response->json('message', 'request failed'));
        }

        $pem = PrivateKey::normalize((string) $response->json('pem'));
        $key = PrivateKey::query()->updateOrCreate(
            ['name' => 'github-app-'.$githubApp->slug],
            [
                'description' => 'GitHub App '.($response->json('name') ?: $githubApp->name),
                'private_key' => $pem,
                'is_git_related' => true,
            ],
        );

        $githubApp->fill([
            'name' => $response->json('name') ?: $githubApp->name,
            'app_id' => (string) $response->json('id'),
            'client_id' => $response->json('client_id'),
            'client_secret' => $response->json('client_secret'),
            'webhook_secret' => $response->json('webhook_secret'),
            'private_key_id' => $key->id,
            'private_key' => null,
        ]);
        $githubApp->save();

        $installUrl = rtrim((string) $response->json('html_url'), '/').'/installations/new';

        return redirect()->away($installUrl);
    }

    public function install(Request $request, GithubApp $githubApp): RedirectResponse
    {
        $installationId = $request->query('installation_id');

        if (is_scalar($installationId) && (string) $installationId !== '') {
            $githubApp->installation_id = (string) $installationId;
            $githubApp->save();
        }

        return redirect()->route('sources.github.show', $githubApp)
            ->with('status', $githubApp->isConnected() ? 'GitHub App registered.' : 'GitHub App created. Install it to finish.');
    }
}
