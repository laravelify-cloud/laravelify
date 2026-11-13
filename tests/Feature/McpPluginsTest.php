<?php

namespace Tests\Feature;

use App\Jobs\RunPanelTask;
use App\Mcp\Servers\LaravelifyServer;
use App\Mcp\Tools\InstallPlugin;
use App\Mcp\Tools\LinkPlugin;
use App\Mcp\Tools\ListPlugins;
use App\Mcp\Tools\PluginStatus;
use App\Mcp\Tools\UnsealVault;
use App\Models\PanelTask;
use App\Models\Plugin;
use App\Models\User;
use App\Models\Website;
use App\Services\HostWriter;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class McpPluginsTest extends TestCase
{
    use RefreshDatabase;

    private function server(): PendingTestResponse
    {
        return LaravelifyServer::actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    private function host(): MockInterface
    {
        $host = Mockery::mock(HostWriter::class);
        $host->shouldReceive('writeWebsite')->byDefault()->andReturn('');
        $host->shouldReceive('pluginStatus')->byDefault()->andReturn('running');
        $this->app->instance(HostWriter::class, $host);

        return $host;
    }

    public function test_list_and_install_run_the_install_in_the_background(): void
    {
        Queue::fake();
        $server = $this->server();

        $server->tool(ListPlugins::class)->assertOk()->assertSee(['postgres', 'vault', 'not installed']);
        $server->tool(InstallPlugin::class, ['plugin' => 'postgres'])->assertOk()->assertSee('get-task');
        $server->tool(InstallPlugin::class, ['plugin' => 'mongo'])->assertHasErrors(['No such plugin']);

        $this->assertSame(['plugins.install', 'plugin:postgres'], [PanelTask::query()->sole()->kind, PanelTask::query()->sole()->scope]);
        Queue::assertPushed(RunPanelTask::class);
    }

    public function test_status_and_link_never_show_the_password(): void
    {
        $this->host();
        Plugin::factory()->create();
        Website::factory()->create(['slug' => 'api', 'env_content' => "APP_NAME=Api\n"]);
        $server = $this->server();

        $server->tool(PluginStatus::class, ['plugin' => 'redis'])->assertOk()->assertSee(['running', '127.0.0.1:6380'])->assertDontSee('secretredispassword');
        $server->tool(LinkPlugin::class, ['website' => 'api', 'plugin' => 'redis'])->assertOk()->assertSee('REDIS_URL')->assertDontSee('secretredispassword');

        $this->assertStringContainsString('REDIS_PASSWORD=secretredispassword', (string) Website::query()->sole()->env_content);

        $server->tool(LinkPlugin::class, ['website' => 'api', 'plugin' => 'redis', 'unlink' => true])->assertOk();
        $this->assertStringNotContainsString('REDIS_', (string) Website::query()->sole()->env_content);
    }

    public function test_unseal_vault_uses_the_saved_keys(): void
    {
        $host = $this->host();
        Plugin::factory()->create(['key' => 'vault', 'ports' => ['vault' => 8200], 'sealed' => true, 'credentials' => ['unseal_keys' => ['k1', 'k2', 'k3', 'k4', 'k5'], 'root_token' => 'hvs.root']]);
        $host->shouldReceive('vaultUnseal')->once()->with('vault', ['k1', 'k2', 'k3'])->andReturn('');
        $host->shouldReceive('vaultSealed')->andReturn(false);

        $this->server()->tool(UnsealVault::class)->assertOk()->assertSee('Vault is unsealed')->assertDontSee(['k1', 'hvs.root']);
    }
}
