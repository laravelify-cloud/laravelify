<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Website;

/**
 * Telegram messages for deployment events, in Coolify's style: a one-line
 * headline, a short summary, and link buttons under the message.
 */
class DeploymentNotifier
{
    public function __construct(private Telegram $telegram) {}

    public function started(Deployment $deployment): ?string
    {
        return $this->telegram->notify('deploy_started', $this->message('🚀', 'Deployment started', $deployment), $this->buttons($deployment));
    }

    public function succeeded(Deployment $deployment): ?string
    {
        return $this->telegram->notify('deploy_succeeded', $this->message('✅', 'Deployment succeeded', $deployment), $this->buttons($deployment, true));
    }

    public function failed(Deployment $deployment, string $error): ?string
    {
        $tail = collect(preg_split('/\R/', trim((string) $deployment->log)) ?: [])
            ->filter()
            ->take(-8)
            ->implode("\n");

        $body = $this->message('❌', 'Deployment failed', $deployment)
            ."\n\n⚠️ <b>Error</b>\n".Telegram::escape($error)
            .($tail !== '' ? "\n\n<pre>".Telegram::escape(mb_substr($tail, -1500)).'</pre>' : '');

        return $this->telegram->notify('deploy_failed', $body, $this->buttons($deployment));
    }

    public function cancelled(Deployment $deployment): ?string
    {
        return $this->telegram->notify('deploy_cancelled', $this->message('⏹', 'Deployment stopped', $deployment), $this->buttons($deployment));
    }

    public function webhookIgnored(Website $website, string $reason): ?string
    {
        return $this->telegram->notify(
            'webhook_ignored',
            '⏭ Laravelify: Push skipped for <b>'.Telegram::escape($website->name).'</b>'."\n\n".Telegram::escape(ucfirst($reason)),
            [['text' => 'Deployments', 'url' => route('websites.show', $website).'?tab=deployments']],
        );
    }

    private function message(string $icon, string $title, Deployment $deployment): string
    {
        $website = $deployment->website;
        $name = Telegram::escape($website?->name ?? 'Removed website');
        $url = $website?->publicUrl();
        $headline = $icon.' Laravelify: '.$title.' of <b>'.$name.'</b>'.($url ? ' ('.Telegram::escape($url).')' : '');

        $summary = array_filter([
            $deployment->branch ? '• Branch: <code>'.Telegram::escape($deployment->branch).'</code>' : null,
            $deployment->commit_sha ? '• Commit: <code>'.Telegram::escape(substr($deployment->commit_sha, 0, 7)).'</code>' : null,
            $deployment->commit_message
                ? '• Message: '.Telegram::escape(mb_strimwidth(strtok($deployment->commit_message, "\n") ?: '', 0, 120, '…'))
                : null,
            $deployment->commit_author ? '• Author: '.Telegram::escape($deployment->commit_author) : null,
            '• Trigger: '.Telegram::escape(ucfirst($deployment->trigger)),
            in_array($deployment->status, ['success', 'failed', 'cancelled'], true) && $deployment->durationLabel() ? '• Took: '.$deployment->durationLabel() : null,
        ]);

        return $headline."\n\n📊 Summary:\n".implode("\n", $summary);
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private function buttons(Deployment $deployment, bool $withSite = false): array
    {
        $website = $deployment->website;

        if (! $website) {
            return [];
        }

        $buttons = [['text' => 'Deployment logs', 'url' => route('websites.show', $website).'?tab=deployments']];

        if ($withSite && $website->publicUrl()) {
            $buttons[] = ['text' => 'Open website', 'url' => $website->publicUrl()];
        }

        return $buttons;
    }
}
