<?php

namespace App\Livewire\Settings;

use App\Livewire\PanelComponent;
use App\Models\Setting;
use App\Services\Telegram as TelegramClient;
use Throwable;

class Telegram extends PanelComponent
{
    public bool $enabled = false;

    /** Empty keeps the saved token. */
    public string $token = '';

    public bool $hasToken = false;

    public string $chatId = '';

    public string $threadId = '';

    /** @var list<string> */
    public array $events = [];

    /** Optional topic per event; empty uses the default thread. @var array<string, string> */
    public array $threads = [];

    public string $diskThreshold = '80';

    /** @var list<array{chat_id: string, thread_id: string, title: string, type: string}> */
    public array $detected = [];

    public function mount(): void
    {
        $this->enabled = Setting::getValue('telegram_enabled') === '1';
        $this->hasToken = filled(Setting::getValue('telegram_bot_token'));
        $this->chatId = (string) Setting::getValue('telegram_chat_id');
        $this->threadId = (string) Setting::getValue('telegram_thread_id');
        $this->events = app(TelegramClient::class)->chosenEvents();
        $threads = json_decode((string) Setting::getValue('telegram_threads'), true);
        $this->threads = array_map(fn ($event): string => is_array($threads) ? (string) ($threads[$event] ?? '') : '', array_combine(array_keys(TelegramClient::EVENTS), array_keys(TelegramClient::EVENTS)));
        $this->diskThreshold = (string) (Setting::getValue('telegram_disk_threshold') ?: '80');
    }

    /**
     * The saved bot token, for the eye button (after the authenticator code).
     */
    protected function revealableSecret(string $key): ?string
    {
        return $key === 'telegram_bot_token' ? Setting::getValue('telegram_bot_token') : null;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        if ($this->enabled && ! $this->hasToken && $this->token === '') {
            $this->addError('token', 'Add the bot token to turn notifications on.');

            return;
        }

        if ($this->token !== '') {
            Setting::putValue('telegram_bot_token', trim($this->token));
            $this->token = '';
            $this->hasToken = true;
        }

        Setting::putValue('telegram_enabled', $this->enabled ? '1' : '0');
        Setting::putValue('telegram_chat_id', trim($this->chatId));
        Setting::putValue('telegram_thread_id', trim($this->threadId));
        TelegramClient::saveEvents($this->events);
        Setting::putValue('telegram_threads', json_encode(array_filter(
            array_intersect_key(array_map(fn ($thread): string => trim((string) $thread), $this->threads), TelegramClient::EVENTS),
            fn (string $thread): bool => $thread !== '',
        )));
        Setting::putValue('telegram_disk_threshold', (string) (int) $this->diskThreshold);

        $this->toast('Telegram settings saved.');
    }

    public function sendTest(TelegramClient $telegram): void
    {
        $this->validate($this->rules());

        try {
            $telegram->send(
                "✅ Laravelify: Telegram notifications are connected!\n\n📊 You will get:\n• Deployment started, succeeded, and failed\n• Website down and back up\n• Crashed queue, scheduler, and PM2 apps\n• Disk usage and server patches",
                $this->token !== '' ? trim($this->token) : null,
                trim($this->chatId),
                trim($this->threadId),
                [['text' => 'Open Laravelify', 'url' => route('dashboard')]],
            );
            $this->toast('Test message sent. Check Telegram.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    /**
     * Read the bot's recent updates to find chat and topic IDs.
     */
    public function detect(TelegramClient $telegram): void
    {
        $token = $this->token !== '' ? trim($this->token) : (string) Setting::getValue('telegram_bot_token');

        try {
            $this->detected = $telegram->recentChats($token);
            $this->toast($this->detected === [] ? 'No chats found. Send a message to the bot or in the group, then try again.' : count($this->detected).' chat(s) found.', $this->detected === [] ? 'warning' : 'success');
        } catch (Throwable $exception) {
            $this->detected = [];
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function useChat(int $index): void
    {
        $chat = $this->detected[$index] ?? null;

        if ($chat) {
            $this->chatId = $chat['chat_id'];
            $this->threadId = $chat['thread_id'];
        }
    }

    public function forgetToken(): void
    {
        if (! $this->confirmed('telegram-token')) {
            return;
        }

        Setting::query()->where('key', 'telegram_bot_token')->delete();
        Setting::putValue('telegram_enabled', '0');
        $this->hasToken = false;
        $this->enabled = false;
        $this->toast('Bot token removed. Notifications are off.');
    }

    public function render()
    {
        return view('livewire.settings.telegram', [
            'eventGroups' => TelegramClient::EVENT_GROUPS,
        ])->layout('layouts.app')->title('Telegram bot');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'token' => ['nullable', 'string', 'max:200', 'regex:/^\d+:[A-Za-z0-9_-]{20,}$/'],
            'chatId' => [$this->enabled ? 'required' : 'nullable', 'string', 'max:64', 'regex:/^(-?\d+|@[A-Za-z0-9_]{5,})$/'],
            'threadId' => ['nullable', 'string', 'max:20', 'regex:/^\d+$/'],
            'events' => ['array'],
            'events.*' => ['string'],
            'threads' => ['array'],
            'threads.*' => ['nullable', 'regex:/^\d*$/', 'max:20'],
            'diskThreshold' => ['required', 'integer', 'between:50,99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'token.regex' => 'A bot token looks like 123456789:AAH... (from @BotFather).',
            'chatId.regex' => 'Use a numeric chat ID like -1001234567890, or a public @channelname.',
            'threadId.regex' => 'The thread (topic) ID is a number.',
            'threads.*.regex' => 'Topic IDs are numbers.',
            'diskThreshold.between' => 'Pick a limit between 50 and 99 percent.',
        ];
    }
}
