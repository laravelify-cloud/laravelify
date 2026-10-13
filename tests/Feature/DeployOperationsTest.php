<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Livewire\Settings\Telegram as TelegramSettings;
use App\Livewire\Websites\Health;
use App\Livewire\Websites\Logs;
use App\Models\Deployment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\Deployer;
use App\Services\HostWriter;
use App\Services\ServerProcesses;
use App\Services\SiteHealth;
use App\Services\SiteLogs;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class DeployOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHfakeTokenForTests_abcdefghij';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        config(['laravelify.host_commands' => false]);
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-ops-'.uniqid();
        mkdir($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/storage/logs/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root.'/storage/logs');
        @rmdir($this->root.'/storage');
        @rmdir($this->root);

        parent::tearDown();
    }

    public function test_push_webhooks_queue_a_deploy_with_the_commit_and_record_the_result(): void
    {
        Queue::fake();
        $website = Website::factory()->create(['webhook_secret' => 'topsecret', 'branch' => 'main']);

        $this->deliver($website, ['zen' => 'hi'], 'ping')->assertOk()->assertJson(['reason' => 'ping']);
        $this->assertSame('Ping received. The webhook is connected.', $website->fresh()->last_webhook_result);

        $this->deliver($website, ['ref' => 'refs/heads/dev'], 'push')->assertJson(['ignored' => true]);
        $this->assertStringContainsString('Ignored a push to dev', $website->fresh()->last_webhook_result);

        $this->deliver($website, [
            'ref' => 'refs/heads/main',
            'after' => str_repeat('a', 40),
            'head_commit' => ['message' => "Fix the widget\n\nLonger body", 'author' => ['name' => 'Ada']],
        ], 'push')->assertJson(['ignored' => false]);

        $deployment = Deployment::query()->latest('id')->first();
        $this->assertSame('webhook', $deployment->trigger);
        $this->assertSame('main', $deployment->branch);
        $this->assertSame('Ada', $deployment->commit_author);
        $this->assertStringStartsWith('Fix the widget', $deployment->commit_message);
        $this->assertStringContainsString('Queued deploy #'.$deployment->id, $website->fresh()->last_webhook_result);
        Queue::assertPushed(RunDeployment::class);
    }

    public function test_auto_deploy_off_records_the_push_and_tells_telegram(): void
    {
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->configureTelegram(['webhook_ignored']);
        $website = Website::factory()->create(['webhook_secret' => 'topsecret', 'auto_deploy' => false]);

        $this->deliver($website, ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)], 'push')
            ->assertJson(['ignored' => true, 'reason' => 'auto deploy is off']);

        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNothingPushed();
        Http::assertSent(fn (Request $request): bool => str_contains(strtolower($request['text']), 'auto deploy is off'));
    }

    public function test_deploys_send_started_and_finished_messages_to_the_configured_topic(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->configureTelegram(['deploy_started', 'deploy_succeeded', 'deploy_failed']);
        $website = Website::factory()->create();
        $deployment = $website->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);

        app(Deployer::class)->run($deployment);

        $this->assertSame('success', $deployment->fresh()->status);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Deployment started')
            && $request['chat_id'] === '-1001234567890'
            && $request['message_thread_id'] === 42
            && str_contains($request->url(), self::TOKEN.'/sendMessage'));
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Deployment succeeded') && str_contains($request['text'], e($website->name)));
    }

    public function test_a_failed_telegram_call_never_fails_the_deploy(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
        $this->configureTelegram(['deploy_started', 'deploy_succeeded']);
        $website = Website::factory()->create();
        $deployment = $website->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);

        app(Deployer::class)->run($deployment);

        $deployment->refresh();
        $this->assertSame('success', $deployment->status);
        $this->assertStringContainsString('Telegram notification failed: Telegram: Bad Request: chat not found', $deployment->log);
        $this->assertStringNotContainsString(self::TOKEN, $deployment->log);
    }

    public function test_the_telegram_page_saves_settings_and_sends_a_test(): void
    {
        Http::fake([
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [
                ['message' => ['message_thread_id' => 7, 'is_topic_message' => true, 'chat' => ['id' => -1009876543210, 'title' => 'Ops', 'type' => 'supergroup']]],
            ]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $this->signIn();

        $this->get(route('settings.telegram'))->assertOk()->assertSee('Telegram bot');

        Livewire::test(TelegramSettings::class)
            ->set('token', 'not-a-token')
            ->call('save')
            ->assertHasErrors('token')
            ->set('token', self::TOKEN)
            ->call('detect')
            ->assertSet('detected.0.chat_id', '-1009876543210')
            ->call('useChat', 0)
            ->assertSet('chatId', '-1009876543210')
            ->assertSet('threadId', '7')
            ->set('enabled', true)
            ->set('events', ['deploy_failed'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('token', '')
            ->call('sendTest');

        $this->assertSame(self::TOKEN, Setting::getValue('telegram_bot_token'));
        $this->assertSame('-1009876543210', Setting::getValue('telegram_chat_id'));
        $this->assertSame('7', Setting::getValue('telegram_thread_id'));
        $this->assertSame(['deploy_failed'], json_decode(Setting::getValue('telegram_events'), true));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage') && $request['message_thread_id'] === 7);
    }

    public function test_site_logs_read_laravel_and_supervisor_logs_inside_the_site_only(): void
    {
        file_put_contents($this->root.'/storage/logs/laravel.log', implode("\n", [
            '['.now()->subHours(2)->format('Y-m-d H:i:s').'] production.ERROR: Something broke {"exception":"x"}',
            '#0 /var/www/app/vendor/foo.php(10): bar()',
            '['.now()->subDays(3)->format('Y-m-d H:i:s').'] production.ERROR: Old problem',
            '['.now()->subHour()->format('Y-m-d H:i:s').'] production.INFO: Fine',
        ]));
        file_put_contents($this->root.'/storage/logs/queue.log', "job one\njob two\n");
        $root = str_replace('\\', '/', realpath($this->root));
        $website = Website::factory()->create([
            'deploy_path' => $this->root,
            'process_config' => "[program:shop-queue]\nstdout_logfile={$root}/storage/logs/queue.log\n\n[program:shop-evil]\nstdout_logfile=/etc/passwd\n",
        ]);
        $logs = app(SiteLogs::class);

        $keys = collect($logs->sources($website))->pluck('key');
        $this->assertTrue($keys->contains('laravel'));
        $this->assertTrue($keys->contains('program:shop-queue'));
        $this->assertFalse($keys->contains('program:shop-evil'));

        $this->assertSame('job two', $logs->tail($website, 'program:shop-queue', 10) === "job one\njob two" ? 'job two' : 'mismatch');
        $this->assertSame(['count' => 1, 'last' => 'Something broke {"exception":"x"}'], $logs->recentErrors($website));

        $this->expectException(\RuntimeException::class);
        $logs->tail($website, 'program:shop-evil', 10);
    }

    public function test_the_logs_tab_switches_sources_and_clears_a_file(): void
    {
        file_put_contents($this->root.'/storage/logs/laravel.log', "[2026-10-12 10:00:00] production.ERROR: Boom\n");
        $this->signIn();
        $website = Website::factory()->create(['deploy_path' => $this->root, 'nginx_config' => "server {\n    server_name example.com;\n}\n"]);

        Livewire::test(Logs::class, ['website' => $website])
            ->assertSee('Boom')
            ->assertSee('Enable nginx logs')
            ->set('confirmName', 'clear')
            ->call('clearLog')
            ->assertDispatched('log-cleared')
            ->assertSet('content', '');

        $this->assertSame('', file_get_contents($this->root.'/storage/logs/laravel.log'));
    }

    public function test_health_checks_the_site_and_offers_nginx_logs(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->signIn();
        $website = Website::factory()->create(['deploy_path' => $this->root, 'nginx_config' => "server {\n    server_name example.com;\n}\n"]);

        $checks = collect(Livewire::withoutLazyLoading()->test(Health::class, ['website' => $website])->get('checks'))->keyBy('key');

        $this->assertSame('ok', $checks['http']['status']);
        $this->assertSame('warn', $checks['nginx-logs']['status']);
        $this->assertSame('unknown', $checks['deploy']['status']);

        Livewire::withoutLazyLoading()->test(Health::class, ['website' => $website])->call('enableNginxLogs');

        $this->assertStringContainsString('access_log /var/log/nginx/laravelify-'.$website->slug.'.access.log;', $website->fresh()->nginx_config);
    }

    public function test_redeploys_fetch_with_a_fresh_token_and_keep_origin_token_free(): void
    {
        mkdir($this->root.DIRECTORY_SEPARATOR.'.git');
        Setting::putValue('github_token', 'ghp_freshToken123');
        $website = Website::factory()->create([
            'deploy_path' => $this->root,
            'repository' => 'https://github.com/FFKRISTAL-AZ/chat-app-widget-laravel.git',
            'branch' => 'main',
        ]);
        $deployment = $website->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);

        $runner = new class extends CommandRunner
        {
            /** @var list<list<string>> */
            public array $commands = [];

            public function supportsHostCommands(): bool
            {
                return true;
            }

            public function run(array $command, ?string $cwd = null, array $env = []): CommandResult
            {
                $this->commands[] = $command;

                return new CommandResult(true, false, $command, '', 0);
            }
        };
        $this->app->instance(CommandRunner::class, $runner);

        try {
            app(Deployer::class)->run($deployment);
        } finally {
            @unlink($this->root.DIRECTORY_SEPARATOR.'.env');
            @rmdir($this->root.DIRECTORY_SEPARATOR.'.git');
        }

        $git = collect($runner->commands)->filter(fn (array $command): bool => $command[0] === 'git')->map(fn (array $command): string => implode(' ', $command));

        $this->assertTrue($git->contains(fn (string $line): bool => str_contains($line, 'remote set-url origin https://github.com/FFKRISTAL-AZ/chat-app-widget-laravel.git')));
        $this->assertTrue($git->contains(fn (string $line): bool => str_contains($line, 'fetch https://x-access-token:ghp_freshToken123@github.com/FFKRISTAL-AZ/chat-app-widget-laravel.git +refs/heads/main:refs/remotes/origin/main')));
        $this->assertFalse($git->contains(fn (string $line): bool => str_contains($line, 'fetch origin')));
        $this->assertStringNotContainsString('ghp_freshToken123', (string) $deployment->fresh()->log);
    }

    public function test_nextjs_deploys_use_the_lockfiles_package_manager_and_complete_the_standalone_bundle(): void
    {
        $site = $this->root.DIRECTORY_SEPARATOR.'next';
        foreach (['.git', 'public', '.next/static/chunks', '.next/standalone'] as $dir) {
            mkdir($site.'/'.$dir, 0777, true);
        }
        file_put_contents($site.'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        file_put_contents($site.'/package.json', '{"packageManager":"pnpm@10.12.1"}');
        file_put_contents($site.'/public/logo.svg', '<svg/>');
        file_put_contents($site.'/.next/static/chunks/app.js', 'console.log(1)');
        file_put_contents($site.'/.next/standalone/server.js', '// next');

        $website = Website::factory()->create([
            'type' => 'nextjs',
            'deploy_path' => $site,
            'node_version' => '22',
            'env_content' => "NEXT_PUBLIC_API_URL=https://api.example.com\n",
        ]);
        $deployment = $website->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);

        $runner = new class extends CommandRunner
        {
            /** @var list<string> */
            public array $commands = [];

            public function supportsHostCommands(): bool
            {
                return true;
            }

            public function run(array $command, ?string $cwd = null, array $env = []): CommandResult
            {
                $this->commands[] = implode(' ', $command);

                return new CommandResult(true, false, $command, '', 0);
            }
        };
        $this->app->instance(CommandRunner::class, $runner);

        try {
            app(Deployer::class)->run($deployment);

            $this->assertSame('success', $deployment->fresh()->status, (string) $deployment->fresh()->log);
            $commands = collect($runner->commands);
            $this->assertTrue($commands->contains(fn (string $line): bool => str_ends_with($line, '22 corepack pnpm install --frozen-lockfile')));
            $this->assertTrue($commands->contains(fn (string $line): bool => str_ends_with($line, '22 corepack pnpm run build')));
            $this->assertFalse($commands->contains(fn (string $line): bool => str_contains($line, 'npm --no-progress')));
            $this->assertFileExists($site.'/.next/standalone/public/logo.svg');
            $this->assertFileExists($site.'/.next/standalone/.next/static/chunks/app.js');
            $this->assertStringContainsString('NEXT_PUBLIC_API_URL', (string) file_get_contents($site.'/.next/standalone/.env'));
        } finally {
            File::deleteDirectory($site);
        }
    }

    public function test_health_reads_supervisor_states_in_any_case(): void
    {
        config(['laravelify.host_commands' => true]);
        Http::fake(['*' => Http::response('ok', 200)]);
        $website = Website::factory()->create([
            'deploy_path' => $this->root,
            'process_config' => "[program:shop-queue]\nstdout_logfile=/x.log\n[program:shop-scheduler]\nstdout_logfile=/y.log\n",
        ]);
        $this->mock(ServerProcesses::class, fn ($mock) => $mock->shouldReceive('list')->andReturn([
            ['manager' => 'supervisor', 'name' => 'shop-queue', 'pid' => '12', 'status' => 'running', 'detail' => 'pid 12, uptime 0:10:00'],
            ['manager' => 'supervisor', 'name' => 'shop-scheduler', 'pid' => '', 'status' => 'fatal', 'detail' => 'Exited too quickly'],
        ]));
        $this->mock(HostWriter::class, fn ($mock) => $mock->shouldReceive('nginxTest')->andReturn('nginx: configuration file /etc/nginx/nginx.conf test is successful'));

        $checks = collect(app(SiteHealth::class)->check($website))->keyBy('key');

        $this->assertSame('ok', $checks['program:shop-queue']['status']);
        $this->assertSame('fail', $checks['program:shop-scheduler']['status']);
        $this->assertStringContainsString('Supervisor stopped retrying', $checks['program:shop-scheduler']['detail']);
        $this->assertSame('ok', $checks['nginx']['status']);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(Website $website, array $payload, string $event): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/hooks/github/'.$website->slug, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'topsecret'),
        ], $body);
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }
}
