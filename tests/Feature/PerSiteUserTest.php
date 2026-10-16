<?php

namespace Tests\Feature;

use App\Models\TrashItem;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\ConfigRenderer;
use App\Services\HostWriter;
use App\Services\SiteFiles;
use App\Services\SiteUser;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Every website runs as its own Unix user (lfy-<slug>) once its folder belongs to that user;
 * sites from before keep the shared www-data until they are moved.
 */
class PerSiteUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The server after scripts/privileges.sh, with these site folders owned by these users.
     *
     * @param  array<string, string>  $owners  site folder => owner
     */
    private function serverWithOwners(array $owners): ArrayObject
    {
        $owners = new ArrayObject($owners);

        $this->app->instance(SiteUser::class, new class(new CommandRunner, $owners) extends SiteUser
        {
            public function __construct(CommandRunner $runner, private ArrayObject $owners)
            {
                parent::__construct($runner);
            }

            public function separate(): bool
            {
                return true;
            }

            protected function ownerOf(string $path): ?string
            {
                return $this->owners[$path] ?? null;
            }
        });

        return $owners;
    }

    /**
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

    public function test_deploy_commands_of_a_site_with_its_own_user_run_as_that_user_with_its_own_home(): void
    {
        $this->serverWithOwners(['/var/www/shop' => 'lfy-shop']);
        $website = Website::factory()->create(['slug' => 'shop']);
        $runner = $this->recordingRunner();

        $runner->actAs(app(SiteUser::class)->for($website));
        $runner->runAsSite(['composer', 'install'], '/var/www/shop');

        $command = $runner->commands[0];
        $this->assertSame(['sudo', '-n', '-u', 'lfy-shop', '--', '/usr/bin/env', '-i'], array_slice($command, 0, 7));
        $this->assertContains('HOME=/var/lib/laravelify/sites/shop/home', $command);
        $this->assertContains('TMPDIR=/var/lib/laravelify/sites/shop/tmp', $command);
        $this->assertContains('USER=lfy-shop', $command);
    }

    public function test_a_site_whose_folder_still_belongs_to_www_data_keeps_the_shared_user(): void
    {
        $this->serverWithOwners(['/var/www/shop' => 'www-data', '/var/www/blog' => 'lfy-shop']);

        $shop = app(SiteUser::class)->forSlug('shop');
        // A folder owned by another site's user does not make it this site's user.
        $blog = app(SiteUser::class)->forSlug('blog');

        $this->assertFalse($shop->isOwn());
        $this->assertSame('www-data', $shop->name());
        $this->assertSame('/var/lib/laravelify/home', $shop->home());
        $this->assertSame('www-data', $blog->name());
    }

    public function test_a_long_slug_gets_a_user_name_of_at_most_32_characters(): void
    {
        $this->assertSame('lfy-shop', SiteUser::userName('shop'));
        $this->assertSame('lfy-a-very-long-website-n-1a09c1', SiteUser::userName('a-very-long-website-name-for-testing'));
    }

    public function test_file_changes_run_as_the_user_of_the_site_the_path_is_in(): void
    {
        $this->serverWithOwners(['/var/www/shop' => 'lfy-shop', '/var/www/blog' => 'www-data']);
        $files = app(SiteFiles::class);

        $this->assertSame('lfy-shop', $files->userOf('/var/www/shop/public/index.php')->name());
        $this->assertSame('lfy-shop', $files->userOf('/var/lib/laravelify/sites/shop/trash/4')->name());
        $this->assertSame('www-data', $files->userOf('/var/www/blog/index.php')->name());
        $this->assertSame('www-data', $files->userOf('/etc/hosts')->name());
    }

    public function test_files_cannot_be_moved_between_a_site_with_its_own_user_and_another_site(): void
    {
        $this->serverWithOwners(['/var/www/shop' => 'lfy-shop', '/var/www/blog' => 'www-data']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Files cannot be moved or copied between two websites');

        app(SiteFiles::class)->move('/var/www/shop/.env', '/var/www/blog/.env');
    }

    public function test_supervisor_programs_of_a_site_with_its_own_user_run_as_it_with_its_home(): void
    {
        $config = "[program:shop-socketio]\ncommand=node server.js\nuser=www-data\nenvironment=HOME=\"/var/www\"\n";

        $own = ConfigRenderer::secureSupervisor($config, 'shop', 'lfy-shop', '/var/lib/laravelify/sites/shop/home');
        $back = ConfigRenderer::secureSupervisor($own, 'shop');

        $this->assertStringContainsString('user=lfy-shop', $own);
        $this->assertStringContainsString('environment=HOME="/var/lib/laravelify/sites/shop/home"', $own);
        $this->assertStringContainsString('user=www-data', $back);
        $this->assertStringContainsString('environment=HOME="/var/www"', $back);
    }

    public function test_a_php_site_with_its_own_user_sends_php_to_its_own_pool_and_back_when_it_moves_back(): void
    {
        config(['laravelify.host_commands' => false]);
        $owners = $this->serverWithOwners(['/var/www/shop' => 'lfy-shop']);
        $website = Website::factory()->create([
            'type' => 'php',
            'slug' => 'shop',
            'php_version' => '8.3',
            'nginx_config' => "server {\n    location ~ \\.php\$ {\n        fastcgi_pass unix:/run/php/php8.3-fpm.sock;\n    }\n}\n",
        ]);

        app(HostWriter::class)->writeWebsite($website);
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/site-shop.sock;', $website->refresh()->nginx_config);

        $owners['/var/www/shop'] = 'www-data';
        app(HostWriter::class)->writeWebsite($website);
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.3-fpm.sock;', $website->refresh()->nginx_config);
    }

    public function test_deleted_files_of_a_site_with_its_own_user_are_kept_in_its_own_trash(): void
    {
        $this->serverWithOwners(['/var/www/shop' => 'lfy-shop']);
        $own = TrashItem::factory()->create(['site_slug' => 'shop']);
        $shared = TrashItem::factory()->create(['site_slug' => null]);

        $this->assertSame('/var/lib/laravelify/sites/shop/trash'.DIRECTORY_SEPARATOR.$own->id, $own->folder());
        $this->assertSame('/var/lib/laravelify/trash'.DIRECTORY_SEPARATOR.$shared->id, $shared->folder());
    }

    public function test_moving_a_site_to_its_own_user_takes_its_trash_along_and_writes_the_site_again(): void
    {
        $owners = $this->serverWithOwners(['/var/www/shop' => 'www-data']);
        $root = str_replace('\\', '/', base_path());
        config(['laravelify.sites_root' => $root]);
        $website = Website::factory()->create(['slug' => 'shop', 'deploy_path' => base_path()]);
        $owners[$root.'/shop'] = 'www-data';
        $fromShop = TrashItem::factory()->create(['scope' => 'files:website:'.$website->id, 'site_slug' => null]);
        $elsewhere = TrashItem::factory()->create(['scope' => 'files:website:'.($website->id + 1), 'site_slug' => null]);

        $this->mock(HostWriter::class, function (MockInterface $mock) use ($owners, $fromShop, $root): void {
            $mock->shouldReceive('separateSite')->once()
                ->withArgs(fn (Website $site, array $trash) => $site->slug === 'shop' && $trash === [$fromShop->id])
                ->andReturnUsing(function () use ($owners, $root): string {
                    $owners[$root.'/shop'] = 'lfy-shop';

                    return 'shop now runs as lfy-shop.';
                });
            $mock->shouldReceive('writeWebsite')->once()->andReturn('ok');
        });

        $this->artisan('laravelify:separate-sites', ['--site' => ['shop']])
            ->expectsOutputToContain('shop: runs as lfy-shop.')
            ->assertSuccessful();

        $this->assertSame('shop', $fromShop->refresh()->site_slug);
        $this->assertNull($elsewhere->refresh()->site_slug);
    }

    public function test_giving_a_site_back_to_www_data_moves_its_trash_to_the_shared_trash(): void
    {
        $owners = $this->serverWithOwners([]);
        $root = str_replace('\\', '/', base_path());
        config(['laravelify.sites_root' => $root]);
        $website = Website::factory()->create(['slug' => 'shop', 'deploy_path' => base_path()]);
        $owners[$root.'/shop'] = 'lfy-shop';
        $item = TrashItem::factory()->create(['site_slug' => 'shop']);

        $this->mock(HostWriter::class, function (MockInterface $mock) use ($owners, $root): void {
            $mock->shouldReceive('unseparateSite')->once()->andReturnUsing(function () use ($owners, $root): string {
                $owners[$root.'/shop'] = 'www-data';

                return 'shop runs as www-data.';
            });
            $mock->shouldReceive('writeWebsite')->once()->andReturn('ok');
        });

        $this->artisan('laravelify:separate-sites', ['--site' => ['shop'], '--undo' => true])
            ->expectsOutputToContain('shop: runs as www-data again.')
            ->assertSuccessful();

        $this->assertNull($item->refresh()->site_slug);
    }
}
