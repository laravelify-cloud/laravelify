<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\ServerService;
use App\Models\TrashItem;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\ConfigRenderer;
use App\Services\HostWriter;
use App\Services\SiteLogs;
use App\Services\SiteUser;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The panel runs as its own user; websites, their workers, and deploy commands run as www-data.
 */
class PrivilegeSeparationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The server layout after scripts/privileges.sh: the panel is not www-data.
     */
    private function panelHasItsOwnUser(): void
    {
        $this->app->instance(SiteUser::class, new class(new CommandRunner) extends SiteUser
        {
            public function separate(): bool
            {
                return true;
            }
        });
    }

    /**
     * A runner that records commands instead of running them.
     *
     * @return CommandRunner&object{commands: list<list<string>>}
     */
    private function recordingRunner(): CommandRunner
    {
        return new class extends CommandRunner
        {
            /** @var list<list<string>> */
            public array $commands = [];

            public function supportsHostCommands(): bool
            {
                return true;
            }

            public function run(array $command, ?string $cwd = null, array $env = [], ?callable $onOutput = null): CommandResult
            {
                $this->commands[] = $command;

                return new CommandResult(true, false, $command, 'done', 0);
            }
        };
    }

    public function test_deploy_commands_run_as_the_site_user_with_only_a_clean_environment(): void
    {
        $this->panelHasItsOwnUser();
        $_ENV['APP_KEY'] = 'base64:panel-key';
        $runner = $this->recordingRunner();

        try {
            $result = $runner->runAsSite(['composer', 'install'], '/var/www/shop');
        } finally {
            unset($_ENV['APP_KEY']);
        }

        $command = $runner->commands[0];
        $this->assertSame(['sudo', '-n', '-u', 'www-data', '--', '/usr/bin/env', '-i'], array_slice($command, 0, 7));
        $this->assertSame(['composer', 'install'], array_slice($command, -2));
        $this->assertContains('HOME=/var/lib/laravelify/home', $command);
        $this->assertContains('USER=www-data', $command);
        $this->assertEmpty(preg_grep('/^APP_KEY=/', $command));
        // The deploy log shows the command itself, not the sudo wrapper.
        $this->assertSame(['composer', 'install'], $result->command);
    }

    public function test_only_the_panel_user_writes_through_sudo(): void
    {
        config(['laravelify.host_commands' => true]);

        $as = function (?string $user): SiteUser {
            return new class(new CommandRunner, $user) extends SiteUser
            {
                public function __construct(CommandRunner $runner, private ?string $user)
                {
                    parent::__construct($runner);
                }

                protected function currentUser(): ?string
                {
                    return $this->user;
                }
            };
        };

        $this->assertFalse($as('runner')->separate());
        $this->assertFalse($as('www-data')->separate());
        $this->assertFalse($as(null)->separate());
        $this->assertTrue($as('laravelify')->separate());
        $this->assertTrue($as('root')->separate());
    }

    public function test_site_commands_run_directly_while_the_panel_is_still_www_data(): void
    {
        $runner = $this->recordingRunner();

        $runner->runAsSite(['composer', 'install'], '/var/www/shop');

        $this->assertSame([['composer', 'install']], $runner->commands);
    }

    public function test_supervisor_programs_run_as_the_site_user_and_log_outside_the_site_folder(): void
    {
        $config = <<<'CONF'
[program:shop-queue]
command=php /var/www/shop/artisan queue:work
User : root
stdout_logfile=/var/www/shop/storage/logs/queue.log ; worker output

[program:shop-sync]
command=php /var/www/shop/artisan sync
stdout_logfile=AUTO
stderr_logfile=/root/.bashrc

CONF;

        $secured = ConfigRenderer::secureSupervisor($config, 'shop');

        $this->assertStringNotContainsString('root', str_replace('/var/www/shop', '', $secured));
        $this->assertSame(2, substr_count($secured, 'user=www-data'));
        $this->assertStringContainsString('stdout_logfile=/var/log/laravelify/sites/shop/queue.log', $secured);
        $this->assertStringContainsString('stdout_logfile=AUTO', $secured);
        $this->assertStringContainsString('stderr_logfile=/var/log/laravelify/sites/shop/.bashrc.log', $secured);
    }

    public function test_saving_a_laravel_site_moves_its_worker_logs_out_of_the_site_folder(): void
    {
        config(['laravelify.host_commands' => false]);
        $website = Website::factory()->create(['type' => 'laravel', 'slug' => 'shop']);
        $website->update(['process_config' => "[program:shop-queue]\ncommand=php artisan queue:work\nuser=www-data\nstdout_logfile=/var/www/shop/storage/logs/queue.log\n"]);

        app(HostWriter::class)->writeWebsite($website);

        $website->refresh();
        $this->assertStringContainsString('stdout_logfile=/var/log/laravelify/sites/shop/queue.log', $website->process_config);
        $this->assertSame($website->process_config, File::get(storage_path('app/rendered/sites/shop/process.conf')));
    }

    public function test_saving_processes_renames_older_programs_and_shows_what_was_applied(): void
    {
        config(['laravelify.host_commands' => false]);
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
        $website = Website::factory()->create(['type' => 'laravel', 'slug' => 'shop']);

        Livewire::test(WebsiteShow::class, ['website' => $website])
            ->set('process_config', "[program:balances-listen]\ncommand=php artisan balances:listen\n")
            ->call('saveProcess')
            ->assertHasNoErrors()
            ->assertSet('process_config', fn (string $config): bool => str_contains($config, '[program:shop-balances-listen]') && str_contains($config, 'user='));

        $this->assertStringContainsString('[program:shop-balances-listen]', File::get(storage_path('app/rendered/sites/shop/process.conf')));
    }

    public function test_the_logs_tab_reads_worker_logs_from_the_site_log_folder(): void
    {
        $logs = str_replace('\\', '/', storage_path('framework/testing/site-logs'));
        config(['laravelify.site_log_dir' => $logs]);
        File::ensureDirectoryExists($logs.'/shop');
        File::put($logs.'/shop/queue.log', "first\nlast job done\n");
        $site = str_replace('\\', '/', storage_path('framework/testing/site-root-shop'));
        File::ensureDirectoryExists($site.'/storage/logs');
        File::put($site.'/storage/logs/queue.log', "from before the move\n");
        File::put($site.'/storage/logs/callback.log', "callback\n");
        $website = Website::factory()->create(['type' => 'laravel', 'slug' => 'shop', 'deploy_path' => $site]);
        $website->update(['process_config' => "[program:shop-queue]\nuser=www-data\nstdout_logfile={$logs}/shop/queue.log\n"]);

        try {
            $sources = collect(app(SiteLogs::class)->sources($website))->keyBy('key');

            $this->assertSame($logs.'/shop/queue.log', $sources['program:shop-queue']['path']);
            $this->assertStringContainsString('last job done', app(SiteLogs::class)->tail($website, 'program:shop-queue', 10));
            $this->assertArrayNotHasKey('file:queue.log', $sources->all(), 'The old copy of a moved worker log is not listed twice.');
            $this->assertArrayHasKey('file:callback.log', $sources->all());
        } finally {
            File::deleteDirectory($logs);
            File::deleteDirectory($site);
        }
    }

    public function test_phpmyadmin_moves_to_the_panels_pool_once_the_panel_has_its_own_user(): void
    {
        $this->panelHasItsOwnUser();
        $service = ServerService::query()->updateOrCreate(['kind' => 'phpmyadmin'], [
            'name' => 'phpMyAdmin',
            'key' => 'phpmyadmin',
            'domain' => 'phpmyadmin.example.com',
            'settings' => ['php_version' => '8.3'],
            'config' => "server {\n    location ~ \\.php$ {\n        fastcgi_pass unix:/run/php/php8.3-fpm.sock;\n    }\n}\n",
        ]);

        $this->artisan('laravelify:adopt-panel-user')->assertSuccessful();

        $config = $service->refresh()->config;
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/laravelify.sock;', $config);
        $this->assertStringNotContainsString('php8.3-fpm.sock', $config);
    }

    public function test_the_trash_is_kept_outside_the_panels_storage_once_the_panel_has_its_own_user(): void
    {
        $this->panelHasItsOwnUser();
        $item = TrashItem::factory()->create();

        $this->assertSame('/var/lib/laravelify/trash'.DIRECTORY_SEPARATOR.$item->id, $item->folder());
    }
}
