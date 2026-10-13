<?php

namespace Tests\Feature;

use App\Livewire\Settings\Github;
use App\Livewire\Settings\Telegram as TelegramSettings;
use App\Livewire\Websites\Show;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\ServerMonitor;
use App\Services\Telegram;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationsAndSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHfakeTokenForTests_abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();

        config(['laravelify.host_commands' => false]);
    }

    public function test_buttons_are_sent_as_an_inline_keyboard_and_local_links_are_dropped(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->configureTelegram(['deploy_succeeded']);

        app(Telegram::class)->notify('deploy_succeeded', 'hello', [
            ['text' => 'Open website', 'url' => 'https://app.example.com'],
            ['text' => 'Local', 'url' => 'http://localhost/websites/x'],
            ['text' => 'Herd', 'url' => 'https://panel.test'],
        ]);

        Http::assertSent(fn (Request $request): bool => $request['reply_markup'] === ['inline_keyboard' => [[['text' => 'Open website', 'url' => 'https://app.example.com']]]]);
    }

    public function test_a_rejected_keyboard_is_retried_without_buttons(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'description' => 'Bad Request: BUTTON_URL_INVALID'], 400)
            ->push(['ok' => true, 'result' => []])]);
        $this->configureTelegram(['deploy_succeeded']);

        $error = app(Telegram::class)->notify('deploy_succeeded', 'hello', [['text' => 'Open', 'url' => 'https://app.example.com']]);

        $this->assertNull($error);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ! isset($request['reply_markup']));
    }

    public function test_each_event_can_post_to_its_own_topic(): void
    {
        $this->configureTelegram(['deploy_failed', 'site_down']);
        Setting::putValue('telegram_threads', json_encode(['deploy_failed' => '77']));

        $telegram = app(Telegram::class);

        $this->assertSame('77', $telegram->threadFor('deploy_failed'));
        $this->assertSame('42', $telegram->threadFor('site_down'));
    }

    public function test_a_website_alerts_after_two_failed_checks_and_once_when_it_recovers(): void
    {
        $this->configureTelegram(['site_down', 'site_up']);
        $website = Website::factory()->create(['domain' => 'app.example.com', 'http_port' => null, 'cloudflare_tunnel_id' => null]);
        $website->deployments()->create(['status' => 'success', 'trigger' => 'manual', 'log' => '']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
            'app.example.com*' => Http::sequence()->push('down', 502)->push('down', 502)->push('down', 502)->push('ok', 200),
        ]);

        $monitor = app(ServerMonitor::class);
        $monitor->checkWebsites();
        $this->assertSame(0, $this->telegramMessages('is down'));

        $monitor->checkWebsites();
        $this->assertSame(1, $this->telegramMessages('is down'));

        $monitor->checkWebsites();
        $this->assertSame(1, $this->telegramMessages('is down'));

        $monitor->checkWebsites();
        $this->assertSame(1, $this->telegramMessages('is back up'));
        $this->assertFalse(Cache::get('monitor:site:'.$website->id)['down']);
    }

    public function test_apt_upgradable_output_is_parsed(): void
    {
        $updates = ServerMonitor::parseUpgradable(implode("\n", [
            'Listing...',
            'base-files/jammy-updates 12ubuntu4.7 amd64 [upgradable from: 12ubuntu4.6]',
            'libssl3/jammy-security 3.0.2-0ubuntu1.18 amd64 [upgradable from: 3.0.2-0ubuntu1.17]',
        ]));

        $this->assertSame([
            ['package' => 'base-files', 'current_version' => '12ubuntu4.6', 'new_version' => '12ubuntu4.7'],
            ['package' => 'libssl3', 'current_version' => '3.0.2-0ubuntu1.17', 'new_version' => '3.0.2-0ubuntu1.18'],
        ], $updates);
    }

    public function test_saved_secrets_stay_hidden_until_the_authenticator_code_is_entered(): void
    {
        $user = $this->signIn();
        Setting::putValue('github_token', 'ghp_saved_token');

        $component = Livewire::test(Github::class)->assertDontSee('ghp_saved_token');

        $this->assertSame(['ok' => false, 'locked' => true], $this->returned($component->call('revealSecret', 'github_token')));
        $this->assertFalse($this->returned($component->call('unlockSecrets', '000000'))['ok']);

        $unlocked = $this->returned($component->call('unlockSecrets', app(TwoFactor::class)->currentCode($user)));
        $this->assertTrue($unlocked['ok']);

        $this->assertSame(['ok' => true, 'value' => 'ghp_saved_token'], $this->returned($component->call('revealSecret', 'github_token')));
        $this->assertSame(['ok' => false, 'error' => 'Nothing is saved yet.'], $this->returned($component->call('revealSecret', 'something_else')));
    }

    public function test_unlocking_is_rate_limited(): void
    {
        $user = $this->signIn();
        $component = Livewire::test(Github::class);

        foreach (range(1, 5) as $attempt) {
            $component->call('unlockSecrets', '000000');
        }

        $result = $this->returned($component->call('unlockSecrets', app(TwoFactor::class)->currentCode($user)));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Too many', $result['error']);
    }

    public function test_the_telegram_token_and_website_secrets_can_be_revealed_after_unlocking(): void
    {
        $this->signIn();
        session(['secrets_unlocked_until' => now()->addMinutes(5)->getTimestamp()]);
        Setting::putValue('telegram_bot_token', self::TOKEN);
        $website = Website::factory()->create(['webhook_secret' => 'hook-secret', 'db_password' => 'db-secret']);

        $this->assertSame(self::TOKEN, $this->returned(Livewire::test(TelegramSettings::class)->call('revealSecret', 'telegram_bot_token'))['value']);

        $show = Livewire::test(Show::class, ['website' => $website]);
        $this->assertSame('hook-secret', $this->returned($show->call('revealSecret', 'webhook_secret'))['value']);
        $this->assertSame('db-secret', $this->returned($show->call('revealSecret', 'db_password'))['value']);
    }

    public function test_the_basic_auth_password_is_kept_encrypted_so_it_can_be_shown_again(): void
    {
        $website = Website::factory()->create();
        $website->update(['basic_auth_password' => 'letmein']);

        $this->assertSame('letmein', $website->fresh()->basic_auth_password);
        $this->assertNotSame('letmein', Website::query()->toBase()->where('id', $website->id)->value('basic_auth_password'));
        $this->assertArrayNotHasKey('basic_auth_password', $website->fresh()->toArray());
    }

    /**
     * @param  list<string>  $events
     */
    private function configureTelegram(array $events): void
    {
        Setting::putValue('telegram_enabled', '1');
        Setting::putValue('telegram_bot_token', self::TOKEN);
        Setting::putValue('telegram_chat_id', '-1001234567890');
        Setting::putValue('telegram_thread_id', '42');
        Setting::putValue('telegram_events', json_encode($events));
    }

    private function telegramMessages(string $needle): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org') && str_contains((string) $request['text'], $needle))->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function returned(mixed $component): array
    {
        return $component->effects['returns'][0] ?? [];
    }

    private function signIn(): User
    {
        $user = User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user);

        return $user;
    }
}
