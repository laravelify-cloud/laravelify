<?php

namespace Tests\Feature;

use App\Livewire\Websites\VsCode;
use App\Models\User;
use App\Models\Website;
use App\Services\CodeServer;
use App\Services\GithubAppClient;
use App\Services\HostWriter;
use App\Services\LiveServer;
use App\Services\PortAllocator;
use App\Services\ProjectWorkspace;
use App\Services\SiteFiles;
use App\Services\SiteUser;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class CodeServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_nginx_lets_only_signed_in_panel_users_into_an_existing_editor(): void
    {
        $website = Website::factory()->create(['code_server_token' => str_repeat('a', 32)]);
        $uri = ['X-Original-URI' => '/code/'.str_repeat('a', 32).'/static/main.js'];

        $this->get('/_access/code', $uri)->assertStatus(401);

        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));

        $this->get('/_access/code', $uri)->assertNoContent();
        $this->get('/_access/code', ['X-Original-URI' => '/code/'.str_repeat('b', 32).'/'])->assertStatus(401);
        $this->get('/_access/code', ['X-Original-URI' => '/p/whatever/'])->assertStatus(401);
        $this->assertNotNull($website->fresh()->code_server_token);
    }

    public function test_an_editor_without_two_factor_is_refused(): void
    {
        Website::factory()->create(['code_server_token' => str_repeat('a', 32)]);
        $this->actingAs(User::factory()->create(['two_factor_confirmed_at' => null]));

        $this->get('/_access/code', ['X-Original-URI' => '/code/'.str_repeat('a', 32).'/'])->assertStatus(401);
    }

    public function test_the_ai_agents_extension_is_packed_as_a_vsix(): void
    {
        $this->app->useStoragePath(sys_get_temp_dir().'/laravelify-vsix-'.uniqid());
        $vsix = app(CodeServer::class)->extensionPackage();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($vsix));
        $this->assertNotFalse($zip->locateName('extension.vsixmanifest'));
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $this->assertNotFalse($zip->locateName('extension/package.json'));
        $this->assertNotFalse($zip->locateName('extension/extension.js'));
        $this->assertNotFalse($zip->locateName('extension/media/sparkle.svg'));
        $this->assertStringContainsString('Id="laravelify-ai"', (string) $zip->getFromName('extension.vsixmanifest'));
        $this->assertSame('laravelify', json_decode((string) $zip->getFromName('extension/package.json'), true)['publisher']);
        $zip->close();
    }

    public function test_vs_code_needs_a_github_website_or_an_example_project(): void
    {
        $other = Website::factory()->create(['repository' => '/srv/somewhere/else']);
        $example = Website::factory()->create(['slug' => 'shop']);
        $example->update(['repository' => config('laravelify.site_home').'/examples/shop']);
        $github = Website::factory()->create(['repository' => 'https://github.com/acme/shop.git']);
        $workspace = app(ProjectWorkspace::class);

        $this->assertSame('VS Code needs a website that deploys from a GitHub repository or an example project.', app(CodeServer::class)->unavailableReason($other));
        $this->assertFalse($workspace->supports($other));
        $this->assertTrue($workspace->supports($example));
        $this->assertTrue($workspace->supports($github));
        $this->assertFalse($workspace->supports(tap($github)->update(['seed_example' => true])));
    }

    public function test_the_launcher_starts_the_editor_and_opens_it(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $website = Website::factory()->create(['repository' => 'https://github.com/acme/shop.git']);
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturn(null);
        $editors->shouldReceive('canMoveToOwnUser')->andReturn(false);
        $editors->shouldReceive('start')->once()->andReturn(['url' => 'https://panel.test/code/'.str_repeat('c', 32).'/?folder=%2Fw', 'installing' => null]);
        $this->app->instance(CodeServer::class, $editors);

        Livewire::test(VsCode::class, ['website' => $website])
            ->assertSee('Starting VS Code')
            ->call('launch')
            ->assertRedirect('https://panel.test/code/'.str_repeat('c', 32).'/?folder=%2Fw');
    }

    public function test_the_launcher_explains_why_it_cannot_start(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $website = Website::factory()->create(['repository' => 'https://github.com/acme/shop.git']);
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturn('VS Code runs as the website\'s own user.');
        $editors->shouldReceive('canMoveToOwnUser')->andReturn(false);
        $editors->shouldNotReceive('start');
        $this->app->instance(CodeServer::class, $editors);

        Livewire::test(VsCode::class, ['website' => $website])
            ->assertSee('VS Code runs as the website')
            ->assertDontSee('quick editor');
    }

    public function test_the_launcher_moves_a_shared_site_to_its_own_user_and_opens_vs_code(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $website = Website::factory()->create(['slug' => 'shop', 'repository' => 'https://github.com/acme/shop.git']);
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturn('VS Code runs as the website\'s own user, and this website still runs as the shared user.', null);
        $editors->shouldReceive('canMoveToOwnUser')->andReturn(true, true, false);
        $editors->shouldReceive('start')->once()->andReturn(['url' => 'https://panel.test/code/'.str_repeat('e', 32).'/', 'installing' => null]);
        $this->app->instance(CodeServer::class, $editors);
        Artisan::shouldReceive('call')->once()->with('laravelify:separate-sites', ['--site' => ['shop']])->andReturn(0);

        Livewire::test(VsCode::class, ['website' => $website])
            ->assertSee('Move to its own user and open VS Code')
            ->call('moveToOwnUser')
            ->assertRedirect('https://panel.test/code/'.str_repeat('e', 32).'/');
    }

    public function test_a_running_editor_opens_at_once_without_a_restart(): void
    {
        $website = Website::factory()->create(['repository' => 'https://github.com/acme/shop.git', 'code_server_token' => str_repeat('d', 32), 'code_server_port' => 13123]);
        $host = Mockery::mock(HostWriter::class);
        $host->shouldReceive('codeServerStatus')->andReturn('active 12');
        $host->shouldNotReceive('codeServerInstall', 'codeServerStart');
        $this->app->instance(HostWriter::class, $host);
        $editors = Mockery::mock(CodeServer::class, [
            $host, app(ProjectWorkspace::class), app(PortAllocator::class), app(SiteFiles::class), app(SiteUser::class), app(GithubAppClient::class), app(LiveServer::class),
        ])->makePartial();
        $editors->shouldReceive('unavailableReason')->andReturn(null);

        $result = $editors->start($website, 'Ada');

        $this->assertNull($result['installing']);
        $this->assertStringContainsString('/code/'.str_repeat('d', 32).'/', $result['url']);
    }
}
