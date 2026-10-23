<?php

namespace App\Services;

use App\Models\Website;

class GithubWebhook
{
    public function __construct(private DeploymentNotifier $notifier) {}

    public function valid(string $secret, string $body, ?string $header): bool
    {
        // Without a secret anyone could sign a delivery (an HMAC with an empty key).
        if (trim($secret) === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, $header);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function branchMatches(Website $website, array $payload): bool
    {
        return ($payload['ref'] ?? null) === 'refs/heads/'.$website->branch;
    }

    /**
     * Act on one delivery for one website: answer pings, skip other branches,
     * respect the auto-deploy switch, and queue a deploy with the pushed commit.
     * The outcome is stored on the website so the panel can show it.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, ignored: bool, reason?: string, deployment_id?: int}
     */
    public function handle(Website $website, array $payload, ?string $event): array
    {
        if ($event === 'ping') {
            $website->recordWebhook('Ping received. The webhook is connected.');

            return ['ok' => true, 'ignored' => true, 'reason' => 'ping'];
        }

        if ($event !== null && $event !== 'push') {
            $website->recordWebhook('Ignored a '.$event.' event.');

            return ['ok' => true, 'ignored' => true, 'reason' => 'not a push event'];
        }

        if (! $this->branchMatches($website, $payload)) {
            $branch = str_replace('refs/heads/', '', (string) ($payload['ref'] ?? 'unknown'));
            $website->recordWebhook('Ignored a push to '.$branch.'. This site deploys '.$website->branch.'.');

            return ['ok' => true, 'ignored' => true, 'reason' => 'other branch'];
        }

        if (! $website->enabled) {
            $website->recordWebhook('Push received. The website is turned off, so it was not deployed.');
            $this->notifier->webhookIgnored($website, 'push received, but the website is turned off.');

            return ['ok' => true, 'ignored' => true, 'reason' => 'website is turned off'];
        }

        if (! $website->auto_deploy) {
            $website->recordWebhook('Push received. Auto deploy is off.');
            $this->notifier->webhookIgnored($website, 'push received, but auto deploy is off.');

            return ['ok' => true, 'ignored' => true, 'reason' => 'auto deploy is off'];
        }

        $sha = is_string($payload['after'] ?? null) ? $payload['after'] : null;

        // The code editor deploys its own pushes right away, so their webhook is not needed.
        if ($sha !== null && $website->deployments()->where('trigger', 'editor')->where('commit_sha', $sha)->exists()) {
            $website->recordWebhook('Push of '.substr($sha, 0, 7).' from the code editor. Already deployed.');

            return ['ok' => true, 'ignored' => true, 'reason' => 'already deployed from the editor'];
        }

        $deployment = $website->queueDeployment('webhook', $sha, [
            'commit_message' => data_get($payload, 'head_commit.message'),
            'commit_author' => data_get($payload, 'head_commit.author.name') ?? data_get($payload, 'pusher.name'),
        ]);

        if (! $deployment) {
            $website->recordWebhook('Push received while a deploy was running. Skipped.');
            $this->notifier->webhookIgnored($website, 'push received while a deploy was running. Skipped.');

            return ['ok' => true, 'ignored' => true, 'reason' => 'deployment already running'];
        }

        $website->recordWebhook('Queued deploy #'.$deployment->id.($sha ? ' for '.substr($sha, 0, 7) : '').'.');

        return ['ok' => true, 'ignored' => false, 'deployment_id' => $deployment->id];
    }
}
