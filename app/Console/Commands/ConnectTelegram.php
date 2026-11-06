<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram;
use Illuminate\Console\Command;

/**
 * Turn on Telegram notifications with a bot and chat given from outside: Laravelify Cloud runs
 * it after an install with --api set to its relay, so the client gets their panel's notifications
 * from the Cloud bot without the panel ever holding that bot's token. The
 * token comes from LARAVELIFY_TELEGRAM_TOKEN, so it never shows in the process list.
 */
class ConnectTelegram extends Command
{
    protected $signature = 'laravelify:telegram-connect {chat : The Telegram chat id to send to} {--api= : Send through this Bot API address instead of api.telegram.org}';

    protected $description = 'Turn on Telegram notifications with the bot in LARAVELIFY_TELEGRAM_TOKEN';

    public function handle(): int
    {
        $token = trim((string) getenv('LARAVELIFY_TELEGRAM_TOKEN'));
        $chat = trim((string) $this->argument('chat'));

        if ($token === '' || ! preg_match('/^-?\d+$/', $chat)) {
            $this->error('Give LARAVELIFY_TELEGRAM_TOKEN and a numeric chat id.');

            return self::FAILURE;
        }

        Setting::putValue('telegram_bot_token', $token);
        Setting::putValue('telegram_chat_id', $chat);
        Setting::putValue('telegram_enabled', '1');
        Setting::putValue('telegram_api_url', $this->option('api') ? rtrim((string) $this->option('api'), '/') : null);

        if (blank(Setting::getValue('telegram_events'))) {
            Telegram::saveEvents(Telegram::DEFAULT_EVENTS);
        }

        $this->line('Telegram notifications are on.');

        return self::SUCCESS;
    }
}
