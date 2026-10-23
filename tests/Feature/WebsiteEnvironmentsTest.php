<?php

namespace Tests\Feature;

use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Websites\Environments;
use App\Models\GithubApp;
use App\Models\Group;
use App\Models\User;
use App\Models\Website;
use App\Services\CloudflareApi;
use App\Services\DatabaseProvisioner;
use App\Services\DomainSuggestions;
use App\Services\GithubAppClient;
use App\Services\HostWriter;
use App\Services\PortAllocator;
use App\Services\TwoFactor;
use App\Services\WebsiteEnvironments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class WebsiteEnvironmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->instance(HostWriter::class, Mockery::mock(HostWriter::class)->shouldIgnoreMissing(''));
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
    }

    public function test_an_environment_deploys_another_branch_with_the_main_sites_settings(): void
    {
        $main = $this->mainWebsite();

        $result = app(WebsiteEnvironments::class)->create($main, ['environment' => 'Development', 'branch' => 'develop', 'http_port' => '']);
        $environment = $result['website']->fresh();

        $this->assertSame([$main->id, 'development', 'develop', 'nextjs', '22', 'APP_NAME=Shop'], [
            $environment->parent_id, $environment->environment, $environment->branch, $environment->type, $environment->node_version, strtok((string) $environment->env_content, '
'),
        ]);
        $this->assertNotNull($environment->http_port);
        $this->assertSame('environment', $environment->deployments()->sole()->trigger);
        $this->assertSame(['production', 'development'], [$main->environmentName(), $environment->environmentName()]);
    }

    public function test_a_new_branch_is_made_on_github_first_and_names_must_be_unique(): void
    {
        $main = $this->mainWebsite();
        $main->githubApp()->associate(GithubApp::query()->create([
            'name' => 'otter', 'slug' => 'otter', 'html_url' => 'https://github.com', 'api_url' => 'https://api.github.com', 'git_user' => 'git', 'git_port' => 22,
            'app_id' => '1', 'installation_id' => '2', 'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----",
        ]))->save();
        $github = $this->mock(GithubAppClient::class);
        $github->shouldReceive('createBranch')->once()->with(Mockery::any(), 'acme/shop', 'staging', 'main');

        app(WebsiteEnvironments::class)->create($main, ['environment' => 'staging', 'branch' => 'staging', 'from' => 'main']);

        $this->expectException(ValidationException::class);
        app(WebsiteEnvironments::class)->create($main, ['environment' => 'staging', 'branch' => 'other']);
    }

    public function test_the_website_page_and_the_group_list_show_environments_together(): void
    {
        $main = $this->mainWebsite();
        app(WebsiteEnvironments::class)->create($main, ['environment' => 'development', 'branch' => 'develop']);
        Website::factory()->create(['group_id' => $main->group_id, 'name' => 'Another']);

        Livewire::test(Environments::class, ['website' => $main])
            ->assertSee('production')
            ->assertSee('development')
            ->assertSee('Add environment');

        Livewire::test(GroupShow::class, ['group' => $main->group])
            ->assertSeeInOrder(['Another', 'Shop', 'Shop (development)', 'development · develop']);
    }

    public function test_the_domain_helper_marks_names_in_use_and_makes_free_ones(): void
    {
        $cloudflare = Mockery::mock(CloudflareApi::class);
        $cloudflare->shouldReceive('configured')->andReturn(true);
        $cloudflare->shouldReceive('zones')->andReturn([['id' => 'z1', 'name' => 'example.com']]);
        $cloudflare->shouldReceive('hostnames')->with('z1')->andReturn([
            ['name' => 'api.example.com', 'type' => 'CNAME', 'content' => 'tunnel.cfargotunnel.com'],
            ['name' => 'shop.example.com', 'type' => 'CNAME', 'content' => 'tunnel.cfargotunnel.com'],
        ]);
        $this->app->instance(CloudflareApi::class, $cloudflare);
        Website::factory()->create(['name' => 'Shop', 'domain' => 'shop.example.com']);

        $options = app(DomainSuggestions::class)->options(fresh: true);

        $this->assertSame([null, 'Shop'], array_column($options['zones'][0]['records'], 'website'));
        $this->assertMatchesRegularExpression('/^[a-z]+-[a-z]+-[0-9a-f]{4}\.example\.com$/', app(DomainSuggestions::class)->random('example.com'));
    }

    public function test_the_dialog_makes_a_random_subdomain_on_the_main_sites_zone(): void
    {
        $domains = Mockery::mock(DomainSuggestions::class);
        $domains->shouldReceive('options')->andReturn(['configured' => true, 'error' => null, 'zones' => [['name' => 'other.com', 'records' => []], ['name' => 'example.com', 'records' => []]]]);
        $domains->shouldReceive('random')->with('example.com')->andReturn('calm-river-4f2a.example.com');
        $this->app->instance(DomainSuggestions::class, $domains);
        $main = $this->mainWebsite();
        $main->update(['domain' => 'shop.example.com']);

        Livewire::test(Environments::class, ['website' => $main])
            ->set('dialog', true)
            ->set('access', 'domain')
            ->assertSeeInOrder(['Random free subdomain on', 'example.com', 'other.com'])
            ->call('randomDomain', 'example.com')
            ->assertSet('domain', 'development-calm-river-4f2a.example.com')
            ->call('randomDomain', 'not-mine.com')
            ->assertSet('domain', 'development-calm-river-4f2a.example.com');
    }

    public function test_sites_whose_slugs_start_the_same_get_their_own_database_and_user(): void
    {
        $databases = app(DatabaseProvisioner::class);
        $main = $databases->credentials('chat-app-widget-laravel');
        Website::factory()->create(['slug' => 'chat-app-widget-laravel', 'db_name' => $main['db_name'], 'db_username' => $main['db_username']]);
        $development = $databases->credentials('chat-app-widget-laravel-development');

        $this->assertNotSame($main['db_name'], $development['db_name']);
        $this->assertNotSame($main['db_username'], $development['db_username']);
        $this->assertLessThanOrEqual(23, strlen($development['db_name']));
        $this->assertLessThanOrEqual(18, strlen($development['db_username']));
        $this->assertSame(['lv_shop', 'lv_shop_u'], [$databases->credentials('shop')['db_name'], $databases->credentials('shop')['db_username']]);
    }

    public function test_an_environment_gets_its_own_redis_prefixes_and_address_in_its_env(): void
    {
        $main = $this->mainWebsite();
        $main->update(['type' => 'laravel', 'env_content' => "APP_NAME=Shop\nAPP_URL=https://shop.test\nREDIS_PREFIX=shop_database_"]);

        $environment = app(WebsiteEnvironments::class)->create($main, ['environment' => 'development', 'branch' => 'develop', 'domain' => 'dev.shop.test'])['website']->fresh();

        $this->assertStringContainsString('APP_URL=https://dev.shop.test', $environment->env_content);
        $this->assertStringContainsString('REDIS_PREFIX='.str_replace('-', '_', $environment->slug).'_database_', $environment->env_content);
        $this->assertStringContainsString('CACHE_PREFIX='.str_replace('-', '_', $environment->slug).'_cache_', $environment->env_content);
        $this->assertStringNotContainsString('REDIS_PREFIX=shop_database_', $environment->env_content);
    }

    public function test_a_random_port_is_free(): void
    {
        Website::factory()->create(['http_port' => 8100]);

        $port = app(PortAllocator::class)->random(8100, 8101);

        $this->assertSame(8101, $port);
    }

    private function mainWebsite(): Website
    {
        return Website::factory()->create([
            'group_id' => Group::factory()->create()->id, 'name' => 'Shop', 'type' => 'nextjs', 'node_version' => '22',
            'repository' => 'https://github.com/acme/shop.git', 'branch' => 'main', 'env_content' => 'APP_NAME=Shop', 'enabled' => true,
        ]);
    }
}
