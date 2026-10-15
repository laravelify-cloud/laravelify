<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Security\PrivateKeys;
use App\Livewire\Setup\CreateAdmin;
use App\Livewire\Setup\EnableTwoFactor;
use App\Livewire\Sources\GithubForm;
use App\Livewire\Sources\GithubShow;
use App\Livewire\Websites\Form as WebsiteForm;
use App\Models\GithubApp;
use App\Models\Group;
use App\Models\PrivateKey;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandRunner;
use App\Services\ConfigRenderer;
use App\Services\GithubAppClient;
use App\Services\TwoFactor;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_goes_to_setup_and_locks_the_panel_until_two_factor_is_confirmed(): void
    {
        $this->get('/')->assertRedirect(route('setup'));

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->post('/livewire-test/update')->assertNotFound();

        Livewire::test(CreateAdmin::class)
            ->set('name', 'Admin')
            ->set('email', 'admin@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('save')
            ->assertRedirect(route('setup.two-factor'));

        $user = User::query()->first();
        $this->assertNotNull($user);
        $this->assertSame(1, User::query()->count());

        $this->actingAs($user);
        $this->get('/')->assertRedirect(route('setup.two-factor'));

        $screen = Livewire::test(EnableTwoFactor::class);
        $user->refresh();
        $code = app(TwoFactor::class)->currentCode($user);

        $screen->set('code', $code)
            ->call('confirm')
            ->assertRedirect(route('dashboard'));

        $this->get('/')->assertOk();
        $this->get('/setup')->assertRedirect(route('dashboard'));

        Livewire::test(CreateAdmin::class)->assertRedirect(route('login'));
    }

    public function test_login_requires_the_authenticator_code(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password123',
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->get('/login')->assertOk();

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'password123')
            ->set('code', '000000')
            ->call('authenticate')
            ->assertHasErrors('code');

        $this->assertGuest();

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'password123')
            ->set('code', app(TwoFactor::class)->currentCode($user))
            ->call('authenticate')
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_group_can_hold_a_laravel_site_and_a_next_site(): void
    {
        $this->signIn();
        $group = Group::factory()->create(['name' => 'FF Kristal']);

        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->set('name', 'Kristal API')
            ->set('type', 'laravel')
            ->set('domain', 'api.example.org')
            ->set('php_version', '8.2')
            ->set('enable_reverb', true)
            ->set('branch', 'main')
            ->call('save')
            ->assertRedirect();

        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->set('name', 'Kristal Store')
            ->set('type', 'nextjs')
            ->set('domain', 'example.org')
            ->set('node_version', '22')
            ->set('branch', 'main')
            ->call('save')
            ->assertRedirect();

        $laravel = Website::query()->where('type', 'laravel')->first();
        $next = Website::query()->where('type', 'nextjs')->first();

        $this->assertNotNull($laravel);
        $this->assertNotNull($next);
        $this->assertSame($group->id, $laravel->group_id);
        $this->assertStringContainsString('unix:/run/php/php8.2-fpm.sock', $laravel->nginx_config);
        $this->assertStringContainsString('root '.$laravel->deploy_path.'/public', $laravel->nginx_config);
        $this->assertStringContainsString('listen 80;', $laravel->nginx_config);
        $this->assertStringContainsString('queue:work redis', $laravel->process_config);
        $this->assertStringContainsString('schedule:work', $laravel->process_config);
        $this->assertStringContainsString('reverb:start', $laravel->process_config);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:'.$next->port, $next->nginx_config);
        $this->assertStringContainsString('server.js', $next->process_config);
        $this->assertStringContainsString('PORT: "'.$next->port.'"', $next->process_config);

        Livewire::actingAs(auth()->user())
            ->test(GroupShow::class, ['group' => $group])
            ->assertSee('Kristal API')
            ->assertSee('Kristal Store');
    }

    public function test_a_github_app_source_can_be_saved(): void
    {
        $user = $this->signIn();

        $this->get(route('sources.index'))
            ->assertOk()
            ->assertSee('New source')
            ->assertSee('GitHub App')
            ->assertSee($user->name)
            ->assertSee('Appearance');

        Livewire::test(GithubForm::class)
            ->set('name', 'FFKRISTAL')
            ->set('organization', 'FFKRISTAL-AZ')
            ->call('save')
            ->assertRedirect();

        $app = GithubApp::query()->where('name', 'FFKRISTAL')->first();

        $this->assertNotNull($app);
        $this->assertFalse($app->isConnected());
        $this->get(route('sources.github.show', $app))
            ->assertOk()
            ->assertSee('Register with GitHub')
            ->assertSee('Continue manually');

        $key = PrivateKey::query()->create([
            'name' => 'github-app-example',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----",
            'is_git_related' => true,
        ]);

        Livewire::test(GithubShow::class, ['githubApp' => $app])
            ->call('createGithubAppManually')
            ->assertSet('manual', true)
            ->set('app_id', '5259841')
            ->set('installation_id', '169543286')
            ->set('private_key_id', (string) $key->id)
            ->set('webhook_secret', 'hook-secret')
            ->call('save');

        $this->assertTrue($app->fresh()->isConnected());
        $this->assertSame('hook-secret', $app->fresh()->webhook_secret);
    }

    public function test_github_manifest_conversion_sends_an_empty_body(): void
    {
        $app = GithubApp::query()->create([
            'name' => 'calm-lynx',
            'slug' => 'calm-lynx',
            'html_url' => 'https://github.com',
            'api_url' => 'https://api.github.com',
            'git_user' => 'git',
            'git_port' => 22,
        ]);

        Http::fake([
            'https://api.github.com/app-manifests/the-code/conversions' => Http::response([
                'id' => 99,
                'name' => 'calm-lynx',
                'client_id' => 'cid',
                'client_secret' => 'secret',
                'webhook_secret' => 'hook',
                'pem' => "-----BEGIN RSA PRIVATE KEY-----\nKEY\n-----END RSA PRIVATE KEY-----",
                'html_url' => 'https://github.com/apps/calm-lynx',
            ]),
        ]);

        $this->get(route('hooks.github-app.callback', ['githubApp' => $app, 'code' => 'the-code']))
            ->assertRedirect('https://github.com/apps/calm-lynx/installations/new');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.github.com/app-manifests/the-code/conversions'
                && $request->body() !== '[]';
        });

        $this->assertSame('99', $app->fresh()->app_id);
        $this->assertTrue($app->fresh()->isConnected() === false);
        $this->assertNull($app->fresh()->private_key);
        $this->assertSame(
            "-----BEGIN RSA PRIVATE KEY-----\nKEY\n-----END RSA PRIVATE KEY-----",
            $app->fresh()->privateKey->private_key,
        );
    }

    public function test_github_connection_result_is_shown_on_the_page(): void
    {
        $this->signIn();
        $key = PrivateKey::query()->create([
            'name' => 'github-app-scary-wolf',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nKEY\n-----END RSA PRIVATE KEY-----",
            'is_git_related' => true,
        ]);
        $app = GithubApp::query()->create([
            'name' => 'scary-wolf',
            'slug' => 'scary-wolf',
            'html_url' => 'https://github.com',
            'api_url' => 'https://api.github.com',
            'git_user' => 'git',
            'git_port' => 22,
            'app_id' => '5270097',
            'installation_id' => '170149053',
            'client_id' => 'Iv23lip',
            'client_secret' => 'secret-value',
            'webhook_secret' => 'hook-value',
            'private_key_id' => $key->id,
        ]);

        $this->mock(GithubAppClient::class, function ($mock): void {
            $mock->shouldReceive('testConnection')->once()->andReturn('Connected to GitHub as FFKRISTAL-AZ.');
        });

        Livewire::test(GithubShow::class, ['githubApp' => $app])
            ->assertSet('client_secret', 'secret-value')
            ->assertSet('webhook_secret', 'hook-value')
            ->assertSet('private_key_id', (string) $key->id)
            ->assertSee('github-app-scary-wolf')
            ->call('testConnection')
            ->assertSet('notice', 'Connected to GitHub as FFKRISTAL-AZ.')
            ->assertSee('Connected to GitHub as FFKRISTAL-AZ.');
    }

    public function test_private_keys_live_in_their_own_list(): void
    {
        $this->signIn();

        Livewire::test(PrivateKeys::class)
            ->set('newName', 'gifted-gerbil')
            ->set('newDescription', 'A test key')
            ->set('newPrivateKey', "-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----")
            ->call('createManual')
            ->assertSee('gifted-gerbil')
            ->assertSee('Unused');

        $key = PrivateKey::query()->where('name', 'gifted-gerbil')->first();

        $this->assertNotNull($key);

        Livewire::test(PrivateKeys::class)
            ->call('openEditor', $key->id)
            ->set('confirmName', 'wrong')
            ->call('deleteKey')
            ->assertHasErrors('confirmName');

        $this->assertNotNull($key->fresh());

        Livewire::test(PrivateKeys::class)
            ->call('openEditor', $key->id)
            ->set('confirmName', 'gifted-gerbil')
            ->call('deleteKey');

        $this->assertNull($key->fresh());
    }

    public function test_reserved_ports_and_process_pages_open(): void
    {
        $this->signIn();

        $this->get(route('server.ports'))->assertOk()->assertSee('3306')->assertSee('MySQL');
        $this->get(route('server.processes'))->assertOk()->assertSee('PM2');
    }

    public function test_a_connected_github_app_can_fill_a_new_website_repository(): void
    {
        $this->signIn();
        $group = Group::factory()->create(['slug' => 'laravel-test']);
        $key = PrivateKey::query()->create([
            'name' => 'github-app-site',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----",
            'is_git_related' => true,
        ]);
        $app = GithubApp::query()->create([
            'name' => 'scary-wolf',
            'slug' => 'scary-wolf',
            'html_url' => 'https://github.com',
            'api_url' => 'https://api.github.com',
            'app_id' => '5270097',
            'installation_id' => '170149053',
            'private_key_id' => $key->id,
        ]);

        $this->mock(GithubAppClient::class, function ($mock): void {
            $mock->shouldReceive('repositories')->once()->andReturn([[
                'name' => 'FFKRISTAL-AZ/apply-coupons-backend-laravel',
                'clone_url' => 'https://github.com/FFKRISTAL-AZ/apply-coupons-backend-laravel.git',
                'branch' => 'main',
            ]]);
        });

        $this->get(route('websites.create', $group))
            ->assertOk()
            ->assertSee('Git Repository (with GitHub App)')
            ->assertSee('Deploy');

        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->call('selectGithubApp', $app->id)
            ->assertSet('github_app_id', (string) $app->id)
            ->call('loadRepository')
            ->assertSet('repository', 'https://github.com/FFKRISTAL-AZ/apply-coupons-backend-laravel.git')
            ->assertSet('branch', 'main')
            ->assertSet('name', 'apply-coupons-backend-laravel');
    }

    public function test_a_site_can_listen_on_a_port_without_a_domain_or_tunnel(): void
    {
        $this->signIn();
        $group = Group::factory()->create();

        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->set('name', 'Laravel')
            ->set('type', 'laravel')
            ->set('domain', '')
            ->set('http_port', '8081')
            ->set('repository', 'https://github.com/laravel/laravel.git')
            ->set('branch', '13.x')
            ->set('php_version', '8.4')
            ->call('save')
            ->assertRedirect();

        $website = Website::query()->where('repository', 'https://github.com/laravel/laravel.git')->first();

        $this->assertNotNull($website);
        $this->assertNull($website->domain);
        $this->assertNull($website->cloudflare_tunnel_id);
        $this->assertSame(8081, $website->http_port);
        $this->assertStringContainsString('listen 8081;', $website->nginx_config);
        $this->assertStringContainsString('server_name _;', $website->nginx_config);
        $this->assertStringContainsString('APP_URL=http://127.0.0.1:8081', $website->env_content);
        $this->assertSame('http://127.0.0.1:8081', $website->publicUrl());
    }

    public function test_deploy_commands_have_a_home_and_chat_apps_get_socket_io(): void
    {
        $env = app(CommandRunner::class)->environment();

        $this->assertNotSame('', $env['HOME'] ?? '');
        $this->assertNotSame('', $env['COMPOSER_HOME'] ?? '');

        $website = Website::factory()->create([
            'nginx_config' => "server {\n    location / {\n        try_files \$uri /index.php;\n    }\n}\n",
            'process_config' => "[program:chat-queue]\ncommand=php artisan queue:work\n",
        ]);
        $dir = storage_path('framework/testing/'.$website->slug);
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir.'/realtime');
        File::put($dir.'/realtime/server.js', '');
        File::put($dir.'/realtime/package.json', '{}');
        $website->deploy_path = $dir;
        $website->save();

        $this->assertTrue(app(ConfigRenderer::class)->attachSocketIo($website));
        $website->refresh();

        $this->assertStringContainsString('location /socket.io/', $website->nginx_config);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:3015', $website->nginx_config);
        $this->assertStringContainsString('node server.js', $website->process_config);
        $this->assertStringContainsString('user=www-data', $website->process_config);
        $this->assertSame(1, substr_count($website->nginx_config, 'location /socket.io/'));

        File::deleteDirectory($dir);
    }

    private function signIn(): User
    {
        $user = User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user);

        return $user;
    }
}
