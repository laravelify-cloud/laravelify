<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show;
use App\Models\Group;
use App\Models\PanelTask;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Glitchtip;
use App\Services\HostWriter;
use App\Services\PanelTaskRunner;
use App\Services\TwoFactor;
use App\Services\WebsiteCreator;
use App\Services\WebsiteSwitch;
use App\Support\EnvFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class GlitchtipTest extends TestCase
{
    use RefreshDatabase;

    private const DSN = 'https://abc123@panel.test/glitchtip/1';

    private function signIn(): User
    {
        $user = User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function fakeGlitchtip(): void
    {
        Http::fake([
            '127.0.0.1:8095/glitchtip/api/0/organizations/' => Http::sequence()->push([])->push(['slug' => 'laravelify']),
            '127.0.0.1:8095/glitchtip/api/0/organizations/laravelify/teams/' => Http::sequence()->push([])->push(['slug' => 'websites']),
            '127.0.0.1:8095/glitchtip/api/0/teams/laravelify/websites/projects/' => Http::response(['slug' => 'shop', 'id' => 7]),
            '127.0.0.1:8095/glitchtip/api/0/projects/laravelify/shop/keys/' => Http::response([['dsn' => ['public' => self::DSN]]]),
            '127.0.0.1:8095/glitchtip/api/0/projects/laravelify/shop/issues/*' => Http::response([
                ['id' => '3', 'title' => 'RuntimeException: Cart is empty', 'culprit' => 'App\\Http\\Controllers\\CartController@show', 'count' => '12', 'userCount' => 4, 'level' => 'error', 'lastSeen' => now()->toIso8601String()],
            ]),
        ]);
    }

    public function test_installing_keeps_the_token_and_admin_and_makes_the_organization_and_team(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $host = Mockery::mock(HostWriter::class);
        $host->shouldReceive('glitchtipInstall')->once()->with(Mockery::type('string'), Glitchtip::VERSION, 'admin@example.com')
            ->andReturn(['token' => 'tok', 'email' => 'admin@example.com', 'password' => 'secret']);
        $this->app->instance(HostWriter::class, $host);
        $this->fakeGlitchtip();

        app(Glitchtip::class)->install($user);

        $this->assertSame('tok', Setting::getValue('glitchtip.token'));
        $this->assertSame('secret', Setting::getValue('glitchtip.password'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/organizations/') && $request->method() === 'POST' && $request['name'] === 'Laravelify');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/teams/') && $request->method() === 'POST' && $request['slug'] === 'websites');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_connecting_a_website_makes_its_project_and_puts_the_key_in_its_env(): void
    {
        Setting::putValue('glitchtip.token', 'tok');
        $this->fakeGlitchtip();
        $website = Website::factory()->create(['name' => 'Shop', 'type' => 'laravel', 'env_content' => "APP_NAME=Shop\n"]);

        $result = app(Glitchtip::class)->connect($website);

        $website->refresh();
        $this->assertSame(['SENTRY_LARAVEL_DSN', 'VITE_SENTRY_DSN'], $result['keys']);
        $this->assertSame('shop', $website->glitchtip_project);
        $this->assertSame(7, $website->glitchtip_project_id);
        $this->assertSame(['SENTRY_LARAVEL_DSN' => self::DSN, 'VITE_SENTRY_DSN' => self::DSN], EnvFile::values($website->env_content, ['SENTRY_LARAVEL_DSN', 'VITE_SENTRY_DSN']));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/projects/') && $request['platform'] === 'php-laravel');
    }

    public function test_every_website_without_a_project_is_connected_by_the_update(): void
    {
        $this->artisan('laravelify:glitchtip-connect')->assertSuccessful();
        Http::assertNothingSent();

        Setting::putValue('glitchtip.token', 'tok');
        $this->fakeGlitchtip();
        $website = Website::factory()->create(['name' => 'Shop', 'type' => 'express']);
        Website::factory()->create(['glitchtip_project' => 'blog', 'glitchtip_project_id' => 2]);

        $this->artisan('laravelify:glitchtip-connect')->expectsOutput('GlitchTip: 1 website connected.')->assertSuccessful();

        $this->assertSame('shop', $website->refresh()->glitchtip_project);
        $this->assertSame(self::DSN, EnvFile::values((string) $website->env_content, ['SENTRY_DSN'])['SENTRY_DSN']);
        Http::assertSentCount(2);
    }

    public function test_a_new_website_is_connected_when_error_tracking_is_on(): void
    {
        $this->app->instance(HostWriter::class, Mockery::mock(HostWriter::class)->shouldIgnoreMissing(''));
        Setting::putValue('glitchtip.token', 'tok');
        $this->fakeGlitchtip();

        $website = app(WebsiteCreator::class)->create(Group::factory()->create(), ['name' => 'Shop', 'type' => 'react', 'repository' => 'https://github.com/acme/shop.git'])['website'];

        $this->assertSame('shop', $website->refresh()->glitchtip_project);
        $this->assertSame(self::DSN, EnvFile::values((string) $website->env_content, ['VITE_SENTRY_DSN'])['VITE_SENTRY_DSN']);
    }

    public function test_deleting_a_website_deletes_its_project(): void
    {
        $this->app->instance(HostWriter::class, Mockery::mock(HostWriter::class)->shouldIgnoreMissing(''));
        Setting::putValue('glitchtip.token', 'tok');
        Http::fake(['127.0.0.1:8095/glitchtip/api/0/projects/laravelify/shop/' => Http::response(null, 204)]);
        $website = Website::factory()->create(['glitchtip_project' => 'shop', 'glitchtip_project_id' => 7]);

        app(WebsiteSwitch::class)->delete($website);

        $this->assertModelMissing($website);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/projects/laravelify/shop/'));
    }

    public function test_turning_off_error_tracking_removes_glitchtip_and_every_websites_key(): void
    {
        $this->signIn();
        $host = Mockery::mock(HostWriter::class);
        $host->shouldReceive('glitchtipRemove')->once()->andReturn('GlitchTip is removed.');
        $this->app->instance(HostWriter::class, $host);
        Setting::putValue('glitchtip.token', 'tok');
        Setting::putValue('glitchtip.password', 'secret');
        $shop = Website::factory()->create(['type' => 'laravel', 'glitchtip_project' => 'shop', 'glitchtip_project_id' => 7, 'env_content' => "APP_NAME=Shop\nSENTRY_LARAVEL_DSN=".self::DSN."\nVITE_SENTRY_DSN=".self::DSN."\n"]);

        $page = Livewire::test(Show::class, ['website' => $shop])->assertSee('Turn off error tracking');

        // The typed confirmation comes first.
        $page->set('confirmName', 'nope')->call('disableErrorTracking')->assertHasErrors('confirmName');
        $this->assertSame(0, PanelTask::query()->count());

        $page->set('confirmName', 'glitchtip')->call('disableErrorTracking');
        $task = PanelTask::query()->sole();
        $this->assertSame('glitchtip.remove', $task->kind);

        // The queue runs the task at once in tests.
        $shop->refresh();
        $this->assertSame(PanelTask::SUCCESS, $task->refresh()->status, (string) $task->message);
        $this->assertNull($shop->glitchtip_project);
        $this->assertSame("APP_NAME=Shop\n", $shop->env_content);
        $this->assertFalse(app(Glitchtip::class)->installed());
        $this->assertNull(Setting::getValue('glitchtip.password'));
    }

    public function test_a_long_failure_keeps_its_first_line_and_its_end(): void
    {
        $output = "Preparing its database...\nTraceback (most recent call last):\n".str_repeat("  File \"dbapi.py\", line 1938, in _batch_execute_sync\n", 60).'vpg.errors.InsufficientPrivilege: permission denied for schema public';

        $message = PanelTaskRunner::failureMessage($output);

        $this->assertStringStartsWith('Preparing its database...', $message);
        $this->assertStringEndsWith('permission denied for schema public', $message);
        $this->assertLessThan(1200, mb_strlen($message));
        $this->assertSame('short', PanelTaskRunner::failureMessage(' short '));
    }

    public function test_opening_glitchtip_signs_the_browser_in_and_stays_on_glitchtip(): void
    {
        $this->signIn();
        Setting::putValue('glitchtip.token', 'tok');
        Setting::putValue('glitchtip.email', 'admin@example.com');
        Setting::putValue('glitchtip.password', 'secret');
        Http::fake([
            '*/_allauth/browser/v1/auth/session' => Http::response(['status' => 401], 401, ['Set-Cookie' => 'csrftoken=csrf123; Path=/']),
            '*/_allauth/browser/v1/auth/login' => Http::response(['status' => 200], 200, ['Set-Cookie' => 'sessionid=sess456; Path=/; HttpOnly']),
        ]);

        $this->get(route('tools.glitchtip', ['to' => 'laravelify/issues/3']))
            ->assertRedirect(app(Glitchtip::class)->url('laravelify/issues/3'))
            ->assertPlainCookie('sessionid', 'sess456')
            ->assertPlainCookie('csrftoken', 'csrf123');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/auth/login') && $request['password'] === 'secret' && $request->hasHeader('X-CSRFToken', 'csrf123'));

        // Another site's address stays a page of this GlitchTip.
        $this->get(route('tools.glitchtip', ['to' => '//evil.example/x']))->assertRedirect(app(Glitchtip::class)->url('evil.example/x'));
        $this->get(route('tools.glitchtip', ['to' => 'https://evil.example']))->assertRedirect(app(Glitchtip::class)->url('laravelify/issues'));
        // Its sign-in page is never the way back: nginx would send the browser here again.
        $this->get(route('tools.glitchtip', ['to' => 'login']))->assertRedirect(app(Glitchtip::class)->url('laravelify/issues'));
    }

    public function test_a_turned_down_sign_in_opens_glitchtips_own_sign_in_instead_of_coming_back(): void
    {
        $this->signIn();
        Setting::putValue('glitchtip.token', 'tok');
        Setting::putValue('glitchtip.email', 'admin@example.com');
        Setting::putValue('glitchtip.password', 'changed-in-glitchtip');
        Http::fake([
            '*/_allauth/browser/v1/auth/session' => Http::response(['status' => 401], 401, ['Set-Cookie' => 'csrftoken=csrf123; Path=/']),
            '*/_allauth/browser/v1/auth/login' => Http::response(['status' => 400], 400),
        ]);

        $this->get(route('tools.glitchtip', ['to' => 'laravelify/issues']))
            ->assertRedirect(app(Glitchtip::class)->url('login?signin=own'))
            ->assertCookieMissing('sessionid');
    }

    public function test_glitchtip_pages_open_only_through_the_panel_and_its_api_stays_open(): void
    {
        $script = (string) file_get_contents(base_path('scripts/host-apply.sh'));

        $this->assertStringContainsString('auth_request /glitchtip-session;', $script);
        $this->assertStringContainsString('error_page 401 = @glitchtip_signin;', $script);
        $this->assertStringContainsString('return 302 /tools/glitchtip?to=$1;', $script);
        $this->assertMatchesRegularExpression('#location ~ \^/glitchtip/\(api\|_allauth\|[^)]*\)#', $script, 'The websites send their errors to the API without a session.');
        $this->assertStringContainsString('if ($arg_signin != "own")', $script);
        $this->assertSame(4, substr_count($script, 'auth_request off;'), 'The API (managed parts too), files, and the own sign-in skip the session check.');
        // What the panel manages is refused to browsers, and its pages get the panel's additions.
        $this->assertStringContainsString('organizations/[^/]+|projects/[^/]+/[^/]+(/keys/[^/]+)?|api-tokens/[^/]+', $script);
        $this->assertStringContainsString("sub_filter '</head>' '<link rel=\"stylesheet\" href=\"/glitchtip-panel/glitchtip.css", $script);
        $this->assertFileExists(public_path('glitchtip-panel/glitchtip.js'));
        $this->assertFileExists(public_path('glitchtip-panel/glitchtip.css'));
        $this->assertStringContainsString('host-apply.sh" glitchtip-nginx all', (string) file_get_contents(base_path('scripts/update.sh')));
    }

    public function test_the_sidebar_links_to_glitchtip_once_it_is_installed(): void
    {
        $this->signIn();
        $this->get(route('dashboard'))->assertDontSee(route('tools.glitchtip'));

        Setting::putValue('glitchtip.token', 'tok');
        $this->get(route('dashboard'))->assertSee(route('tools.glitchtip'))->assertSee('GlitchTip');
    }

    public function test_each_kind_of_site_gets_the_key_its_sdk_reads(): void
    {
        $glitchtip = app(Glitchtip::class);
        $keys = fn (string $type) => $glitchtip->envKeys(new Website(['type' => $type]));

        $this->assertSame(['SENTRY_DSN', 'NEXT_PUBLIC_SENTRY_DSN'], $keys('nextjs'));
        $this->assertSame(['VITE_SENTRY_DSN'], $keys('react'));
        $this->assertSame(['SENTRY_DSN'], $keys('express'));
        $this->assertSame([], $keys('static'));
        $this->assertSame('browser', $glitchtip->guide(new Website(['type' => 'static'])));
    }

    public function test_the_errors_section_offers_to_turn_on_error_tracking(): void
    {
        $this->signIn();
        $website = Website::factory()->create();

        Livewire::test(Show::class, ['website' => $website])
            ->assertSee('Turn on error tracking')
            ->call('enableErrorTracking');

        $this->assertSame('glitchtip.install', PanelTask::query()->sole()->kind);
    }

    public function test_the_glitchtip_tab_shows_what_is_set_up_and_what_is_left(): void
    {
        $this->signIn();
        $root = storage_path('framework/testing/glitchtip-site');
        File::ensureDirectoryExists($root);
        $website = Website::factory()->create(['name' => 'Shop', 'type' => 'nuxt', 'deploy_path' => $root]);

        try {
            Livewire::test(Show::class, ['website' => $website])
                ->assertSee('GlitchTip on this server')
                ->assertSee('Not installed yet')
                ->assertSee('No package.json yet: deploy the website first')
                ->assertSee('Turn on error tracking');

            Setting::putValue('glitchtip.token', 'tok');
            File::put($root.'/package.json', '{"dependencies": {"nuxt": "^4.0.0"}}');

            Livewire::test(Show::class, ['website' => $website])
                ->assertSee('No GlitchTip project yet')
                ->assertSee('Not in package.json yet')
                ->assertSee('Install SDK in the code')
                ->assertSee('npm install @sentry/nuxt')
                ->assertSee('Connect Shop');

            $this->fakeGlitchtip();
            File::put($root.'/package.json', '{"dependencies": {"nuxt": "^4.0.0", "@sentry/nuxt": "^11.9.0"}}');
            app(Glitchtip::class)->connect($website);

            Livewire::test(Show::class, ['website' => $website->refresh()])
                ->assertSee('Project shop')
                ->assertSee('SENTRY_DSN and NUXT_PUBLIC_SENTRY_DSN')
                ->assertSee('Found in package.json')
                ->assertSee('1 open issue');
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_a_connected_website_shows_its_errors_and_how_to_send_them(): void
    {
        $this->signIn();
        Setting::putValue('glitchtip.token', 'tok');
        Setting::putValue('glitchtip.email', 'admin@example.com');
        $this->fakeGlitchtip();
        $website = Website::factory()->create(['name' => 'Shop', 'type' => 'laravel']);

        Livewire::test(Show::class, ['website' => $website])
            ->assertSee('Connect Shop')
            ->call('connectErrorTracking')
            ->assertSee('RuntimeException: Cart is empty')
            ->assertSee('composer require sentry/sentry-laravel')
            ->assertSee(self::DSN)
            ->assertSee('admin@example.com');
    }
}
