<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show;
use App\Models\User;
use App\Models\Website;
use App\Services\SiteSecurity;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const NGINX = "server {\n    listen 80;\n    server_name shop.example.com;\n\n    location / {\n        try_files \$uri /index.php;\n    }\n}\n";

    protected function setUp(): void
    {
        parent::setUp();

        config(['laravelify.host_commands' => false]);
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    public function test_hiding_from_search_engines_adds_noindex_robots_and_crawler_blocking(): void
    {
        $website = Website::factory()->create(['slug' => 'shop-site', 'nginx_config' => self::NGINX]);

        Livewire::test(Show::class, ['website' => $website])
            ->set('hide_from_search', true)
            ->set('block_crawlers', true)
            ->call('saveSecurity')
            ->assertHasNoErrors();

        $website->refresh();
        $security = app(SiteSecurity::class);
        $server = $security->serverConfig($website);
        $http = $security->httpConfig($website);

        $this->assertStringContainsString("server_name shop.example.com;\n    include /etc/nginx/laravelify-security/shop-site.conf;", $website->nginx_config);
        $this->assertStringContainsString('add_header X-Robots-Tag "noindex, nofollow, noarchive, nosnippet" always;', $server);
        $this->assertStringContainsString('location = /robots.txt', $server);
        $this->assertStringContainsString('Disallow: /', $server);
        $this->assertStringContainsString('if ($laravelify_crawler_shop_site)', $server);
        $this->assertStringContainsString('map $http_user_agent $laravelify_crawler_shop_site', $http);
        $this->assertStringContainsString('googlebot', $http);
        $this->assertStringNotContainsString('telegrambot', $http);
        $this->assertStringNotContainsString('facebookexternalhit', $http);

        $rendered = storage_path('app/rendered/sites/shop-site');
        $this->assertSame($server, file_get_contents($rendered.'/security-server.conf'));
        $this->assertSame($http, file_get_contents($rendered.'/security-http.conf'));
    }

    public function test_an_existing_robots_location_is_not_duplicated(): void
    {
        $website = Website::factory()->create([
            'nginx_config' => str_replace('    location / {', "    location = /robots.txt {\n        proxy_pass http://127.0.0.1:3000;\n    }\n\n    location / {", self::NGINX),
            'hide_from_search' => true,
        ]);

        $server = app(SiteSecurity::class)->serverConfig($website);

        $this->assertStringContainsString('X-Robots-Tag', $server);
        $this->assertStringNotContainsString('location = /robots.txt', $server);
    }

    public function test_basic_auth_stores_only_a_hash_and_skips_listed_paths(): void
    {
        $website = Website::factory()->create(['slug' => 'staging-app', 'nginx_config' => self::NGINX]);

        Livewire::test(Show::class, ['website' => $website])
            ->set('basic_auth_enabled', true)
            ->set('basic_auth_user', 'team')
            ->call('saveSecurity')
            ->assertHasErrors('basic_auth_password')
            ->set('basic_auth_password', 'correct horse')
            ->set('basic_auth_except', "/api/\n/hooks/github\n/bad path;\n")
            ->call('saveSecurity')
            ->assertHasErrors('basic_auth_except')
            ->set('basic_auth_except', "/api/\n/hooks/github\n")
            ->call('saveSecurity')
            ->assertHasNoErrors()
            ->assertSet('basic_auth_password', '');

        $website->refresh();
        $security = app(SiteSecurity::class);

        $this->assertStringStartsWith('$6$', $website->basic_auth_hash);
        $this->assertTrue($security->verify($website, 'correct horse'));
        $this->assertFalse($security->verify($website, 'wrong'));
        $this->assertArrayNotHasKey('basic_auth_hash', $website->toArray());
        $this->assertSame('team:'.$website->basic_auth_hash."\n", $security->htpasswd($website));

        $server = $security->serverConfig($website);
        $http = $security->httpConfig($website);
        $this->assertStringContainsString('auth_basic $laravelify_auth_staging_app;', $server);
        $this->assertStringContainsString('auth_basic_user_file /etc/nginx/laravelify-security/staging-app.htpasswd;', $server);
        $this->assertStringContainsString('~^/api/ off;', $http);
        $this->assertStringContainsString('~^/hooks/github off;', $http);
        $this->assertStringContainsString('~^/\.well-known/acme-challenge/ off;', $http);

        $hash = $website->basic_auth_hash;
        Livewire::test(Show::class, ['website' => $website])
            ->set('basic_auth_user', 'team2')
            ->call('saveSecurity')
            ->assertHasNoErrors();

        $this->assertSame($hash, $website->fresh()->basic_auth_hash, 'An empty password field keeps the saved password.');

        Livewire::test(Show::class, ['website' => $website->fresh()])
            ->set('basic_auth_enabled', false)
            ->call('saveSecurity');

        $this->assertSame('', $security->htpasswd($website->fresh()));
        $this->assertStringNotContainsString('auth_basic', $security->serverConfig($website->fresh()));
    }

    public function test_every_server_block_gets_the_include(): void
    {
        $config = "server {\n    listen 80;\n    server_name a.example.com;\n    return 301 https://a.example.com\$request_uri;\n}\n\nserver {\n    listen 443 ssl;\n    server_name a.example.com;\n}\n";
        $website = Website::factory()->create(['slug' => 'two-blocks', 'nginx_config' => $config, 'hide_from_search' => true]);

        $this->assertTrue(app(SiteSecurity::class)->ensureInclude($website));
        $this->assertSame(2, substr_count($website->nginx_config, 'include /etc/nginx/laravelify-security/two-blocks.conf;'));
        $this->assertFalse(app(SiteSecurity::class)->ensureInclude($website), 'It is added once.');
    }
}
