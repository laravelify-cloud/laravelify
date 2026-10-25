<?php

namespace Tests\Feature;

use App\Models\CloudflareTunnel;
use App\Models\GithubApp;
use App\Models\Group;
use App\Models\Setting;
use App\Models\Website;
use App\Services\CloudflareApi;
use App\Services\CloudflareRoutes;
use App\Services\DomainSuggestions;
use App\Services\ExampleRepositories;
use App\Services\GithubAppClient;
use App\Services\GlitchtipSdk;
use App\Services\HostWriter;
use App\Services\PanelPreview;
use App\Services\ProjectWorkspace;
use App\Services\SiteUser;
use App\Services\WebsiteCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ProjectRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->storage = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-projects-'.uniqid();
        File::ensureDirectoryExists($this->storage);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_the_first_deploy_pushes_the_example_to_the_new_repository_once(): void
    {
        $remote = $this->bareRepository();
        $website = Website::factory()->create(['type' => 'express', 'slug' => 'shop', 'branch' => 'main', 'repository' => $remote, 'seed_example' => true]);
        $site = app(SiteUser::class)->for($website);

        $this->assertSame('Pushed the express example to '.$remote.'.', app(ExampleRepositories::class)->seed($website, $site, $remote));

        $this->assertFalse($website->fresh()->seed_example);
        $this->assertSame('Example project', trim(Process::run(['git', '-C', $remote, 'log', '-1', '--format=%s', 'main'])->output()));
        $this->assertNull(app(ExampleRepositories::class)->seed($website->fresh(), $site, $remote));
    }

    public function test_vs_code_clones_an_example_project_and_its_pushes_deploy(): void
    {
        $website = Website::factory()->create(['type' => 'static', 'slug' => 'blog', 'branch' => 'main', 'repository' => '', 'seed_example' => false]);
        $site = app(SiteUser::class)->for($website);
        $website->update(['repository' => $site->home().'/examples/blog']);
        app(ExampleRepositories::class)->ensure($website, $site);
        $workspace = app(ProjectWorkspace::class);

        $workspace->prepare($website->fresh());

        $repository = $website->fresh()->repository;
        $hook = File::get($repository.'/.git/hooks/post-receive');
        $this->assertDirectoryExists($workspace->path($website).'/.git');
        $this->assertSame('updateInstead', trim(Process::run(['git', '-C', $repository, 'config', 'receive.denyCurrentBranch'])->output()));
        $this->assertStringContainsString('refs/heads/main', $hook);
        $this->assertStringContainsString('/hooks/github/blog', $hook);
        $this->assertStringContainsString("-hmac '".$website->webhook_secret."'", $hook);
    }

    public function test_a_new_website_can_start_from_a_new_repository_in_the_apps_organization(): void
    {
        $this->app->instance(HostWriter::class, Mockery::mock(HostWriter::class)->shouldIgnoreMissing(''));
        $this->withoutAppSigning();
        Http::fake([
            'api.github.com/app/installations/456/access_tokens' => Http::response(['token' => 'ghs_secret']),
            'api.github.com/app/installations/456' => Http::response(['account' => ['login' => 'acme', 'type' => 'Organization'], 'permissions' => ['administration' => 'write', 'contents' => 'write']]),
            'api.github.com/orgs/acme/repos' => Http::response(['full_name' => 'acme/landing', 'clone_url' => 'https://github.com/acme/landing.git'], 201),
        ]);

        $result = app(WebsiteCreator::class)->create(Group::factory()->create(), [
            'name' => 'Landing', 'type' => 'nextjs', 'http_port' => '8090', 'create_repository' => true,
            'new_repository_name' => 'landing', 'github_app_id' => (string) $this->githubApp()->id,
        ]);

        $website = $result['website'];
        $this->assertSame(['https://github.com/acme/landing.git', 'main', true], [$website->repository, $website->branch, $website->seed_example]);
        $this->assertSame('create', $website->deployments()->sole()->trigger);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/orgs/acme/repos' && $request['name'] === 'landing' && $request['private'] === true);
    }

    public function test_a_personal_account_app_cannot_create_repositories(): void
    {
        $this->withoutAppSigning();
        Http::fake(['api.github.com/app/installations/456' => Http::response(['account' => ['login' => 'ada', 'type' => 'User'], 'permissions' => []])]);

        $this->expectException(ValidationException::class);

        app(WebsiteCreator::class)->create(Group::factory()->create(), [
            'name' => 'Landing', 'type' => 'nextjs', 'create_repository' => true, 'github_app_id' => (string) $this->githubApp()->id,
        ]);
    }

    public function test_the_preview_opens_the_site_at_a_random_panel_path(): void
    {
        config(['app.url' => 'https://panel.test']);
        $host = Mockery::mock(HostWriter::class);
        $host->shouldReceive('writePanelPreview')->once()->withArgs(fn ($site, $token, $port, $name) => strlen($token) === 32 && $port === 80 && $name === 'shop.test')->andReturn('');
        $this->app->instance(HostWriter::class, $host);
        $website = Website::factory()->create(['domain' => 'shop.test', 'http_port' => null]);

        $url = app(PanelPreview::class)->enable($website);

        $this->assertSame('https://panel.test/p/'.$website->fresh()->preview_token.'/', $url);
    }

    public function test_the_preview_gets_a_random_subdomain_through_the_tunnel_when_cloudflare_is_set_up(): void
    {
        $tunnel = CloudflareTunnel::query()->create(['name' => 'Main', 'slug' => 'main', 'token' => 'token']);
        $cloudflare = Mockery::mock(CloudflareApi::class);
        $cloudflare->shouldReceive('configured')->andReturn(true);
        $this->app->instance(CloudflareApi::class, $cloudflare);
        $domains = Mockery::mock(DomainSuggestions::class);
        $domains->shouldReceive('options')->andReturn(['configured' => true, 'error' => null, 'zones' => [['name' => 'example.com', 'records' => []]]]);
        $domains->shouldReceive('random')->with('example.com')->andReturn('calm-river-4f2a.example.com');
        $this->app->instance(DomainSuggestions::class, $domains);
        $routes = Mockery::mock(CloudflareRoutes::class);
        $routes->shouldReceive('route')->once()->withArgs(fn ($used, $host, $service, $header, $remove = false) => $used->is($tunnel) && $host === 'calm-river-4f2a.example.com' && $service === 'http://127.0.0.1:80' && $header === 'shop.example.com' && ! $remove)
            ->andReturn(['configured' => true, 'lines' => [['host' => 'calm-river-4f2a.example.com', 'route' => 'added', 'dns' => 'created', 'ok' => true]], 'note' => null]);
        $routes->shouldReceive('route')->once()->withArgs(fn ($used, $host, $service, $header, $remove = false) => $remove === true)
            ->andReturn(['configured' => true, 'lines' => [], 'note' => null]);
        $this->app->instance(CloudflareRoutes::class, $routes);
        $website = Website::factory()->create(['domain' => 'shop.example.com', 'http_port' => null]);

        $previews = app(PanelPreview::class);

        $this->assertSame('https://calm-river-4f2a.example.com/', $previews->enable($website));
        $this->assertSame(['subdomain', $tunnel->id], [$previews->mode($website->fresh()), $website->fresh()->preview_tunnel_id]);

        $previews->disable($website->fresh());
        $this->assertNull($previews->mode($website->fresh()));
    }

    public function test_a_new_github_app_is_offered_the_known_organizations(): void
    {
        $this->githubApp()->update(['organization' => 'acme']);
        Setting::putValue('github_token', 'ghp_test');
        Http::fake(['api.github.com/user/orgs*' => Http::response([['login' => 'Zeta-Org'], ['login' => 'acme'], ['login' => 'beta']])]);

        $this->assertSame(['acme', 'beta', 'Zeta-Org'], GithubAppClient::knownOrganizations());
    }

    public function test_the_sdk_setup_pushes_with_an_app_that_may_write(): void
    {
        $this->withoutAppSigning();
        $readOnly = $this->githubApp();
        $writer = GithubApp::query()->create([...$readOnly->only(['html_url', 'api_url', 'git_user', 'git_port', 'private_key', 'webhook_secret']), 'name' => 'brave-wolf', 'slug' => 'brave-wolf', 'app_id' => '124', 'installation_id' => '789']);
        $website = Website::factory()->create(['repository' => 'https://github.com/acme/shop.git', 'github_app_id' => $readOnly->id]);
        Http::fake([
            'api.github.com/app/installations/456/access_tokens' => Http::response(['token' => 'ghs_read']),
            'api.github.com/app/installations/456' => Http::response(['account' => ['login' => 'acme', 'type' => 'Organization'], 'permissions' => ['contents' => 'read']]),
            'api.github.com/app/installations/789/access_tokens' => Http::response(['token' => 'ghs_write']),
            'api.github.com/app/installations/789' => Http::response(['account' => ['login' => 'acme', 'type' => 'Organization'], 'permissions' => ['contents' => 'write']]),
        ]);

        $pushUrl = (fn () => $this->pushUrl($website))->call(app(GlitchtipSdk::class));

        $this->assertSame('https://x-access-token:ghs_write@github.com/acme/shop.git', $pushUrl);
        $this->assertTrue($writer->isConnected());
    }

    private function githubApp(): GithubApp
    {
        return GithubApp::query()->create([
            'name' => 'swift-otter', 'slug' => 'swift-otter', 'html_url' => 'https://github.com', 'api_url' => 'https://api.github.com',
            'git_user' => 'git', 'git_port' => 22, 'app_id' => '123', 'installation_id' => '456', 'webhook_secret' => 'hook-secret',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----",
        ]);
    }

    /**
     * GitHub is faked, so the app's signed JWT is not needed (and OpenSSL may have no config here).
     */
    private function withoutAppSigning(): void
    {
        $this->app->instance(GithubAppClient::class, new class extends GithubAppClient
        {
            protected function jwt(GithubApp $app): string
            {
                return 'test-jwt';
            }
        });
    }

    private function bareRepository(): string
    {
        $path = $this->storage.'/remote-'.uniqid().'.git';
        Process::run(['git', 'init', '-q', '--bare', '-b', 'main', $path])->throw();

        return $path;
    }
}
