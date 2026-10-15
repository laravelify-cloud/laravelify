<?php

namespace Tests\Feature;

use App\Livewire\Settings\Cloudflare as CloudflareSettings;
use App\Livewire\Websites\Form as WebsiteForm;
use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\CloudflareTunnel;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\CloudflareApi;
use App\Services\CloudflareRoutes;
use App\Services\ConfigRenderer;
use App\Services\TwoFactor;
use App\Services\WebsiteDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteDomainsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    private function tunnel(): CloudflareTunnel
    {
        return CloudflareTunnel::factory()->create([
            'token' => base64_encode((string) json_encode(['a' => 'acc123', 't' => 'tun456', 's' => 'secret'])),
        ]);
    }

    /**
     * A fake Cloudflare: one zone (x.com), the tunnel's current routes, and the DNS records it holds.
     *
     * @param  list<array<string, mixed>>  $records  DNS records that already exist, by name
     * @param  list<array<string, mixed>>  $ingress
     */
    private function cloudflare(array $records = [], array $ingress = [['hostname' => 'other.org', 'service' => 'http://localhost:3000'], ['service' => 'http_status:404']]): void
    {
        Setting::putValue(CloudflareApi::SETTING, 'cf_token_123');

        Http::fake(function (Request $request) use ($records, $ingress) {
            $url = $request->url();
            $ok = fn (mixed $result) => Http::response(['success' => true, 'errors' => [], 'result' => $result]);

            return match (true) {
                str_contains($url, '/cfd_tunnel/tun456/configurations') && $request->method() === 'GET' => $ok(['config' => ['ingress' => $ingress]]),
                str_contains($url, '/cfd_tunnel/tun456/configurations') => $ok(['config' => []]),
                str_contains($url, '/zones?name=x.com') => $ok([['id' => 'zone1', 'name' => 'x.com']]),
                str_contains($url, '/zones?') && str_contains($url, 'per_page') => $ok([['id' => 'zone1', 'name' => 'x.com']]),
                str_contains($url, '/zones?') => $ok([]),
                str_contains($url, '/zones/zone1/dns_records') && $request->method() === 'GET' => $ok(array_values(array_filter($records, fn (array $record) => str_contains($url, 'name='.$record['name'])))),
                str_contains($url, '/dns_records') => $ok(['id' => 'new']),
                str_contains($url, '/user/tokens/verify') => $ok(['status' => 'active']),
                default => Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404),
            };
        });
    }

    public function test_domains_are_cleaned_and_www_is_derived(): void
    {
        $this->assertSame(['example.com', 'www.example.com', 'b.org'], WebsiteDomains::normalize(" HTTPS://Example.com/path\nwww.example.com, b.org. example.com"));
        $this->assertSame('www.example.com', WebsiteDomains::wwwOf('example.com'));
        $this->assertSame('www.example.com', WebsiteDomains::wwwOf('www.example.com'));
    }

    public function test_the_form_adds_www_and_extra_domains_to_nginx_and_the_tunnel(): void
    {
        $this->signIn();
        $tunnel = $this->tunnel();
        $group = Group::factory()->create();

        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->call('chooseType', 'static')
            ->set('name', 'Shop')
            ->set('domain', 'x.com')
            ->set('include_www', true)
            ->set('extra_domains', "y.com\nwww.y.com")
            ->set('cloudflare_tunnel_id', (string) $tunnel->id)
            ->set('repository', 'https://github.com/acme/shop.git')
            ->call('save')
            ->assertHasNoErrors();

        $website = Website::query()->sole();
        $this->assertSame(['y.com', 'www.y.com', 'www.x.com'], $website->aliases);
        $this->assertSame(['x.com', 'y.com', 'www.y.com', 'www.x.com'], $website->domains());
        $this->assertStringContainsString('server_name x.com y.com www.y.com www.x.com;', (string) $website->nginx_config);

        $ingress = (string) $tunnel->fresh()->ingress;

        foreach (['x.com', 'y.com', 'www.y.com', 'www.x.com'] as $host) {
            $this->assertStringContainsString('hostname: '.$host."\n", $ingress);
        }
    }

    public function test_a_domain_another_website_already_answers_for_is_refused(): void
    {
        $this->signIn();
        Website::factory()->create(['domain' => 'taken.com', 'aliases' => ['www.shared.com']]);
        $group = Group::factory()->create();

        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->call('chooseType', 'static')
            ->set('name', 'Second')
            ->set('domain', 'second.com')
            ->set('extra_domains', 'www.shared.com')
            ->call('save')
            ->assertHasErrors(['aliases']);

        $this->assertSame(1, Website::query()->count());
    }

    public function test_changing_domains_on_the_general_tab_rewrites_server_name_and_keeps_other_nginx_edits(): void
    {
        $this->signIn();
        $website = Website::factory()->create([
            'domain' => 'x.com',
            'nginx_config' => "server {\n    listen 80;\n    server_name x.com;\n    location /special { return 200; }\n}\n",
        ]);

        Livewire::test(WebsiteShow::class, ['website' => $website->fresh()])
            ->set('aliases_text', "www.x.com\ny.com")
            ->call('saveGeneral')
            ->assertHasNoErrors();

        $website->refresh();
        $this->assertSame(['www.x.com', 'y.com'], $website->aliases);
        $this->assertStringContainsString('server_name x.com www.x.com y.com;', (string) $website->nginx_config);
        $this->assertStringContainsString('location /special { return 200; }', (string) $website->nginx_config);

        Livewire::test(WebsiteShow::class, ['website' => $website->fresh()])
            ->set('aliases_text', 'x.com')
            ->call('saveGeneral')
            ->assertHasNoErrors();
        $this->assertNull($website->fresh()->aliases, 'The main domain is not repeated as an extra domain.');
    }

    public function test_cloudflare_gets_the_tunnel_routes_and_cname_for_every_domain_and_keeps_other_routes(): void
    {
        $this->cloudflare();
        $tunnel = $this->tunnel();
        $website = Website::factory()->create(['cloudflare_tunnel_id' => $tunnel->id, 'domain' => 'x.com', 'aliases' => ['www.x.com'], 'http_port' => null]);

        $report = app(CloudflareRoutes::class)->sync($website);

        $this->assertSame([], CloudflareRoutes::problems($report));
        $this->assertSame(['created', 'created'], array_column($report['lines'], 'dns'));

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $ingress = $request->data()['config']['ingress'];

            return collect($ingress)->pluck('hostname')->filter()->values()->all() === ['other.org', 'x.com', 'www.x.com']
                && end($ingress) === ['service' => 'http_status:404'];
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === ['type' => 'CNAME', 'name' => 'www.x.com', 'content' => 'tun456.cfargotunnel.com', 'proxied' => true, 'ttl' => 1, 'comment' => 'Laravelify tunnel']);
    }

    public function test_an_existing_dns_record_is_reported_not_replaced_and_a_right_cname_is_left_alone(): void
    {
        $this->cloudflare([
            ['id' => 'r1', 'type' => 'A', 'name' => 'x.com', 'content' => '1.2.3.4'],
            ['id' => 'r2', 'type' => 'CNAME', 'name' => 'www.x.com', 'content' => 'tun456.cfargotunnel.com'],
        ], [['hostname' => 'x.com', 'service' => 'http://127.0.0.1:80'], ['hostname' => 'www.x.com', 'service' => 'http://127.0.0.1:80'], ['service' => 'http_status:404']]);
        $tunnel = $this->tunnel();
        $website = Website::factory()->create(['cloudflare_tunnel_id' => $tunnel->id, 'domain' => 'x.com', 'aliases' => ['www.x.com'], 'http_port' => null]);

        $report = app(CloudflareRoutes::class)->sync($website);

        $this->assertSame(['x.com (DNS): a A record for x.com already exists. Delete it in Cloudflare (DNS), then save again'], CloudflareRoutes::problems($report));
        $this->assertSame(['ok', 'ok'], array_column($report['lines'], 'route'));
        $this->assertSame(['error: a A record for x.com already exists. Delete it in Cloudflare (DNS), then save again', 'ok'], array_column($report['lines'], 'dns'));
        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PUT', 'POST', 'DELETE'], true));
    }

    public function test_dropped_domains_leave_the_tunnel_and_lose_their_cname_but_only_ours(): void
    {
        $this->cloudflare([['id' => 'r2', 'type' => 'CNAME', 'name' => 'www.x.com', 'content' => 'tun456.cfargotunnel.com']],
            [['hostname' => 'www.x.com', 'service' => 'http://127.0.0.1:80'], ['hostname' => 'x.com', 'service' => 'http://127.0.0.1:80'], ['service' => 'http_status:404']]);
        $tunnel = $this->tunnel();
        $website = Website::factory()->create(['cloudflare_tunnel_id' => $tunnel->id, 'domain' => 'x.com', 'aliases' => null, 'http_port' => null]);

        $report = app(CloudflareRoutes::class)->sync($website, ['www.x.com']);

        $this->assertSame([], CloudflareRoutes::problems($report));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/dns_records/r2'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && collect($request->data()['config']['ingress'])->pluck('hostname')->filter()->values()->all() === ['x.com']);
    }

    public function test_without_a_token_the_panel_says_what_to_add_in_cloudflare(): void
    {
        $tunnel = $this->tunnel();
        $website = Website::factory()->create(['cloudflare_tunnel_id' => $tunnel->id, 'domain' => 'x.com', 'aliases' => ['www.x.com']]);

        $report = app(CloudflareRoutes::class)->sync($website);

        $this->assertFalse($report['configured']);
        $this->assertStringContainsString('add x.com, www.x.com in Cloudflare yourself', (string) $report['note']);
        Http::assertNothingSent();
    }

    public function test_the_cloudflare_settings_page_saves_tests_and_removes_the_token(): void
    {
        $this->signIn();
        $this->cloudflare();
        Setting::query()->where('key', CloudflareApi::SETTING)->delete();

        Livewire::test(CloudflareSettings::class)
            ->set('token', 'bad token!')
            ->call('save')
            ->assertHasErrors('token')
            ->set('token', 'cf_token_123')
            ->call('save')
            ->assertSet('hasToken', true)
            ->assertSee('The token works and can see 1 domain.')
            ->set('confirmName', 'cloudflare-token')
            ->call('clear')
            ->assertSet('hasToken', false);

        $this->assertNull(Setting::getValue(CloudflareApi::SETTING));
    }

    public function test_the_nginx_server_name_helper_keeps_a_file_without_server_name_as_it_is(): void
    {
        $website = Website::factory()->make(['domain' => 'x.com', 'aliases' => ['www.x.com']]);

        $this->assertSame('location / {}', app(ConfigRenderer::class)->withServerNames($website, 'location / {}'));
    }
}
