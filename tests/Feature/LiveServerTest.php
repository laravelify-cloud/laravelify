<?php

namespace Tests\Feature;

use App\Livewire\Domains\Index as DomainsIndex;
use App\Models\CloudflareTunnel;
use App\Models\User;
use App\Models\Website;
use App\Services\CloudflareApi;
use App\Services\CloudflareRoutes;
use App\Services\CodeServer;
use App\Services\DomainSuggestions;
use App\Services\HostWriter;
use App\Services\LiveServer;
use App\Services\PanelPreview;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class LiveServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_websites_own_key_reaches_the_live_server(): void
    {
        $website = Website::factory()->create(['slug' => 'shop', 'live_key' => str_repeat('k', 48)]);

        $this->postJson('/hooks/live/shop/start', ['port' => 14100])->assertStatus(401);
        $this->postJson('/hooks/live/shop/start', ['port' => 14100], ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $this->postJson('/hooks/live/shop/stop', [], ['Authorization' => 'Bearer '.$website->live_key])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_it_puts_only_the_sites_own_dev_server_on_the_internet(): void
    {
        $website = Website::factory()->create(['slug' => 'shop']);
        $live = $this->live(listener: 2001, site: 1001);

        foreach ([80, 13500, 15000] as $port) {
            $this->assertRefused($live, $website, $port, 'must be between 14000 and 14999');
        }

        // Port 14100 is someone else's (another site's app or editor).
        $this->assertRefused($live, $website, 14100, 'Nothing of this website listens on port 14100');
        $this->assertNull($website->fresh()->live_host);
    }

    public function test_a_website_keeps_its_live_name_when_stopped_and_gives_it_up_on_release(): void
    {
        $website = Website::factory()->create(['slug' => 'shop']);
        $tunnel = CloudflareTunnel::factory()->create();
        $routes = Mockery::mock(CloudflareRoutes::class);
        $added = ['configured' => true, 'lines' => [['host' => 'calm-river-4f2a.example.com', 'route' => 'added', 'dns' => 'added', 'ok' => true]], 'note' => null];
        $routes->shouldReceive('route')->twice()->with(Mockery::on(fn ($t) => $t->is($tunnel)), 'calm-river-4f2a.example.com', Mockery::pattern('#^http://127\.0\.0\.1:141#'), 'localhost')->andReturn($added);
        // Stop: only the tunnel route goes, the DNS record stays. Release: both go.
        $routes->shouldReceive('route')->once()->with(Mockery::any(), 'calm-river-4f2a.example.com', '', null, true, true)->andReturn(['configured' => true, 'lines' => [], 'note' => null]);
        $routes->shouldReceive('route')->once()->with(Mockery::any(), 'calm-river-4f2a.example.com', '', null, true)->andReturn(['configured' => true, 'lines' => [], 'note' => null]);
        $live = $this->live(listener: 1001, site: 1001, routes: $routes, tunnel: $tunnel);

        $this->assertSame('https://calm-river-4f2a.example.com/', $live->start($website, 14100));
        $this->assertTrue($live->running($website->fresh()));

        $live->stop($website);
        $this->assertSame(['calm-river-4f2a.example.com', null], [$website->fresh()->live_host, $website->fresh()->live_port]);
        $this->assertFalse($live->running($website->fresh()));

        // The next Go Live (another port) gets the same name.
        $this->assertSame('https://calm-river-4f2a.example.com/', $live->start($website->fresh(), 14155));

        $live->release($website->fresh());
        $this->assertNull($website->fresh()->live_host);
    }

    public function test_the_domains_page_lists_every_domain_and_stops_a_live_server(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $shop = Website::factory()->create(['name' => 'shop', 'domain' => 'shop.example.com', 'aliases' => ['www.shop.example.com'], 'preview_host' => 'quiet-lake-1a2b.example.com', 'live_host' => 'calm-river-4f2a.example.com', 'live_port' => 14100]);
        $this->mock(LiveServer::class, function (MockInterface $live) use ($shop) {
            $live->shouldReceive('running')->andReturn(true);
            $live->shouldReceive('stop')->once()->with(Mockery::on(fn ($w) => $w->is($shop)));
        });

        Livewire::test(DomainsIndex::class)
            ->assertSee(['shop.example.com', 'www.shop.example.com', 'quiet-lake-1a2b.example.com', 'calm-river-4f2a.example.com'])
            ->assertSee(['Other domain', 'Preview', 'Live Server', 'Running'])
            ->set('kind', 'live')
            ->assertDontSee('quiet-lake-1a2b.example.com')
            ->call('stopLive', $shop->id);
    }

    public function test_an_editor_that_stopped_by_itself_leaves_no_live_address(): void
    {
        $website = Website::factory()->create(['code_server_token' => str_repeat('a', 32), 'live_host' => 'calm-river-4f2a.example.com', 'live_port' => 14100]);
        $this->mock(HostWriter::class, fn (MockInterface $host) => $host->shouldReceive('codeServerStatus')->andReturn('inactive -1'));
        $this->mock(LiveServer::class, fn (MockInterface $live) => $live->shouldReceive('stop')->once()->with(Mockery::on(fn ($w) => $w->is($website))));

        app(CodeServer::class)->refreshCredentials($website);
    }

    private function assertRefused(LiveServer $live, Website $website, int $port, string $message): void
    {
        try {
            $live->start($website, $port);
            $this->fail('Port '.$port.' was put on the internet.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function live(int $listener, int $site, ?CloudflareRoutes $routes = null, ?CloudflareTunnel $tunnel = null): LiveServer
    {
        $cloudflare = Mockery::mock(CloudflareApi::class);
        $cloudflare->shouldReceive('configured')->andReturn(true);
        $domains = Mockery::mock(DomainSuggestions::class);
        $domains->shouldReceive('random')->with('example.com')->andReturn('calm-river-4f2a.example.com');
        $preview = Mockery::mock(PanelPreview::class);
        $preview->shouldReceive('tunnelFor')->andReturn($tunnel);
        $preview->shouldReceive('zoneFor')->andReturn('example.com');

        $live = Mockery::mock(LiveServer::class, [$routes ?? Mockery::mock(CloudflareRoutes::class), $cloudflare, $domains, $preview])->makePartial()->shouldAllowMockingProtectedMethods();
        $live->shouldReceive('listenerUid')->andReturn($listener);
        $live->shouldReceive('siteUid')->andReturn($site);

        return $live;
    }
}
