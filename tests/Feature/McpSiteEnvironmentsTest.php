<?php

namespace Tests\Feature;

use App\Mcp\Servers\LaravelifyServer;
use App\Mcp\Tools\CreateSiteEnvironment;
use App\Mcp\Tools\DeleteSiteEnvironment;
use App\Mcp\Tools\DeployWebsite;
use App\Mcp\Tools\ListSiteEnvironments;
use App\Mcp\Tools\RunCommand;
use App\Mcp\Tools\UpdateEnvironment;
use App\Mcp\Tools\UpdateSiteEnvironment;
use App\Models\Deployment;
use App\Models\GithubApp;
use App\Models\Group;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\HostWriter;
use App\Services\SiteCommand;
use App\Services\TwoFactor;
use App\Services\WebsiteSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class McpSiteEnvironmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(HostWriter::class, Mockery::mock(HostWriter::class)->shouldIgnoreMissing(''));
        $this->admin = User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]);
    }

    private function server(): PendingTestResponse
    {
        return LaravelifyServer::actingAs($this->admin);
    }

    private function shop(array $attributes = []): Website
    {
        return Website::factory()->create([
            'group_id' => Group::factory()->create()->id, 'name' => 'Shop', 'slug' => 'shop', 'type' => 'laravel', 'php_version' => '8.4',
            'repository' => 'https://github.com/acme/shop.git', 'branch' => 'main', 'env_content' => "APP_NAME=Shop\nAPP_URL=https://shop.example.com", 'enabled' => true,
            'domain' => 'shop.example.com', 'http_port' => 8101,
        ] + $attributes);
    }

    public function test_an_environment_gets_its_own_database_port_and_domain(): void
    {
        $main = $this->shop(['provision_database' => true, 'db_engine' => 'sqlite', 'db_name' => 'shop']);

        $this->server()->tool(CreateSiteEnvironment::class, [
            'website' => 'shop', 'name' => 'Development', 'branch' => 'dev', 'domain' => 'dev.shop.example.com',
            'create_database' => true, 'database_engine' => 'sqlite', 'env' => ['APP_DEBUG=true'],
        ])->assertOk()->assertSee('deployment_id')->assertSee('dev.shop.example.com');

        $environment = $main->environments()->sole();

        $this->assertSame(['development', 'dev', 'dev.shop.example.com'], [$environment->environment, $environment->branch, $environment->domain]);
        $this->assertTrue((bool) $environment->provision_database);
        $this->assertNotSame($main->db_name, $environment->db_name);
        $this->assertNotSame($main->http_port, $environment->http_port);
        $this->assertNotSame($main->deploy_path, $environment->deploy_path);
        $this->assertStringContainsString('APP_DEBUG=true', (string) $environment->env_content);
        $this->assertStringContainsString('APP_URL=https://dev.shop.example.com', (string) $environment->env_content);
        $this->assertSame(1, $environment->deployments()->count());

        $this->server()->tool(ListSiteEnvironments::class, ['website' => 'shop'])->assertOk()->assertSee('"production"')->assertSee('"development"');
        $this->server()->tool(UpdateSiteEnvironment::class, ['website' => 'shop', 'environment' => 'development', 'name' => 'staging', 'branch' => 'staging'])->assertOk();
        $this->assertSame(['staging', 'staging'], [$environment->fresh()->environment, $environment->fresh()->branch]);
    }

    public function test_the_environment_parameter_picks_the_environment_on_per_site_tools(): void
    {
        $main = $this->shop();
        $dev = Website::factory()->create([
            'group_id' => $main->group_id, 'parent_id' => $main->id, 'environment' => 'development', 'slug' => 'shop-development',
            'repository' => $main->repository, 'branch' => 'dev', 'enabled' => true, 'env_content' => 'APP_ENV=local',
        ]);
        $this->mock(SiteCommand::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->withArgs(fn (Website $website): bool => $website->slug === 'shop-development')->andReturn(new CommandResult(true, false, ['php', 'artisan', 'about'], 'ok', 0));
        });
        $server = $this->server();

        $server->tool(DeployWebsite::class, ['website' => 'shop', 'environment' => 'development'])->assertOk();
        $this->assertSame([0, 1], [$main->deployments()->count(), $dev->deployments()->count()]);

        $server->tool(UpdateEnvironment::class, ['website' => 'shop', 'environment' => 'development', 'set' => ['APP_DEBUG=true']])->assertOk();
        $this->assertStringContainsString('APP_DEBUG=true', (string) $dev->fresh()->env_content);
        $this->assertStringNotContainsString('APP_DEBUG', (string) $main->fresh()->env_content);

        $server->tool(RunCommand::class, ['website' => 'shop', 'environment' => (string) $dev->id, 'command' => 'php artisan about'])->assertOk()->assertSee('shop-development');

        $server->tool(DeployWebsite::class, ['website' => 'shop', 'environment' => 'nope'])->assertHasErrors(['has no "nope" environment']);
    }

    public function test_a_push_to_the_dev_branch_deploys_only_the_dev_environment(): void
    {
        $app = GithubApp::query()->create([
            'name' => 'otter', 'slug' => 'otter', 'html_url' => 'https://github.com', 'api_url' => 'https://api.github.com', 'git_user' => 'git', 'git_port' => 22,
            'app_id' => '1', 'installation_id' => '2', 'webhook_secret' => 'hook-secret', 'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----",
        ]);
        $main = $this->shop(['github_app_id' => $app->id]);
        $dev = Website::factory()->create([
            'group_id' => $main->group_id, 'parent_id' => $main->id, 'environment' => 'development', 'repository' => $main->repository,
            'branch' => 'dev', 'enabled' => true, 'github_app_id' => $app->id,
        ]);
        $body = (string) json_encode(['ref' => 'refs/heads/dev', 'after' => str_repeat('a', 40), 'repository' => ['full_name' => 'acme/shop']]);

        $this->call('POST', route('hooks.github-app', $app), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
        ], $body)->assertOk();

        $this->assertSame([$dev->id], Deployment::query()->pluck('website_id')->all());
    }

    public function test_production_the_last_environment_is_not_deleted_and_others_are(): void
    {
        $main = $this->shop();
        $dev = Website::factory()->create(['group_id' => $main->group_id, 'parent_id' => $main->id, 'environment' => 'development', 'branch' => 'dev']);
        $this->mock(WebsiteSwitch::class, function (MockInterface $mock) use ($dev): void {
            $mock->shouldReceive('delete')->once()->withArgs(fn (Website $website): bool => $website->is($dev));
        });

        $this->server()->tool(DeleteSiteEnvironment::class, ['website' => 'shop', 'environment' => 'production'])->assertHasErrors(['last environment']);
        $this->server()->tool(DeleteSiteEnvironment::class, ['website' => 'shop', 'environment' => 'development'])->assertOk()->assertSee('"deleted":"development"');
    }
}
