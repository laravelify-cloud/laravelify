<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Telegram Bot API client for panel notifications. Settings live encrypted in
 * the settings table: bot token, chat id, optional forum topic (thread) id.
 */
class Telegram
{
    /**
     * Every event, grouped for the settings page.
     *
     * @var array<string, array<string, string>>
     */
    public const EVENT_GROUPS = [
        'Deployments' => [
            'deploy_started' => 'Deploy started',
            'deploy_succeeded' => 'Deploy succeeded',
            'deploy_failed' => 'Deploy failed',
            'deploy_cancelled' => 'Deploy stopped',
            'webhook_ignored' => 'Push skipped (auto deploy off or a deploy already running)',
        ],
        'Websites' => [
            'site_down' => 'Website is down (two failed checks in a row)',
            'site_up' => 'Website is back up',
        ],
        'Processes' => [
            'process_crashed' => 'Queue, scheduler, or PM2 app stopped or crashing',
            'process_recovered' => 'Process running again',
        ],
        'Monitoring' => [
            'issue_new' => 'New issue, or a resolved one came back (Laravel errors)',
            'error_spike' => 'Error spike (5xx responses above the site limit)',
            'slow_site' => 'Slow responses (p95 above the site limit)',
            'threat_detected' => 'Attack detected (SQL injection, XSS, scanners)',
            'heartbeat_missed' => 'Cron job missed its heartbeat, and when it reports again',
        ],
        'Backups' => [
            'backup_failed' => 'A backup or restore failed',
            'backup_succeeded' => 'A backup finished',
        ],
        'Server' => [
            'disk_high' => 'Disk usage above the limit',
            'server_patches' => 'Server patches available (daily check)',
        ],
    ];

    public const EVENTS = [
        'deploy_started' => 'Deploy started',
        'deploy_succeeded' => 'Deploy succeeded',
        'deploy_failed' => 'Deploy failed',
        'deploy_cancelled' => 'Deploy stopped',
        'webhook_ignored' => 'Push skipped (auto deploy off or a deploy already running)',
        'site_down' => 'Website is down',
        'site_up' => 'Website is back up',
        'process_crashed' => 'Process stopped or crashing',
        'process_recovered' => 'Process running again',
        'disk_high' => 'Disk usage above the limit',
        'server_patches' => 'Server patches available',
        'issue_new' => 'New issue',
        'error_spike' => 'Error spike',
        'slow_site' => 'Slow responses',
        'threat_detected' => 'Attack detected',
        'heartbeat_missed' => 'Heartbeat missed',
        'backup_failed' => 'A backup or restore failed',
        'backup_succeeded' => 'A backup finished',
    ];

    public const DEFAULT_EVENTS = ['deploy_started', 'deploy_succeeded', 'deploy_failed', 'site_down', 'site_up', 'process_crashed', 'process_recovered', 'disk_high', 'server_patches', 'issue_new', 'error_spike', 'slow_site', 'heartbeat_missed', 'backup_failed'];

    public function configured(): bool
    {
        return filled(Setting::getValue('telegram_bot_token')) && filled(Setting::getValue('telegram_chat_id'));
    }

    public function enabled(string $event): bool
    {
        if (Setting::getValue('telegram_enabled') !== '1' || ! $this->configured()) {
            return false;
        }

        $events = json_decode((string) Setting::getValue('telegram_events'), true);

        return in_array($event, is_array($events) ? $events : self::DEFAULT_EVENTS, true);
    }

    /**
     * The topic for an event: its own thread ID when set, otherwise the default one.
     */
    public function threadFor(string $event): string
    {
        $threads = json_decode((string) Setting::getValue('telegram_threads'), true);
        $own = is_array($threads) ? trim((string) ($threads[$event] ?? '')) : '';

        return $own !== '' ? $own : (string) Setting::getValue('telegram_thread_id');
    }

