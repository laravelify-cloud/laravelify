<?php

namespace Tests\Feature;

use App\Livewire\Websites\PhpConfig;
use App\Models\PanelTask;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\HostWriter;
use App\Services\PhpExtensions;
use App\Services\Runtimes;
use App\Services\TwoFactor;
use App\Support\NodeVersion;
use App\Support\PhpOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PhpConfigTest extends TestCase
{
    use RefreshDatabase;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
        $this->website = Website::factory()->create(['slug' => 'shop', 'type' => 'laravel', 'php_version' => '8.4']);

        $extensions = Mockery::mock(PhpExtensions::class)->makePartial();
        $extensions->shouldReceive('forVersion')->andReturnUsing(fn () => collect(PhpExtensions::catalog())->map(fn (array $extension, string $module) => [
            'module' => $module, 'label' => $extension['label'], 'help' => $extension['help'], 'installed' => false, 'enabled' => false,
        ])->values()->all());
        $this->app->instance(PhpExtensions::class, $extensions);
    }

    public function test_only_known_options_with_valid_values_reach_the_pool(): void
    {
        $encoded = PhpOptions::encode([
            'memory_limit' => '512M',
            'upload_max_filesize' => '64M',
            'display_errors' => 'off',
            'date.timezone' => 'Asia/Baku',
            'max_input_vars' => '5000',
            'disable_functions' => 'exec,system',
            'include_path' => '.:/usr/share/php; rm -rf /',
            'error_reporting' => '1',
            'expose_php' => 'yes',
            'post_max_size' => '64M; rm -rf /',
            'max_execution_time' => '',
        ]);

        $this->assertSame('display_errors=off;memory_limit=512M;upload_max_filesize=64M;max_input_vars=5000;date.timezone=Asia/Baku;disable_functions=exec,system', $encoded);
    }

    public function test_options_are_saved_and_written_to_the_site(): void
    {
        $writer = Mockery::mock(HostWriter::class);
        $writer->shouldReceive('writeWebsite')->once()->andReturn('ok');
        $this->app->instance(HostWriter::class, $writer);

        Livewire::test(PhpConfig::class, ['website' => $this->website])
            ->set('options.memory_limit', '512M')
            ->set('options.upload_max_filesize', '128M')
            ->set('options.post_max_size', '64M')
            ->call('saveOptions')
            ->assertHasErrors('options.post_max_size')
            ->set('options.post_max_size', '128M')
            ->set('options.max_execution_time', 'forever')
            ->call('saveOptions')
            ->assertHasErrors('options.max_execution_time')
            ->set('options.max_execution_time', '120')
            ->call('saveOptions')
            ->assertHasNoErrors();

        $this->assertSame(['memory_limit' => '512M', 'upload_max_filesize' => '128M', 'post_max_size' => '128M', 'max_execution_time' => '120'], $this->website->fresh()->php_options);
    }

    public function test_options_that_the_server_refuses_are_not_kept(): void
    {
        $writer = Mockery::mock(HostWriter::class);
        $writer->shouldReceive('writeWebsite')->once()->andThrow(new RuntimeException('php-fpm8.4 rejected the pool of shop'));
        $this->app->instance(HostWriter::class, $writer);

        Livewire::test(PhpConfig::class, ['website' => $this->website])
            ->set('options.memory_limit', '256M')
            ->call('saveOptions');

        $this->assertNull($this->website->fresh()->php_options);
    }

    public function test_a_new_php_version_is_rolled_back_when_it_cannot_be_used(): void
    {
        $writer = Mockery::mock(HostWriter::class);
        $writer->shouldReceive('writeWebsite')->once()->andThrow(new RuntimeException('PHP 8.2-FPM is not installed'));
        $this->app->instance(HostWriter::class, $writer);
        $this->installedPhp(['8.2', '8.4']);

        Livewire::test(PhpConfig::class, ['website' => $this->website])
            ->set('php_version', '8.2')
            ->call('saveVersion')
            ->assertSet('php_version', '8.4');

        $this->assertSame('8.4', $this->website->fresh()->php_version);
    }

    public function test_ticked_extensions_are_saved_together_in_the_background(): void
    {
        Queue::fake();

        Livewire::test(PhpConfig::class, ['website' => $this->website])
            ->set('section', 'extensions')
            ->set('extensionState.pdo_pgsql', true)
            ->set('extensionState.mongodb', true)
            ->assertSee('2 changes not saved yet')
            ->call('saveExtensions')
            ->call('resetExtensions')
            ->assertSee('Hover an extension');

        $task = PanelTask::query()->sole();
        $this->assertSame('php.extensions', $task->kind);
        $this->assertSame(['version' => '8.4', 'changes' => ['mongodb' => true, 'pdo_pgsql' => true]], $task->payload);
    }

    public function test_one_extension_that_fails_does_not_stop_the_others(): void
    {
        $writer = Mockery::mock(HostWriter::class);
        $writer->shouldReceive('phpExtension')->once()->with('8.4', 'xdebug', false, 'xdebug', null)->andReturn('ok');
        $writer->shouldReceive('phpExtension')->once()->with('8.4', 'swoole', true, 'swoole', null)->andThrow(new RuntimeException('php8.4-swoole could not be installed'));
        $writer->shouldReceive('phpExtension')->once()->with('8.4', 'redis', true, 'redis', null)->andReturn('ok');
        $this->app->instance(HostWriter::class, $writer);

        $result = app(PhpExtensions::class)->apply('8.4', ['swoole' => true, 'xdebug' => false, 'redis' => true]);

        $this->assertSame(['message' => 'PHP 8.4: −xdebug, +redis. Not changed: swoole (php8.4-swoole could not be installed).', 'failed' => false], $result);
    }

    public function test_on_off_options_are_kept_only_when_they_differ_from_php(): void
    {
        $writer = Mockery::mock(HostWriter::class);
        $writer->shouldReceive('writeWebsite')->once()->andReturn('ok');
        $this->app->instance(HostWriter::class, $writer);

        Livewire::test(PhpConfig::class, ['website' => $this->website])
            ->set('section', 'options')
            ->assertSee('session.cookie_httponly')
            ->set('flags.session__cookie_httponly', true)
            ->set('flags.expose_php', false)
            ->set('options.error_reporting', '32767')
            ->call('saveOptions')
            ->assertHasNoErrors();

        $this->assertSame(['session.cookie_httponly' => 'on', 'error_reporting' => '32767'], $this->website->fresh()->php_options);
    }

    public function test_a_running_extension_change_is_shown_after_a_reload_and_not_started_twice(): void
    {
        $task = PanelTask::query()->create(['kind' => 'php.extensions', 'scope' => 'php:'.$this->website->php_version, 'label' => 'Changing 2 extensions', 'status' => PanelTask::RUNNING, 'progress' => 0, 'message' => 'Compiling sqlsrv for PHP 8.4...']);

        $page = Livewire::withQueryParams(['php' => 'extensions'])
            ->test(PhpConfig::class, ['website' => $this->website])
            ->assertSee('Compiling sqlsrv for PHP 8.4...')
            ->assertSee('Working…')
            ->assertSet('extensionsRunning', true);

        $page->set('extensionState.redis', ! ($page->get('extensionState')['redis'] ?? false))->call('saveExtensions');
        $this->assertSame(1, PanelTask::query()->count());

        $task->update(['status' => PanelTask::FAILED, 'message' => 'PHP 8.4: nothing changed. Not changed: sqlsrv (PECL could not build sqlsrv).']);

        $page->call('$refresh')
            ->assertSet('extensionsRunning', false)
            ->assertSee('PECL could not build sqlsrv');
    }

    public function test_the_open_php_section_is_kept_in_the_address(): void
    {
        Livewire::withQueryParams(['php' => 'options'])
            ->test(PhpConfig::class, ['website' => $this->website])
            ->assertSet('section', 'options')
            ->assertSee('session.cookie_httponly')
            ->set('section', 'extensions')
            ->assertSetStrict('section', 'extensions');
    }

    public function test_an_extension_is_turned_on_with_its_package(): void
    {
        $writer = Mockery::mock(HostWriter::class);
        $writer->shouldReceive('phpExtension')->once()->with('8.4', 'pdo_pgsql', true, 'pgsql', null)->andReturn('ok');
        $this->app->instance(HostWriter::class, $writer);

        Livewire::test(PhpConfig::class, ['website' => $this->website])
            ->set('section', 'extensions')
            ->assertSee('pdo_pgsql')
            ->call('toggleExtension', 'pdo_pgsql');
    }

    public function test_the_site_command_carries_its_php_options(): void
    {
        $this->website->update(['php_options' => ['memory_limit' => '1G']]);
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('supportsHostCommands')->andReturn(true);
        $runner->shouldReceive('run')->once()->withArgs(fn (array $command) => in_array('memory_limit=1G', $command, true) && in_array('site', $command, true))
            ->andReturn(new CommandResult(true, false, [], 'ok', 0));
        $runner->shouldIgnoreMissing();
        $this->app->instance(CommandRunner::class, $runner);

        app(HostWriter::class)->writeWebsite($this->website->fresh());
    }

    public function test_a_php_version_the_server_lacks_is_installed_in_the_background(): void
    {
        $this->installedPhp(['8.4']);

        Livewire::test(PhpConfig::class, ['website' => $this->website])
            ->set('php_version', '7.4')
            ->call('saveVersion');

        $task = PanelTask::query()->where('kind', 'runtimes.php')->firstOrFail();
        $this->assertSame(['website_id' => $this->website->id, 'version' => '7.4'], $task->payload);
        // The task queue runs right away in tests: the install finished and the site moved.
        $this->assertSame('7.4', $this->website->fresh()->php_version);
    }

    public function test_a_deploy_installs_the_php_and_node_the_site_needs(): void
    {
        $writer = Mockery::mock(HostWriter::class);
        $writer->shouldReceive('phpInstall')->once()->with('7.4')->andReturn('ok');
        $writer->shouldReceive('nodeInstall')->once()->with('19.0.0')->andReturn('ok');
        $this->app->instance(HostWriter::class, $writer);
        $this->website->update(['php_version' => '7.4']);
        $node = Website::factory()->create(['slug' => 'web', 'type' => 'nextjs', 'php_version' => null, 'node_version' => '19.0.0']);

        $this->assertSame('Installed PHP 7.4 on this server for this website.', app(Runtimes::class)->ensure($this->website->fresh()));
        $this->assertSame('Installed Node.js 19.0.0 on this server for this website.', app(Runtimes::class)->ensure($node));
    }

    public function test_any_node_release_from_14_on_is_accepted(): void
    {
        foreach (['19', '19.4', '19.0.0', '24', '14.21.3'] as $good) {
            $this->assertMatchesRegularExpression(NodeVersion::PATTERN, $good);
        }
        foreach (['13', '9', '19.0.0.1', 'latest', '19;rm', ''] as $bad) {
            $this->assertDoesNotMatchRegularExpression(NodeVersion::PATTERN, $bad);
        }
    }

    /**
     * @param  list<string>  $versions
     */
    private function installedPhp(array $versions): void
    {
        $runtimes = Mockery::mock(Runtimes::class)->makePartial();
        $runtimes->shouldReceive('phpInstalled')->andReturnUsing(fn (string $version) => in_array($version, $versions, true));
        $this->app->instance(Runtimes::class, $runtimes);
    }
}
