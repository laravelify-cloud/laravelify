<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show as WebsiteShow;
use App\Mcp\Servers\LaravelifyServer;
use App\Mcp\Tools\SetRedirects;
use App\Models\CloudflareTunnel;
use App\Models\User;
use App\Models\Website;
use App\Services\SiteSecurity;
use App\Services\TwoFactor;
use App\Services\WebsiteDomains;
use App\Services\WebsiteRedirects;
use App\Support\Redirects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteRedirectsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        return tap(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]), fn (User $user) => $this->actingAs($user));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(array $attributes = []): Website
    {
        return Website::factory()->create([
            'domain' => 'supercell.apply.coupons',
            'http_port' => 8123,
            'nginx_config' => "server {\n    listen 8123;\n    server_name supercell.apply.coupons;\n    location / { return 200; }\n}\n",
            ...$attributes,
        ]);
    }

    public function test_rows_are_cleaned_and_checked(): void
    {
        $rules = Redirects::normalize([
            ['from' => ' HTTPS://Apply.Coupons/path ', 'to' => ''],
            ['from' => '/old', 'to' => '/new', 'code' => 999, 'match' => 'prefix'],
            ['from' => '', 'to' => ''],
        ]);

        $this->assertSame('apply.coupons', $rules[0]['from']);
        $this->assertSame(301, $rules[1]['code']);
        $this->assertCount(2, $rules);
        $this->assertSame([], Redirects::errors($rules, ['supercell.apply.coupons'], [], 'supercell.apply.coupons'));

        $bad = Redirects::normalize([
            ['from' => 'supercell.apply.coupons'],
            ['from' => 'taken.com'],
            ['from' => '/x', 'to' => ''],
            ['from' => 'a.com', 'to' => 'https://b.com'],
            ['from' => 'b.com', 'to' => 'https://c.com'],
            ['from' => '/y z', 'to' => '/q'],
        ]);
        $errors = Redirects::errors($bad, ['supercell.apply.coupons'], ['taken.com'], 'supercell.apply.coupons');

        $this->assertSame([1, 2, 3, 4, 6], array_keys($errors));
        $this->assertStringContainsString('own domains', $errors[1]);
        $this->assertStringContainsString('another website', $errors[2]);
        $this->assertStringContainsString('redirects again', $errors[4]);
    }

    public function test_the_redirects_tab_writes_domain_and_page_redirects_into_nginx_and_the_tunnel(): void
    {
        $this->signIn();
        $tunnel = CloudflareTunnel::factory()->create();
        $website = $this->site(['cloudflare_tunnel_id' => $tunnel->id]);

        Livewire::test(WebsiteShow::class, ['website' => $website])
            ->call('addRedirect', 'domain')
            ->set('redirect_rows.0.from', 'apply.coupons')
            ->call('addRedirect', 'domain')
            ->set('redirect_rows.1.from', 'www.apply.coupons')
            ->call('addRedirect', 'path')
            ->set('redirect_rows.2.from', '/blog/')
            ->set('redirect_rows.2.to', 'https://example.com/blog')
            ->set('redirect_rows.2.match', 'prefix')
            ->call('saveRedirects')
            ->assertHasNoErrors();

        $nginx = (string) $website->fresh()->nginx_config;

        $this->assertStringContainsString("server_name apply.coupons www.apply.coupons;\n    return 301 https://supercell.apply.coupons\$request_uri;", $nginx);
        $this->assertStringContainsString('location ^~ /blog/ { return 301 https://example.com/blog; }', $nginx);
        $this->assertStringContainsString('location / { return 200; }', $nginx);
        $this->assertSame(1, substr_count($nginx, 'laravelify-redirects:start'));

        $ingress = (string) $tunnel->fresh()->ingress;
        $this->assertStringContainsString('hostname: apply.coupons', $ingress);
        $this->assertStringContainsString('hostname: www.apply.coupons', $ingress);
    }

    public function test_saving_again_replaces_the_managed_blocks_and_an_empty_list_removes_them(): void
    {
        $website = $this->site();
        $service = app(WebsiteRedirects::class);

        $service->apply($website, [['from' => 'a.com', 'to' => 'https://b.com'], ['from' => '/p', 'to' => '/q']]);
        $service->apply($website->fresh(), [['from' => 'c.com', 'to' => 'https://b.com']]);

        $nginx = (string) $website->fresh()->nginx_config;
        $this->assertStringContainsString('server_name c.com;', $nginx);
        $this->assertStringNotContainsString('a.com', $nginx);
        $this->assertStringNotContainsString('location = /p', $nginx);

        $service->apply($website->fresh(), []);

        $this->assertStringNotContainsString('laravelify-redirects', (string) $website->fresh()->nginx_config);
        $this->assertStringNotContainsString('c.com', (string) $website->fresh()->nginx_config);
        $this->assertNull($website->fresh()->redirects);
    }

    public function test_a_wrong_rule_changes_nothing(): void
    {
        $this->signIn();
        $website = $this->site();
        $before = $website->nginx_config;

        Livewire::test(WebsiteShow::class, ['website' => $website])
            ->call('addRedirect', 'domain')
            ->set('redirect_rows.0.from', 'supercell.apply.coupons')
            ->call('saveRedirects')
            ->assertHasErrors(['redirects.0']);

        $this->assertSame($before, $website->fresh()->nginx_config);
    }

    public function test_redirect_domains_cannot_be_taken_as_another_websites_domain(): void
    {
        $this->site(['redirects' => [['from' => 'old.com', 'to' => '', 'code' => 301, 'keep_path' => true, 'match' => 'exact']]]);
        $other = Website::factory()->create(['domain' => 'other.com']);

        $this->expectException(ValidationException::class);
        app(WebsiteDomains::class)->validate($other, 'other.com', ['old.com']);
    }

    public function test_the_security_include_skips_the_redirect_servers(): void
    {
        $website = $this->site();
        app(WebsiteRedirects::class)->apply($website, [['from' => 'a.com', 'to' => 'https://b.com']]);
        $website = $website->fresh();

        app(SiteSecurity::class)->ensureInclude($website);

        $this->assertSame(1, substr_count((string) $website->nginx_config, 'laravelify-security/'.$website->slug.'.conf'));
        $this->assertStringContainsString('laravelify-redirects:start', (string) $website->nginx_config);
    }

    public function test_the_mcp_tool_sets_redirects(): void
    {
        $user = $this->signIn();
        $website = $this->site();

        LaravelifyServer::actingAs($user)
            ->tool(SetRedirects::class, ['website' => 'supercell.apply.coupons', 'redirects' => [['from' => 'apply.coupons', 'to' => '']]])
            ->assertOk()
            ->assertSee('apply.coupons');

        $this->assertStringContainsString('server_name apply.coupons;', (string) $website->fresh()->nginx_config);

        LaravelifyServer::actingAs($user)
            ->tool(SetRedirects::class, ['website' => 'supercell.apply.coupons', 'redirects' => [['from' => 'nope', 'to' => '']]])
            ->assertHasErrors(['is not a domain']);
    }
}
