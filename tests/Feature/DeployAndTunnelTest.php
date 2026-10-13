<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Livewire\Server\Edit as ServerEdit;
use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\CloudflareTunnel;
use App\Models\Deployment;
use App\Models\Group;
use App\Models\ServerService;
use App\Models\User;
use App\Models\Website;
use App\Services\ConfigRenderer;
use App\Services\DatabaseProvisioner;
use App\Services\DeploymentLogger;
use App\Services\GithubWebhook;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeployAndTunnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingress_lists_every_website_on_a_tunnel_and_ends_with_a_404(): void
    {
        $group = Group::factory()->create();
        $tunnel = CloudflareTunnel::factory()->create();

        Website::factory()->create([
            'group_id' => $group->id,
            'cloudflare_tunnel_id' => $tunnel->id,
            'domain' => 'api.example.com',
        ]);
        Website::factory()->create([
            'group_id' => $group->id,
            'cloudflare_tunnel_id' => $tunnel->id,
            'domain' => 'shop.example.com',
        ]);

        $ingress = app(ConfigRenderer::class)->ingress($tunnel);

        $this->assertStringContainsString('hostname: api.example.com', $ingress);
        $this->assertStringContainsString('hostname: shop.example.com', $ingress);
        $this->assertStringContainsString('service: http://127.0.0.1:80', $ingress);
        $this->assertStringContainsString('service: http_status:404', $ingress);
    }

    public function test_github_webhook_checks_the_signature_and_branch(): void
    {
        $website = Website::factory()->create([
            'branch' => 'main',
            'webhook_secret' => 'topsecret',
        ]);

        $ignored = json_encode(['ref' => 'refs/heads/develop'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/hooks/github/'.$website->slug, [], [], [], $this->signatureHeaders($ignored, 'topsecret'), $ignored)
            ->assertOk()
            ->assertJson(['ignored' => true]);

        $this->assertDatabaseCount('deployments', 0);

        $this->call('POST', '/hooks/github/'.$website->slug, [], [], [], $this->signatureHeaders($ignored, 'wrong'), $ignored)
            ->assertUnauthorized();

        $body = json_encode(['ref' => 'refs/heads/main', 'after' => 'abc123def'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/hooks/github/'.$website->slug, [], [], [], $this->signatureHeaders($body, 'topsecret'), $body)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $deployment = $website->deployments()->first();
        $this->assertNotNull($deployment);
        $this->assertSame('webhook', $deployment->trigger);
        $this->assertSame('success', $deployment->status);
        $this->assertStringContainsString('Host commands are disabled', (string) $deployment->log);
    }

    public function test_manual_deploy_job_renders_configs_without_running_host_commands(): void
    {
        $website = Website::factory()->create();
        $deployment = $website->deployments()->create([
            'status' => 'queued',
            'trigger' => 'manual',
            'log' => '',
        ]);

        RunDeployment::dispatchSync($deployment->id);

        $deployment->refresh();
        $this->assertSame('success', $deployment->status);
        $this->assertFileExists(storage_path('app/rendered/sites/'.$website->slug.'/nginx.conf'));
    }

    public function test_server_services_include_mysql_redis_phpmyadmin_and_php_fpm(): void
    {
        $this->assertTrue(ServerService::query()->where('key', 'mysql')->exists());
        $this->assertTrue(ServerService::query()->where('key', 'redis')->exists());
        $this->assertTrue(ServerService::query()->where('key', 'phpmyadmin')->exists());

        foreach (config('laravelify.php_versions') as $version) {
            $service = ServerService::query()->where('key', 'php-fpm-'.$version)->first();
            $this->assertNotNull($service);
            $this->assertStringContainsString('/run/php/php'.$version.'-fpm.sock', $service->config);
        }

        $phpmyadmin = ServerService::query()->where('key', 'phpmyadmin')->first();
        $this->assertStringContainsString('server_name phpmyadmin.internal', $phpmyadmin->config);
        $this->assertSame('phpmyadmin.internal', $phpmyadmin->domain);
        $this->assertSame('127.0.0.1', ServerService::query()->where('key', 'mysql')->value('domain'));
        $this->assertSame('127.0.0.1', ServerService::query()->where('key', 'redis')->value('domain'));
    }

    public function test_mysql_redis_and_phpmyadmin_settings_update_their_configs(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));

        $phpmyadmin = ServerService::query()->where('key', 'phpmyadmin')->firstOrFail();

        Livewire::test(ServerEdit::class, ['service' => $phpmyadmin])
            ->set('domain', 'db.example.com')
            ->set('php_version', '8.2')
            ->call('save')
            ->assertHasNoErrors();

        $phpmyadmin->refresh();
        $this->assertSame('db.example.com', $phpmyadmin->domain);
        $this->assertSame('8.2', $phpmyadmin->settings['php_version']);
        $this->assertStringContainsString('server_name db.example.com;', $phpmyadmin->config);
        $this->assertStringContainsString('unix:/run/php/php8.2-fpm.sock', $phpmyadmin->config);

        $mysql = ServerService::query()->where('key', 'mysql')->firstOrFail();

        Livewire::test(ServerEdit::class, ['service' => $mysql])
            ->set('domain', '127.0.0.1')
            ->set('bind', '127.0.0.1')
            ->set('port', '3306')
            ->set('password', 'panel-root-secret')
            ->call('save')
            ->assertHasNoErrors();

        $mysql->refresh();
        $this->assertStringContainsString('bind-address = 127.0.0.1', $mysql->config);
        $this->assertStringContainsString('port = 3306', $mysql->config);
        $this->assertSame('panel-root-secret', $mysql->settings['password']);
        $this->assertSame('root', $mysql->settings['username']);

        $redis = ServerService::query()->where('key', 'redis')->firstOrFail();

        Livewire::test(ServerEdit::class, ['service' => $redis])
            ->set('domain', '127.0.0.1')
            ->set('password', 'local-secret')
            ->call('save')
            ->assertHasNoErrors();

        $redis->refresh();
        $this->assertStringContainsString('requirepass local-secret', $redis->config);
    }

    public function test_one_deploy_runs_at_a_time_and_finished_records_can_be_deleted(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));

        $website = Website::factory()->create();
        $running = $website->deployments()->create([
            'status' => 'running',
            'trigger' => 'manual',
            'log' => 'installing',
        ]);
        $done = $website->deployments()->create([
            'status' => 'success',
            'trigger' => 'manual',
            'log' => 'done',
        ]);

        Livewire::test(WebsiteShow::class, ['website' => $website])
            ->call('deploy')
            ->assertHasErrors('deploy');

        $this->assertSame(2, $website->deployments()->count());

        Livewire::test(WebsiteShow::class, ['website' => $website->fresh()])
            ->set('selectedDeployments', [(string) $running->id, (string) $done->id])
            ->set('confirmName', 'delete')
            ->call('deleteDeployments')
            ->assertHasErrors('deployments');

        $this->assertNotNull(Deployment::query()->find($running->id));

        Livewire::test(WebsiteShow::class, ['website' => $website->fresh()])
            ->call('stopDeploy');

        $this->assertSame('cancelled', $running->fresh()->status);

        Livewire::test(WebsiteShow::class, ['website' => $website->fresh()])
            ->set('selectedDeployments', [(string) $done->id])
            ->set('confirmName', 'delete')
            ->call('deleteDeployments')
            ->assertHasNoErrors();

        $this->assertNull(Deployment::query()->find($done->id));
        $this->assertNotNull(Deployment::query()->find($running->id));
    }

    public function test_the_website_page_keeps_the_tab_and_warns_about_unsaved_edits(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));

        $website = Website::factory()->create();

        Livewire::test(WebsiteShow::class, ['website' => $website])
            ->assertSee("setTab('environment')", false)
            ->assertSee('markDirty', false);

        $this->get(route('websites.show', $website).'?tab=environment')
            ->assertOk()
            ->assertSee('beforeunload', false)
            ->assertSee('You have unsaved changes. Leave this page?', false);
    }

    public function test_deployment_logs_are_plain_text_and_hide_tokens(): void
    {
        $website = Website::factory()->create();
        $deployment = $website->deployments()->create([
            'status' => 'running',
            'trigger' => 'manual',
            'log' => '',
        ]);

        $logger = new DeploymentLogger($deployment);
        $logger->line("\e[32;1mDONE\e[39;22m laravel/tinker ............ DONE");
        $logger->line('git clone https://x-access-token:ghs_secretvalue@github.com/FFKRISTAL-AZ/chat-app-widget-laravel.git');

        $deployment->refresh();
        $this->assertStringContainsString('laravel/tinker ... DONE', $deployment->log);
        $this->assertStringNotContainsString('[32', $deployment->log);
        $this->assertStringContainsString('x-access-token:***@github.com', $deployment->log);
        $this->assertStringNotContainsString('ghs_secretvalue', $deployment->log);
    }

    public function test_database_provision_skips_when_host_commands_are_disabled(): void
    {
        $website = Website::factory()->create([
            'db_name' => 'lv_chat',
            'db_username' => 'lv_chat_u',
            'db_password' => 'secret',
        ]);

        $this->assertSame(
            '[skipped] host commands are disabled',
            app(DatabaseProvisioner::class)->create($website),
        );
    }

    public function test_webhook_signature_helper_rejects_a_tampered_body(): void
    {
        $webhook = app(GithubWebhook::class);

        $this->assertTrue($webhook->valid('secret', 'body', 'sha256='.hash_hmac('sha256', 'body', 'secret')));
        $this->assertFalse($webhook->valid('secret', 'body', 'sha256='.hash_hmac('sha256', 'other', 'secret')));
    }

    /**
     * @return array<string, string>
     */
    private function signatureHeaders(string $body, string $secret): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ];
    }
}
