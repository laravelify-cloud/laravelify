<?php

namespace Tests\Feature;

use App\Livewire\Server\Nginx as NginxPage;
use App\Models\User;
use App\Models\Website;
use App\Services\NginxOverview;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class NginxPageTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/nginx-'.uniqid());
        File::ensureDirectoryExists($this->root.'/sites-enabled');
        File::ensureDirectoryExists($this->root.'/conf.d');
        $this->app->instance(NginxOverview::class, new NginxOverview($this->root));

        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_server_blocks_are_read_with_their_names_ports_and_targets(): void
    {
        $servers = NginxOverview::parse(<<<'CONF'
# a comment { with a brace
server {
    listen 80;
    listen [::]:80;
    server_name supercell.apply.coupons;
    location / { proxy_pass http://127.0.0.1:3000; }
}

server {
    listen 127.0.0.1:8443 ssl;
    server_name apply.coupons www.apply.coupons;
    return 301 https://supercell.apply.coupons$request_uri;
}
CONF);

        $this->assertCount(2, $servers);
        $this->assertSame(['supercell.apply.coupons'], $servers[0]['names']);
        $this->assertSame([80], $servers[0]['ports']);
        $this->assertSame(['proxy http://127.0.0.1:3000'], $servers[0]['targets']);
        $this->assertSame([8443], $servers[1]['ports']);
        $this->assertSame(['redirect 301 → https://supercell.apply.coupons$request_uri'], $servers[1]['targets']);
    }

    public function test_the_page_lists_sites_owners_ports_and_clashing_domains(): void
    {
        Website::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
        File::put($this->root.'/sites-enabled/laravelify-shop.conf', "server {\n    listen 80;\n    server_name shop.test;\n}\n");
        File::put($this->root.'/conf.d/old.conf', "server {\n    listen 80;\n    server_name shop.test;\n}\n");
        File::put($this->root.'/conf.d/laravelify-security-shop.conf', "geo \$x \$y { default 0; }\n");

        Livewire::test(NginxPage::class)
            ->assertSee('shop.test')
            ->assertSee('Shop')
            ->assertSee('Not managed by Laravelify')
            ->assertSee('Two server blocks answer for the same domain and port')
            ->assertDontSee('laravelify-security-shop.conf');

        $conflicts = NginxOverview::conflicts(app(NginxOverview::class)->servers());
        $this->assertSame('shop.test', $conflicts[0]['name']);
        $this->assertSame(80, $conflicts[0]['port']);
    }

    public function test_the_test_button_shows_a_result_and_the_log_can_be_loaded(): void
    {
        Livewire::test(NginxPage::class)
            ->call('runTest')
            ->assertSet('test.ok', true)
            ->call('loadLog')
            ->assertSet('log', '[skipped] host commands are disabled');
    }
}
