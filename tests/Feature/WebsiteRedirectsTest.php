<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show as WebsiteShow;
use App\Mcp\Servers\LaravelifyServer;
use App\Mcp\Tools\SetRedirects;
use App\Models\CloudflareTunnel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\CloudflareApi;
use App\Services\CloudflareRoutes;
use App\Services\SiteSecurity;
use App\Services\TwoFactor;
use App\Services\WebsiteDomains;
use App\Services\WebsiteRedirects;
use App\Support\Redirects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_a_redirect_served_on_the_server_ip_listens_on_port_80_and_gets_an_a_record_not_a_tunnel_route(): void
    {
        Setting::putValue(CloudflareApi::SETTING, 'cf_token_123');
        $created = [];

        Http::fake(function (Request $request) use (&$created) {
            $url = $request->url();
            $ok = fn (mixed $result) => Http::response(['success' => true, 'errors' => [], 'result' => $result]);

            return match (true) {
                str_contains($url, 'api.ipify.org') => Http::response('203.0.113.9'),
                str_contains($url, '/zones?name=apply.coupons') => $ok([['id' => 'zone1', 'name' => 'apply.coupons']]),
                str_contains($url, '/dns_records') && $request->method() === 'GET' => $ok([['id' => 'mx1', 'type' => 'MX', 'name' => 'apply.coupons', 'content' => 'mail.example.com']]),
                str_contains($url, '/dns_records') && $request->method() === 'POST' => tap($ok(['id' => 'new']), function () use ($request, &$created): void {
                    $created[] = $request->data();
                }),
                default => Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404),
            };
        });

        $tunnel = CloudflareTunnel::factory()->create();
        $website = $this->site(['cloudflare_tunnel_id' => $tunnel->id]);

        app(WebsiteRedirects::class)->apply($website, [['from' => 'apply.coupons', 'to' => '', 'via' => 'ip']]);

        $nginx = (string) $website->fresh()->nginx_config;
        $this->assertStringContainsString('listen 80;
    listen [::]:80;
    server_name apply.coupons;', $nginx);
        $this->assertStringNotContainsString('hostname: apply.coupons', (string) $tunnel->fresh()->ingress);
        $this->assertSame('A', $created[0]['type']);
        $this->assertSame('203.0.113.9', $created[0]['content']);
        $this->assertContains('apply.coupons', $website->fresh()->claimedHostnames());
    }

    public function test_only_a_bare_domain_gets_a_www_name(): void
    {
        $this->assertTrue(WebsiteDomains::isApex('example.com'));
        $this->assertTrue(WebsiteDomains::isApex('example.co.uk'));
        $this->assertTrue(WebsiteDomains::isApex('www.example.az'));
        $this->assertFalse(WebsiteDomains::isApex('supercell.apply.coupons'));
        $this->assertFalse(WebsiteDomains::isApex('api.example.com'));
    }

    public function test_a_cname_to_another_redirect_domain_on_the_server_ip_is_left_alone(): void
    {
        Setting::putValue(CloudflareApi::SETTING, 'cf_token_123');
        $posted = 0;

        Http::fake(function (Request $request) use (&$posted) {
            $url = $request->url();
            $ok = fn (mixed $result) => Http::response(['success' => true, 'errors' => [], 'result' => $result]);

            return match (true) {
                str_contains($url, 'api.ipify.org') => Http::response('203.0.113.9'),
                str_contains($url, '/zones?name=apply.coupons') => $ok([['id' => 'zone1', 'name' => 'apply.coupons']]),
                str_contains($url, 'name=www.apply.coupons') => $ok([['id' => 'c1', 'type' => 'CNAME', 'name' => 'www.apply.coupons', 'content' => 'apply.coupons']]),
                str_contains($url, '/dns_records') && $request->method() === 'GET' => $ok([['id' => 'a1', 'type' => 'A', 'name' => 'apply.coupons', 'content' => '203.0.113.9']]),
                str_contains($url, '/dns_records') && $request->method() === 'POST' => tap($ok(['id' => 'new']), function () use (&$posted): void {
                    $posted++;
                }),
                default => Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404),
            };
        });

        $report = app(WebsiteRedirects::class)->apply($this->site(), [
            ['from' => 'apply.coupons', 'to' => '', 'via' => 'ip'],
            ['from' => 'www.apply.coupons', 'to' => '', 'via' => 'ip'],
        ]);

        $this->assertSame([], CloudflareRoutes::problems($report));
        $this->assertSame(0, $posted);
    }

    public function test_regenerating_the_nginx_file_keeps_the_redirects(): void
    {
        $this->signIn();
        $website = $this->site();
        app(WebsiteRedirects::class)->apply($website, [['from' => 'apply.coupons', 'to' => '']]);

        Livewire::test(WebsiteShow::class, ['website' => $website->fresh()])->call('regenerate');

        $this->assertStringContainsString('server_name apply.coupons;', (string) $website->fresh()->nginx_config);
    }
}
