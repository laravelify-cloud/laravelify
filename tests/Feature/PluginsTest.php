<?php

namespace Tests\Feature;

use App\Jobs\RunPanelTask;
use App\Livewire\Plugins\Index;
use App\Models\BackupSchedule;
use App\Models\DatabaseConnection;
use App\Models\PanelTask;
use App\Models\Plugin;
use App\Models\User;
use App\Models\Website;
use App\Services\BackupManager;
use App\Services\HostWriter;
use App\Services\PanelTaskRunner;
use App\Services\PluginManager;
use App\Services\PortAllocator;
use App\Services\SiteSecurity;
use App\Services\TwoFactor;
use App\Support\Plugins;
use App\Support\SidebarLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PluginsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
    }

    private function host(): MockInterface
    {
        $host = Mockery::mock(HostWriter::class);
        $host->shouldReceive('dockerInstall')->byDefault()->andReturn('Docker is installed.');
        $host->shouldReceive('writeWebsite')->byDefault()->andReturn('');
        $this->app->instance(HostWriter::class, $host);

        return $host;
    }

    public function test_the_page_lists_every_plugin_and_install_runs_in_the_background(): void
    {
        Queue::fake();
        $this->signIn();

        Livewire::test(Index::class)
            ->assertSee(['PostgreSQL', 'Redis', 'MinIO', 'HashiCorp Vault'])
            ->assertSee('Not installed')
            ->call('install', 'minio');

        $task = PanelTask::query()->sole();
        $this->assertSame(['plugins.install', 'plugin:minio', ['key' => 'minio']], [$task->kind, $task->scope, $task->payload]);
        Queue::assertPushed(RunPanelTask::class);
    }

    public function test_install_picks_free_ports_and_keeps_credentials_encrypted(): void
    {
        Website::factory()->create(['port' => 6380]);
        $host = $this->host();
        $host->shouldReceive('pluginUp')->once()->withArgs(function (string $key, string $compose, array $env): bool {
            return $key === 'redis'
                && str_contains($compose, '"127.0.0.1:6381:6379"')
                && strlen($env['REDIS_PASSWORD']) === 32;
        })->andReturn('');

        $plugin = app(PluginManager::class)->install('redis');

        $this->assertSame(['redis' => 6381], $plugin->ports);
        $this->assertSame(Plugin::RUNNING, $plugin->status);
        $password = $plugin->credentials['password'];
        $this->assertStringNotContainsString($password, (string) DB::table('plugins')->value('credentials'));
        $this->assertArrayNotHasKey('credentials', $plugin->toArray());

        // Ports a plugin holds are never given to a website.
        $this->assertSame(6382, app(PortAllocator::class)->next(6381));
    }

    public function test_vault_is_initialized_and_unsealed_on_install(): void
    {
        $host = $this->host();
        $host->shouldReceive('pluginUp')->once()->andReturn('');
        $host->shouldReceive('vaultInit')->once()->with('vault')->andReturn(['unseal_keys' => ['k1', 'k2', 'k3', 'k4', 'k5'], 'root_token' => 'hvs.roottoken123']);
        $host->shouldReceive('vaultUnseal')->once()->with('vault', ['k1', 'k2', 'k3'])->andReturn('');
        $host->shouldReceive('vaultSealed')->once()->with('vault')->andReturn(false);

        $plugin = app(PluginManager::class)->install('vault');

        $this->assertSame('hvs.roottoken123', $plugin->credentials['root_token']);
        $this->assertFalse($plugin->sealed);
        $this->assertNull($plugin->credentials_shown_at);
        $this->assertSame(['VAULT_ADDR' => 'http://127.0.0.1:8200', 'VAULT_TOKEN' => 'hvs.roottoken123'], Plugins::find('vault')->websiteEnv($plugin->ports, $plugin->credentials));
    }

    public function test_a_failed_install_never_shows_the_secrets(): void
    {
        $host = $this->host();
        $host->shouldReceive('pluginUp')->once()->andReturnUsing(function (string $key, string $compose, array $env): string {
            throw new RuntimeException('compose failed with '.$env['POSTGRES_PASSWORD']);
        });

        $task = PanelTask::query()->create(['kind' => 'plugins.install', 'scope' => 'plugin:postgres', 'label' => 'Installing PostgreSQL', 'payload' => ['key' => 'postgres']]);
        app(PanelTaskRunner::class)->run($task);

        $plugin = Plugin::query()->sole();
        $password = $plugin->credentials['password'];
        $this->assertSame(PanelTask::FAILED, $task->fresh()->status);
        $this->assertStringNotContainsString($password, (string) $task->fresh()->message);
        $this->assertStringNotContainsString($password, (string) $plugin->last_error);
        $this->assertSame(Plugin::ERROR, $plugin->status);
    }

    public function test_linking_sets_the_website_variables_and_unlinking_removes_them(): void
    {
        $this->host()->shouldReceive('writeWebsite')->twice()->andReturn('');
        $plugin = Plugin::factory()->create();
        $website = Website::factory()->create(['env_content' => "APP_NAME=Shop\nREDIS_HOST=old\n"]);
        $manager = app(PluginManager::class);

        $manager->link($plugin, $website);

        $env = (string) $website->fresh()->env_content;
        $this->assertStringContainsString('REDIS_URL=redis://:secretredispassword@127.0.0.1:6380', $env);
        $this->assertStringContainsString('REDIS_HOST=127.0.0.1', $env);
        $this->assertTrue($plugin->websites()->whereKey($website->id)->exists());

        $manager->unlink($plugin, $website->fresh());

        $this->assertSame('APP_NAME=Shop', trim((string) $website->fresh()->env_content));
        $this->assertFalse($plugin->websites()->exists());
    }

    public function test_new_credentials_are_shown_once(): void
    {
        $this->signIn();
        Plugin::factory()->create(['credentials_shown_at' => null]);

        $page = Livewire::test(Index::class)
            ->assertSet('credentialsFor', 'redis')
            ->assertSee(['Redis credentials', 'secretredispassword', 'Copy all', 'Download .txt']);

        $page->call('downloadCredentials')->assertFileDownloaded('laravelify-redis-credentials.txt', contentType: 'text/plain; charset=UTF-8');

        $page->call('savedCredentials')
            ->assertSet('credentialsFor', null)
            ->assertDontSee('secretredispassword');

        $this->assertNotNull(Plugin::query()->sole()->credentials_shown_at);
    }

    public function test_the_vault_download_groups_the_unseal_keys(): void
    {
        $this->signIn();
        Plugin::factory()->create(['key' => 'vault', 'ports' => ['vault' => 8200], 'credentials_shown_at' => null, 'credentials' => ['unseal_keys' => ['k1', 'k2', 'k3', 'k4', 'k5'], 'root_token' => 'hvs.root']]);
        $host = $this->host();
        $host->shouldReceive('pluginStatus')->andReturn('running');
        $host->shouldReceive('vaultSealed')->andReturn(false);

        $page = Livewire::test(Index::class)
            ->assertSee(['Unseal keys', 'Any 3 of these 5 keys'])
            ->call('downloadCredentials')
            ->assertFileDownloaded('laravelify-vault-credentials.txt');
        $text = (new \ReflectionMethod(Index::class, 'credentialsText'))->invoke($page->instance(), Plugin::query()->sole());

        $this->assertStringContainsString("Root token: hvs.root\n", $text);
        $this->assertStringContainsString("Unseal keys (any 3 of these 5 unseal Vault):\n  1. k1\n", $text);
        $this->assertStringContainsString('  5. k5', $text);
        $this->assertNull(Plugin::query()->sole()->credentials_shown_at, 'downloading does not close it; "I saved them" does');
    }

    public function test_uninstall_asks_for_the_name_and_unlinks_websites(): void
    {
        Queue::fake();
        $this->signIn();
        Plugin::factory()->create();

        Livewire::test(Index::class)
            ->call('uninstall', 'redis', true)
            ->assertHasErrors('confirmName')
            ->set('confirmName', 'redis')
            ->call('uninstall', 'redis', true);

        $task = PanelTask::query()->sole();
        $this->assertSame(['plugins.uninstall', ['key' => 'redis', 'purge' => true]], [$task->kind, $task->payload]);

        $website = Website::factory()->create(['env_content' => "REDIS_URL=x\n"]);
        $plugin = Plugin::query()->sole();
        $plugin->websites()->attach($website->id, ['env_keys' => json_encode(['REDIS_URL'])]);
        $host = $this->host();
        $host->shouldReceive('pluginRemove')->once()->with('redis', true)->andReturn('');

        app(PanelTaskRunner::class)->run($task);

        $this->assertSame(PanelTask::SUCCESS, $task->fresh()->status);
        $this->assertSame(0, Plugin::query()->count());
        $this->assertStringNotContainsString('REDIS_URL', (string) $website->fresh()->env_content);
    }

    public function test_auto_unseal_unseals_a_sealed_vault(): void
    {
        $host = $this->host();
        Plugin::factory()->create(['key' => 'vault', 'ports' => ['vault' => 8200], 'auto_unseal' => true, 'sealed' => true, 'credentials' => ['unseal_keys' => ['a', 'b', 'c', 'd', 'e'], 'root_token' => 't']]);
        $host->shouldReceive('pluginStatus')->with('vault')->andReturn('running');
        $host->shouldReceive('vaultSealed')->with('vault')->andReturn(true, false);
        $host->shouldReceive('vaultUnseal')->once()->with('vault', ['a', 'b', 'c'])->andReturn('');

        $this->artisan('laravelify:unseal-plugins')->assertSuccessful();

        $this->assertFalse(Plugin::query()->sole()->sealed);
    }

    public function test_a_plugin_page_opens_on_a_domain_behind_basic_auth(): void
    {
        $host = $this->host();
        $plugin = Plugin::factory()->create(['key' => 'minio', 'ports' => ['api' => 9000, 'console' => 9001]]);
        $host->shouldReceive('pluginDomain')->once()->withArgs(function (string $key, string $domain, int $port, string $htpasswd): bool {
            return [$key, $domain, $port] === ['minio', 'minio.example.com', 9001]
                && preg_match('/\A[a-z]+:\$6\$[A-Za-z0-9.\/$]{100,}\n\z/', $htpasswd) === 1
                && ! str_contains($htpasswd, 'a-long-password');
        })->andReturn('');
        $host->shouldReceive('pluginDomainRemove')->once()->with('minio')->andReturn('');
        $manager = app(PluginManager::class);

        $manager->setDomain($plugin, 'Minio.Example.com', null, 'admin', 'a-long-password');

        $this->assertSame(['minio.example.com', 'admin'], [$plugin->fresh()->domain, $plugin->fresh()->basic_auth_user]);
        $this->assertTrue(app(SiteSecurity::class)->verify(new Website(['basic_auth_hash' => $plugin->fresh()->basic_auth_hash]), 'a-long-password'));

        $manager->removeDomain($plugin->fresh());

        $this->assertNull($plugin->fresh()->domain);
    }

    public function test_a_domain_needs_a_web_page_and_a_strong_password(): void
    {
        $this->host()->shouldNotReceive('pluginDomain');
        $manager = app(PluginManager::class);

        $this->expectException(RuntimeException::class);
        $manager->setDomain(Plugin::factory()->create(['key' => 'minio', 'ports' => ['api' => 9000, 'console' => 9001]]), 'minio.example.com', null, 'admin', 'short');
    }

    public function test_plugins_are_backed_up_with_the_databases(): void
    {
        Plugin::factory()->create(['key' => 'postgres', 'ports' => ['postgres' => 5433]]);
        $schedule = BackupSchedule::factory()->create(['kind' => BackupSchedule::KIND_DATABASE, 'targets' => ['all' => false, 'databases' => ['plugin:postgres', 'plugin:redis']]]);

        $labels = array_column(app(BackupManager::class)->targets($schedule), 'label');

        $this->assertSame(['plugin:postgres'], $labels);
    }

    public function test_the_postgres_plugin_shows_on_the_database_page_until_it_is_removed(): void
    {
        $host = $this->host();
        $plugin = Plugin::factory()->create(['key' => 'postgres', 'ports' => ['postgres' => 5433], 'credentials' => ['database' => 'app', 'username' => 'app', 'password' => 'pgsecret123']]);
        $manager = app(PluginManager::class);

        $manager->syncConnections();
        $manager->syncConnections();

        $connection = DatabaseConnection::query()->sole();
        $this->assertSame(['plugin-postgres', 'pgsql', '127.0.0.1', 5433, 'app'], [$connection->slug, $connection->driver, $connection->host, $connection->port, $connection->username]);
        $this->assertSame('pgsecret123', $connection->password);
        $this->assertStringNotContainsString('pgsecret123', (string) DB::table('database_connections')->value('password'));

        $host->shouldReceive('pluginRemove')->once()->andReturn('');
        $host->shouldReceive('pluginDomainRemove')->andReturn('');
        $manager->uninstall($plugin, false);

        $this->assertSame(0, DatabaseConnection::query()->count());
    }

    public function test_every_plugin_compose_file_follows_the_host_rules(): void
    {
        foreach (Plugins::all() as $key => $definition) {
            $compose = $definition->compose($definition->ports());

            $this->assertMatchesRegularExpression('/^  '.$key.':\r?$/m', $compose, $key.' has a service named like the plugin');
            $this->assertDoesNotMatchRegularExpression('/privileged|network_mode|docker\.sock|^\s*build:/mi', $compose, $key);
            preg_match_all('/^\s*-\s*"?[0-9.]*:?[0-9]+:[0-9]+"?\s*$/m', $compose, $ports);
            foreach ($ports[0] as $line) {
                $this->assertMatchesRegularExpression('/^\s*-\s*"127\.0\.0\.1:\d{4,5}:\d{2,5}"\s*$/', $line, $key);
            }
            foreach ($definition->containerEnv($definition->credentials()) as $name => $value) {
                $this->assertMatchesRegularExpression('/\A[A-Z][A-Z0-9_]*=[A-Za-z0-9._:@\/+=-]*\z/', $name.'='.$value, $key);
            }
        }
    }

    public function test_the_glitchtip_card_installs_the_panels_own_glitchtip(): void
    {
        Queue::fake();
        $this->signIn();

        Livewire::test(Index::class)
            ->assertSee('GlitchTip')
            ->call('installGlitchtip')
            ->call('installGlitchtip');

        $task = PanelTask::query()->sole();
        $this->assertSame(['glitchtip.install', 'glitchtip'], [$task->kind, $task->scope]);
        $this->assertNull(Plugins::get('glitchtip'), 'there is no second, Docker GlitchTip');
    }

    public function test_running_plugins_with_a_web_page_are_linked_in_the_sidebar(): void
    {
        Plugin::factory()->create(['key' => 'vault', 'ports' => ['vault' => 8200], 'credentials' => ['root_token' => 't']]);
        Plugin::factory()->create(['key' => 'minio', 'ports' => ['api' => 9000, 'console' => 9001], 'domain' => 'minio.example.com', 'basic_auth_user' => 'admin']);
        Plugin::factory()->create(['key' => 'redis']);

        $local = SidebarLinks::plugins('localhost');
        $this->assertSame(['https://minio.example.com/', 'http://127.0.0.1:8200/ui/'], array_column($local, 'url'), 'Redis has no web page');
        $this->assertSame(['MinIO console', 'Vault UI'], array_column($local, 'label'));

        // On another address the 127.0.0.1 port cannot be reached: the Plugins page, to set a domain.
        $this->assertSame(route('plugins.index'), SidebarLinks::plugins('95.86.158.97')[1]['url']);
    }

    public function test_credentials_are_shown_again_only_for_ten_minutes_after_an_authenticator_code(): void
    {
        $this->signIn();
        Plugin::factory()->create(['credentials_shown_at' => now()]);

        $page = Livewire::test(Index::class)
            ->call('showCredentials', 'redis')
            ->assertSet('credentialsFor', null)
            ->assertDontSee('secretredispassword');

        $code = app(TwoFactor::class)->currentCode(auth()->user());
        $this->assertTrue($page->call('unlockSecrets', 'bad123')->effects['returns'][0]['ok'] === false);
        $page->call('unlockSecrets', $code)
            ->call('showCredentials', 'redis')
            ->assertSet('reviewing', true)
            ->assertSee(['secretredispassword', 'Close'])
            ->assertDontSee('I saved them');

        $page->call('downloadCredentials')->assertFileDownloaded('laravelify-redis-credentials.txt');

        $this->travel(11)->minutes();
        $page->call('$refresh')->assertSet('credentialsFor', null)->assertDontSee('secretredispassword');
    }

    public function test_minio_opens_its_s3_api_on_a_public_domain_beside_the_console(): void
    {
        $this->signIn();
        $host = $this->host();
        $plugin = Plugin::factory()->create(['key' => 'minio', 'ports' => ['api' => 9000, 'console' => 9001], 'domain' => 's3-ui.example.com', 'basic_auth_user' => 'admin']);
        Website::factory()->create(['domain' => 'taken.example.com']);
        $host->shouldReceive('pluginApiDomain')->once()->with('minio', 's3.example.com', 9000)->andReturn('');
        $host->shouldReceive('pluginApiDomainRemove')->once()->with('minio')->andReturn('');

        Livewire::test(Index::class)
            ->call('editDomain', 'minio', 'api')
            ->assertSee('S3 API on a domain')
            ->set('domain', 'S3-UI.example.com')
            ->call('saveDomain')
            ->assertHasErrors('domain')->assertSee('s3-ui.example.com is already used by a plugin.')
            ->set('domain', 'taken.example.com')
            ->call('saveDomain')
            ->assertHasErrors('domain')->assertSee('taken.example.com is already used by a website.')
            ->set('domain', 's3.example.com')
            ->call('saveDomain')
            ->assertHasNoErrors()
            ->assertSee(['s3-ui.example.com', 's3.example.com', '127.0.0.1:9000']);

        $this->assertSame('s3.example.com', $plugin->fresh()->api_domain);

        Livewire::test(\App\Livewire\Domains\Index::class)
            ->assertSee(['s3.example.com', 'MinIO S3 API (public)', 's3-ui.example.com', 'MinIO console (basic auth)']);

        app(PluginManager::class)->removeApiDomain($plugin->fresh());
        $this->assertNull($plugin->fresh()->api_domain);
    }
}