    /**
     * Send an HTML message with optional link buttons. Throws with Telegram's own
     * description when it fails. Buttons Telegram refuses (e.g. a localhost URL)
     * are dropped and the message is sent again without them.
     *
     * @param  list<array{text: string, url: string}>  $buttons
     */
    public function send(string $html, ?string $token = null, ?string $chatId = null, ?string $threadId = null, array $buttons = []): void
    {
        $token ??= (string) Setting::getValue('telegram_bot_token');
        $chatId ??= (string) Setting::getValue('telegram_chat_id');
        $threadId ??= (string) Setting::getValue('telegram_thread_id');

        if ($token === '' || $chatId === '') {
            throw new RuntimeException('Add a bot token and a chat ID first.');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($html, 0, 4000),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($threadId !== '') {
            $payload['message_thread_id'] = (int) $threadId;
        }

        $buttons = array_values(array_filter($buttons, fn (array $button): bool => self::buttonUrl($button['url'] ?? '')));

        if ($buttons !== []) {
            // One row, like Coolify: [Deployment logs] [Open site]
            $payload['reply_markup'] = ['inline_keyboard' => [array_map(fn (array $button): array => ['text' => $button['text'], 'url' => $button['url']], $buttons)]];
        }

        $response = $this->call($token, 'sendMessage', $payload);

        if (! ($response['ok'] ?? false) && isset($payload['reply_markup'])) {
            unset($payload['reply_markup']);
            $response = $this->call($token, 'sendMessage', $payload);
        }

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException('Telegram: '.($response['description'] ?? 'the message was not sent.'));
        }
    }

    /**
     * Send without ever throwing. Returns the error message, or null on success.
     *
     * @param  list<array{text: string, url: string}>  $buttons
     */
    public function notify(string $event, string $html, array $buttons = []): ?string
    {
        if (! $this->enabled($event)) {
            return null;
        }

        try {
            $this->send($html, null, null, $this->threadFor($event), $buttons);

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * Telegram only accepts public http(s) addresses on buttons.
     */
    public static function buttonUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return preg_match('#^https?://#i', $url) === 1
            && $host !== ''
            && str_contains($host, '.')
            && ! in_array(strtolower($host), ['localhost', '127.0.0.1'], true)
            && ! str_ends_with(strtolower($host), '.test')
            && ! str_ends_with(strtolower($host), '.local');
    }

    /**
     * Chats and forum topics the bot has seen recently, from getUpdates.
     *
     * @return list<array{chat_id: string, thread_id: string, title: string, type: string}>
     */
    public function recentChats(string $token): array
    {
        $response = $this->call($token, 'getUpdates', ['limit' => 100, 'allowed_updates' => ['message', 'channel_post', 'my_chat_member']]);

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException('Telegram: '.($response['description'] ?? 'could not read updates.'));
        }

        $found = [];

        foreach ((array) ($response['result'] ?? []) as $update) {
            $message = $update['message'] ?? $update['channel_post'] ?? $update['my_chat_member'] ?? null;
            $chat = is_array($message) ? ($message['chat'] ?? null) : null;

            if (! is_array($chat) || ! isset($chat['id'])) {
                continue;
            }

            $thread = isset($message['message_thread_id']) && ($message['is_topic_message'] ?? false) ? (string) $message['message_thread_id'] : '';
            $topic = (string) data_get($message, 'reply_to_message.forum_topic_created.name', data_get($message, 'forum_topic_created.name', ''));
            $title = (string) ($chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['username'] ?? 'Chat'));

            $found[$chat['id'].':'.$thread] = [
                'chat_id' => (string) $chat['id'],
                'thread_id' => $thread,
                'title' => $title.($thread !== '' ? ' › '.($topic !== '' ? $topic : 'topic '.$thread) : ''),
                'type' => (string) ($chat['type'] ?? ''),
            ];
        }

        return array_values($found);
    }

    public static function escape(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $token, string $method, array $payload): array
    {
        if (preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token) !== 1) {
            throw new RuntimeException('That does not look like a bot token. It should look like 123456:ABC-DEF...');
        }

        try {
            $response = Http::timeout(10)->asJson()->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);
        } catch (Throwable $exception) {
            throw new RuntimeException('Could not reach Telegram: '.str_replace($token, '***', $exception->getMessage()));
        }

        $json = $response->json();

        return is_array($json) ? $json : ['ok' => false, 'description' => 'HTTP '.$response->status()];
    }
}
