<?php

namespace Tests\Feature;

use App\Jobs\RunBackupSchedule;
use App\Mcp\Servers\LaravelifyServer;
use App\Mcp\Tools\CancelDeployment;
use App\Mcp\Tools\CheckWebsite;
use App\Mcp\Tools\DeleteFile;
use App\Mcp\Tools\DeleteGroup;
use App\Mcp\Tools\DeleteWebsite;
use App\Mcp\Tools\GetEnvironment;
use App\Mcp\Tools\GetLogs;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ImportDatabase;
use App\Mcp\Tools\ListBackups;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListFiles;
use App\Mcp\Tools\ReadFile;
use App\Mcp\Tools\RestartWebsite;
use App\Mcp\Tools\RunBackup;
use App\Mcp\Tools\RunCommand;
use App\Mcp\Tools\ServerStatus;
use App\Mcp\Tools\SetDomains;
use App\Mcp\Tools\SyncCloudflare;
use App\Mcp\Tools\UpdateGroup;
use App\Mcp\Tools\UpdateWebsite;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Models\CloudflareTunnel;
use App\Models\Group;
use App\Models\PanelTask;
use App\Models\Setting;
use App\Models\TrashItem;
use App\Models\User;
use App\Models\Website;
use App\Services\CloudflareApi;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

class McpToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-mcp-tools-'.uniqid();
        File::ensureDirectoryExists($this->root.'/storage/logs');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function server(): PendingTestResponse
    {
        return LaravelifyServer::actingAs($this->admin);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function site(array $attributes = []): Website
    {
        return Website::factory()->create(['slug' => 'shop', 'deploy_path' => $this->root, 'domain' => 'shop.example.com', 'nginx_config' => 'server { server_name shop.example.com; }
'] + $attributes);
    }

    public function test_the_server_offers_every_tool_once_with_a_kebab_case_name_and_a_description(): void
    {
        $classes = collect((new \ReflectionProperty(LaravelifyServer::class, 'tools'))->getDefaultValue());

        $this->assertGreaterThanOrEqual(36, $classes->count());
        $this->assertSame($classes->count(), $classes->unique()->count());

        $names = $classes->map(function (string $class): string {
            $tool = new $class;
            $this->assertNotSame('', $tool->description(), $class.' has a description');

            return $tool->name();
        });

        $this->assertSame($names->count(), $names->unique()->count());
        $names->each(fn (string $name) => $this->assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)+$/', $name));
    }

    public function test_update_website_changes_only_what_is_given_and_refuses_what_does_not_apply(): void
    {
        $this->site(['type' => 'nextjs', 'php_version' => null, 'node_version' => '22', 'port' => 3000]);
        $server = $this->server();

        $server->tool(UpdateWebsite::class, ['website' => 'shop', 'branch' => 'production', 'build_command' => 'pnpm build', 'auto_deploy' => false])
            ->assertOk()->assertSee('Runtime settings changed');

        $website = Website::query()->sole();
        $this->assertSame('production', $website->branch);
        $this->assertSame('pnpm build', $website->build_command);
        $this->assertFalse($website->auto_deploy);

        $server->tool(UpdateWebsite::class, ['website' => 'shop', 'php_version' => '8.4'])->assertHasErrors(['has no php version']);
        $server->tool(UpdateWebsite::class, ['website' => 'shop', 'node_version' => '19'])->assertHasErrors();
        $server->tool(UpdateWebsite::class, ['website' => 'shop'])->assertHasErrors(['Give at least one setting']);
    }

    public function test_set_domains_gives_one_website_many_domains_and_finds_it_by_any_of_them(): void
    {
        $this->site();
        Website::factory()->create(['slug' => 'other', 'domain' => 'other.com', 'aliases' => ['www.other.com']]);
        $server = $this->server();

        $server->tool(SetDomains::class, ['website' => 'shop', 'include_www' => true, 'aliases' => ['second.com']])
            ->assertOk()->assertSee('shop.example.com')->assertSee('www.shop.example.com')->assertSee('second.com');

        $website = Website::query()->where('slug', 'shop')->sole();
        $this->assertSame(['second.com', 'www.shop.example.com'], $website->aliases);
        $this->assertStringContainsString('server_name shop.example.com second.com www.shop.example.com;', (string) $website->nginx_config);

        $server->tool(ListFiles::class, ['website' => 'second.com'])->assertOk();
        $server->tool(SetDomains::class, ['website' => 'shop', 'aliases' => ['www.other.com']])->assertHasErrors(['already belongs to the website']);
        $server->tool(SetDomains::class, ['website' => 'shop', 'aliases' => []])->assertOk();
        $this->assertNull($website->fresh()->aliases);
    }

    public function test_delete_website_needs_its_slug_removes_it_and_keeps_the_folder_and_database(): void
    {
        Setting::putValue(CloudflareApi::SETTING, 'cf_token');
        $tunnel = CloudflareTunnel::factory()->create(['token' => base64_encode((string) json_encode(['a' => 'acc', 't' => 'tun', 's' => 's']))]);
        $this->site(['cloudflare_tunnel_id' => $tunnel->id, 'provision_database' => true, 'db_name' => 'lv_shop']);
        Http::fake(fn (HttpRequest $request) => Http::response(['success' => true, 'errors' => [], 'result' => str_contains($request->url(), 'configurations') ? ['config' => ['ingress' => [['hostname' => 'shop.example.com', 'service' => 'http://127.0.0.1:80'], ['service' => 'http_status:404']]]] : []]));

        $this->server()->tool(DeleteWebsite::class, ['website' => 'shop', 'confirm' => 'wrong'])->assertHasErrors(['pass its slug "shop"']);
        $this->assertSame(1, Website::query()->count());

        $this->server()->tool(DeleteWebsite::class, ['website' => 'shop', 'confirm' => 'shop'])
            ->assertOk()->assertSee('the MySQL database lv_shop')->assertSee($this->root);

        $this->assertSame(0, Website::query()->count());
        $this->assertDirectoryExists($this->root);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT' && collect($request->data()['config']['ingress'])->pluck('hostname')->filter()->isEmpty());
    }

    public function test_groups_are_renamed_and_only_empty_ones_are_deleted(): void
    {
        $group = Group::factory()->create(['name' => 'Old']);
        $this->site(['group_id' => $group->id]);
        $server = $this->server();

        $server->tool(UpdateGroup::class, ['group' => 'Old', 'name' => 'New'])->assertOk()->assertSee('New');
        $server->tool(DeleteGroup::class, ['group' => 'New'])->assertHasErrors(['still has 1 website']);

        Website::query()->delete();
        $server->tool(DeleteGroup::class, ['group' => (string) $group->id])->assertOk();
        $this->assertSame(0, Group::query()->count());
    }

    public function test_environment_secrets_are_hidden_unless_revealed(): void
    {
        $this->site(['env_content' => "APP_NAME=Shop\nAPP_KEY=base64:abc\nDB_PASSWORD=hunter2\nMAIL_HOST=smtp.example.com\n"]);

        $this->server()->tool(GetEnvironment::class, ['website' => 'shop'])
            ->assertOk()->assertSee('smtp.example.com')->assertSee('********')->assertDontSee('hunter2')->assertDontSee('base64:abc');
        $this->server()->tool(GetEnvironment::class, ['website' => 'shop', 'keys' => ['DB_PASSWORD'], 'reveal' => true])->assertOk()->assertSee('hunter2');
    }

    public function test_deployments_are_listed_and_a_running_one_is_cancelled(): void
    {
        $website = $this->site();
        $website->deployments()->create(['status' => 'success', 'trigger' => 'webhook', 'log' => '']);
        $running = $website->deployments()->create(['status' => 'running', 'trigger' => 'mcp', 'log' => 'start']);

        $this->server()->tool(ListDeployments::class, ['website' => 'shop'])->assertOk()->assertSee('"trigger":"mcp"')->assertSee('"trigger":"webhook"');
        $this->server()->tool(CancelDeployment::class, ['website' => 'shop'])->assertOk();

        $this->assertSame('cancelled', $running->fresh()->status);
        $this->server()->tool(CancelDeployment::class, ['website' => 'shop'])->assertHasErrors(['has no deployment running']);
    }

    public function test_logs_are_listed_then_read_and_unknown_logs_are_refused(): void
    {
        $this->site();
        file_put_contents($this->root.'/storage/logs/laravel.log', "line one\nERROR something broke\n");
        $server = $this->server();

        $server->tool(GetLogs::class, ['website' => 'shop'])->assertOk()->assertSee('"key":"laravel"');
        $server->tool(GetLogs::class, ['website' => 'shop', 'source' => 'laravel'])->assertOk()->assertSee('ERROR something broke');
        $server->tool(GetLogs::class, ['website' => 'shop', 'source' => 'nope'])->assertHasErrors(['No log "nope"']);
    }

    public function test_files_are_listed_read_and_moved_to_the_trash_but_never_outside_the_site_or_env(): void
    {
        $this->site();
        file_put_contents($this->root.'/composer.json', '{"name":"acme/shop"}');
        file_put_contents($this->root.'/.env', 'APP_KEY=secret');
        File::ensureDirectoryExists($this->root.'/app');
        $server = $this->server();

        $server->tool(ListFiles::class, ['website' => 'shop'])->assertOk()->assertSee('"name":"app"')->assertSee('composer.json');
        $server->tool(ReadFile::class, ['website' => 'shop', 'path' => 'composer.json'])->assertOk()->assertSee('acme');
        $server->tool(ReadFile::class, ['website' => 'shop', 'path' => '.env'])->assertHasErrors(['get-environment']);
        $server->tool(ReadFile::class, ['website' => 'shop', 'path' => '../outside.txt'])->assertHasErrors();
        $server->tool(ListFiles::class, ['website' => 'shop', 'path' => '../'])->assertHasErrors();

        $server->tool(DeleteFile::class, ['website' => 'shop', 'path' => 'composer.json'])->assertOk()->assertSee('Trash');
        $this->assertFileDoesNotExist($this->root.'/composer.json');
        $this->assertSame(1, TrashItem::query()->count());
        $server->tool(DeleteFile::class, ['website' => 'shop', 'path' => ''])->assertHasErrors();
    }

    public function test_health_commands_restarts_and_server_status_answer(): void
    {
        $this->site();
        $server = $this->server();

        $server->tool(CheckWebsite::class, ['website' => 'shop'])->assertOk()->assertSee('overall');
        $server->tool(RunCommand::class, ['website' => 'shop', 'command' => 'php artisan about'])->assertOk()->assertSee('"ok":false')->assertSee('Host commands are disabled');
        $server->tool(RunCommand::class, ['website' => 'shop', 'command' => '  '])->assertHasErrors(['Enter a command']);
        $server->tool(RestartWebsite::class, ['website' => 'shop'])->assertOk();
        $server->tool(ServerStatus::class)->assertOk()->assertSee('services');
    }

    public function test_backups_are_listed_and_started_and_a_missing_storage_is_explained(): void
    {
        Queue::fake();
        $storage = BackupStorage::factory()->create();
        $schedule = BackupSchedule::factory()->create(['backup_storage_id' => $storage->id, 'name' => 'Nightly']);
        $server = $this->server();

        $server->tool(ListBackups::class)->assertOk()->assertSee('Nightly');
        $server->tool(RunBackup::class, ['schedule_id' => $schedule->id])->assertOk()->assertSee('Nightly');
        Queue::assertPushed(RunBackupSchedule::class);
        $server->tool(RunBackup::class, [])->assertHasErrors(['Give a schedule_id']);
        $server->tool(RunBackup::class, ['full' => 'nonsense'])->assertHasErrors(['Use "database" or "files"']);
    }

    public function test_cloudflare_sync_says_so_when_the_site_is_not_on_a_tunnel(): void
    {
        $this->site(['cloudflare_tunnel_id' => null]);

        $this->server()->tool(SyncCloudflare::class, ['website' => 'shop'])->assertOk()->assertSee('not on a Cloudflare tunnel');
    }

    public function test_a_dump_in_the_site_folder_is_imported_as_the_sites_own_database_user(): void
    {
        $this->site(['type' => 'php', 'provision_database' => true, 'db_name' => 'lv_shop', 'db_username' => 'lv_shop_u', 'db_password' => 'p@ss"word']);
        file_put_contents($this->root.'/dump.sql', 'CREATE TABLE t (id int);');
        $commands = new \ArrayObject;
        $this->mock(CommandRunner::class, function (MockInterface $mock) use ($commands) {
            $mock->shouldReceive('supportsHostCommands')->andReturnTrue();
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use ($commands) {
                $cnf = collect($command)->first(fn (string $part) => str_starts_with($part, '--defaults-extra-file='));
                $commands[] = ['command' => $command, 'defaults' => $cnf ? file_get_contents(substr($cnf, 22)) : null];

                return new CommandResult(true, false, $command, '', 0);
            });
        });
        $server = $this->server();

        $server->tool(ImportDatabase::class, ['website' => 'shop', 'path' => '../etc/dump.sql'])->assertHasErrors();
        $server->tool(ImportDatabase::class, ['website' => 'shop', 'path' => 'dump.txt'])->assertHasErrors(['.sql or .sql.gz']);
        $server->tool(ImportDatabase::class, ['website' => 'shop', 'path' => 'missing.sql'])->assertHasErrors(['was not found in the site folder']);

        $server->tool(ImportDatabase::class, ['website' => 'shop', 'path' => 'dump.sql', 'reset' => true])->assertOk()->assertSee('task_id');

        $task = PanelTask::query()->sole();
        $this->assertSame('success', $task->status, (string) $task->message);
        $this->assertStringContainsString('(the database was emptied first)', (string) $task->message);

        $lines = collect($commands)->map(fn (array $entry): string => str_replace('\\', '/', implode(' ', $entry['command'])));
        $this->assertTrue($lines->contains(fn (string $line): bool => str_contains($line, 'DROP DATABASE IF EXISTS `lv_shop`; CREATE DATABASE `lv_shop`')));
        $this->assertTrue($lines->contains(fn (string $line): bool => str_contains($line, 'lv_shop -e source '.$this->root.'/dump.sql')));
        $this->assertStringContainsString('user=lv_shop_u', (string) $commands[0]['defaults']);
        $this->assertStringContainsString('password="p@ss\"word"', (string) $commands[0]['defaults']);
        $this->assertSame([], glob(storage_path('app/mysql-site-*.cnf')) ?: [], 'The login file is deleted afterwards.');

        $server->tool(GetTask::class, ['task_id' => $task->id])->assertOk()->assertSee('"finished":true');
    }

    public function test_an_import_failure_shows_the_mysql_error_and_a_site_without_a_database_is_refused(): void
    {
        $this->site(['type' => 'php', 'provision_database' => true, 'db_name' => 'lv_shop', 'db_username' => 'u', 'db_password' => 'p']);
        file_put_contents($this->root.'/dump.sql.gz', 'x');
        $this->mock(CommandRunner::class, function (MockInterface $mock) {
            $mock->shouldReceive('supportsHostCommands')->andReturnTrue();
            $mock->shouldReceive('run')->andReturn(new CommandResult(false, false, ['bash'], 'ERROR 1050 (42S01) at line 25: Table \'users\' already exists', 1));
        });

        $this->server()->tool(ImportDatabase::class, ['website' => 'shop', 'path' => 'dump.sql.gz'])->assertOk();
        $task = PanelTask::query()->sole();
        $this->assertSame('failed', $task->status);
        $this->assertStringContainsString("Table 'users' already exists", (string) $task->message);
        $this->assertStringContainsString('reset true', (string) $task->message);

        Website::query()->update(['provision_database' => false]);
        $this->server()->tool(ImportDatabase::class, ['website' => 'shop', 'path' => 'dump.sql.gz'])->assertHasErrors(['has no database made by the panel']);
    }
}
