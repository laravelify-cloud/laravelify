<?php

namespace Tests\Feature;

use App\Livewire\Settings\Mcp;
use App\Mcp\Servers\LaravelifyServer;
use App\Mcp\Tools\CreateWebsite;
use App\Mcp\Tools\DeployWebsite;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetWebsite;
use App\Mcp\Tools\ListWebsites;
use App\Mcp\Tools\UpdateEnvironment;
use App\Models\Deployment;
use App\Models\Group;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Website;
use App\Services\AccessGate;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->admin = User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function rpc(array $body, array $headers = []): TestResponse
    {
        return $this->postJson('/mcp', $body, $headers + ['Accept' => 'application/json, text/event-stream']);
    }

    public function test_the_endpoint_needs_a_valid_token(): void
    {
        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]];

        $this->rpc($initialize)->assertUnauthorized();
        $this->assertStringStartsWith('Bearer', (string) $this->rpc($initialize)->headers->get('WWW-Authenticate'));
        $this->rpc($initialize, ['Authorization' => 'Bearer lvf_wrong'])->assertUnauthorized();

        $plain = McpToken::issue($this->admin, 'laptop')['plain'];

        $this->rpc($initialize, ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Laravelify')
            ->assertSee('create-website', false);

        $this->assertNotNull(McpToken::query()->sole()->last_used_at);
        $this->assertStringStartsWith('lvf_', $plain);
        $this->assertNotSame($plain, McpToken::query()->sole()->token_hash, 'Only a hash is stored.');
    }

    public function test_the_access_prompt_does_not_block_mcp_clients(): void
    {
        app(AccessGate::class)->saveSettings('panel', ['enabled' => true, 'method' => AccessGate::METHOD_CODE, 'username' => '', 'password_hash' => '']);
        $plain = McpToken::issue($this->admin, 'laptop')['plain'];

        $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$plain])->assertOk();
    }

    public function test_create_website_sets_it_up_writes_env_and_deploys(): void
    {
        LaravelifyServer::actingAs($this->admin)->tool(CreateWebsite::class, [
            'group' => 'Acme',
            'name' => 'Shop',
            'type' => 'laravel',
            'repository' => 'acme/shop',
            'branch' => 'main',
            'domain' => 'shop.example.com',
            'env' => ['MAIL_HOST=smtp.example.com', 'APP_NAME=Acme Shop'],
        ])->assertOk()->assertSee('"deployment_id"')->assertSee('shop.example.com');

        $website = Website::query()->sole();
        $this->assertSame('Acme', $website->group->name);
        $this->assertSame('https://github.com/acme/shop.git', $website->repository);
        $this->assertStringContainsString('MAIL_HOST=smtp.example.com', (string) $website->env_content);
        $this->assertStringContainsString("APP_NAME='Acme Shop'", (string) $website->env_content);
        $this->assertSame('mcp', Deployment::query()->sole()->trigger);
    }

    public function test_create_website_explains_what_is_wrong(): void
    {
        Group::factory()->create(['name' => 'Acme']);

        LaravelifyServer::actingAs($this->admin)->tool(CreateWebsite::class, [
            'group' => 'Acme', 'name' => 'Shop', 'type' => 'not-a-type', 'repository' => 'acme/shop',
        ])->assertHasErrors();

        LaravelifyServer::actingAs($this->admin)->tool(CreateWebsite::class, [
            'group' => 'Acme', 'name' => 'Shop', 'type' => 'laravel', 'repository' => 'acme/shop', 'env' => ['NOT A VARIABLE'],
        ])->assertHasErrors(['Write each variable as KEY=value']);

        $this->assertSame(0, Website::query()->count());
    }

    public function test_websites_are_listed_deployed_and_followed(): void
    {
        $website = Website::factory()->create(['slug' => 'shop', 'domain' => 'shop.example.com']);
        $server = LaravelifyServer::actingAs($this->admin);

        $server->tool(ListWebsites::class)->assertOk()->assertSee('shop.example.com');
        $server->tool(GetWebsite::class, ['website' => 'shop.example.com'])->assertOk()->assertSee('"env_keys"');
        $server->tool(GetWebsite::class, ['website' => 'nope'])->assertHasErrors(['No website "nope"']);

        $server->tool(DeployWebsite::class, ['website' => 'shop'])->assertOk()->assertSee('deployment_id');
        $server->tool(DeployWebsite::class, ['website' => 'shop'])->assertHasErrors(['A deployment is already running']);

        $deployment = Deployment::query()->sole();
        $deployment->update(['status' => 'failed', 'log' => "line one\nComposer could not find a lock file"]);

        $server->tool(GetDeployment::class, ['website' => (string) $website->id])
            ->assertOk()
            ->assertSee('Composer could not find a lock file')
            ->assertSee('"status":"failed"')
            ->assertSee('"finished":true');
    }

    public function test_environment_variables_are_set_and_removed_without_showing_values(): void
    {
        $website = Website::factory()->create(['slug' => 'shop', 'env_content' => "APP_KEY=base64:secret\nOLD=1\n"]);

        LaravelifyServer::actingAs($this->admin)->tool(UpdateEnvironment::class, [
            'website' => 'shop', 'set' => ['MAIL_PORT=587'], 'unset' => ['OLD'],
        ])->assertOk()->assertSee('MAIL_PORT')->assertDontSee('base64:secret');

        $this->assertSame("APP_KEY=base64:secret\nMAIL_PORT=587\n", $website->fresh()->env_content);
    }

    public function test_tokens_are_made_after_the_code_shown_once_and_revoked(): void
    {
        $this->actingAs($this->admin);

        $page = Livewire::test(Mcp::class)
            ->set('tokenName', 'Laptop')
            ->call('check')
            ->assertHasNoErrors()
            ->call('create', '000000')
            ->assertHasErrors('code');
        $this->assertSame(0, McpToken::query()->count());

        $page->call('create', app(TwoFactor::class)->currentCode($this->admin))->assertHasNoErrors();
        $plain = $page->get('newToken');

        $this->assertNotNull(McpToken::findByPlain($plain));
        $page->assertSee('claude mcp add --transport http laravelify')->assertSee($plain);

        $page->call('dismissToken')->assertDontSee($plain)->call('revoke', McpToken::query()->sole()->id);
        $this->assertSame(0, McpToken::query()->count());
    }
}
