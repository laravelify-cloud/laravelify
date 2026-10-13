<?php

namespace Tests\Feature;

use App\Jobs\RunBackupSchedule;
use App\Jobs\RunRestore;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\Backups\ScheduleForm;
use App\Livewire\Backups\Storages;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Models\User;
use App\Services\BackupManager;
use App\Services\BackupStorageClient;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\DatabaseDumper;
use App\Services\FileArchiver;
use App\Services\TwoFactor;
use App\Support\BackupCron;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    public function test_simple_choices_become_cron_expressions(): void
    {
        $this->assertSame('* * * * *', BackupCron::build('minutes', 1, '00:00', 0));
        $this->assertSame('*/15 * * * *', BackupCron::build('minutes', 15, '00:00', 0));
        $this->assertSame('30 * * * *', BackupCron::build('hourly', 1, '00:30', 0));
        $this->assertSame('0 */6 * * *', BackupCron::build('hours', 6, '00:00', 0));
        $this->assertSame('15 2 * * *', BackupCron::build('daily', 1, '02:15', 0));
        $this->assertSame('0 3 * * 1', BackupCron::build('weekly', 1, '03:00', 1));
        $this->assertSame('5 4 * * 1-5', BackupCron::build('custom', 1, '00:00', 0, '5 4 * * 1-5'));
        $this->assertSame('Every 15 minutes', BackupCron::describe('minutes', 15, '00:00', 0));
        $this->assertSame('Every Monday at 03:00', BackupCron::describe('weekly', 1, '03:00', 1));

        $this->expectException(\InvalidArgumentException::class);
        BackupCron::build('custom', 1, '00:00', 0, 'every day');
    }

    public function test_storage_environment_keeps_keys_out_of_the_command_line(): void
    {
        $storage = BackupStorage::factory()->create(['endpoint' => 'nyc3.digitaloceanspaces.com/', 'bucket' => 'my-bucket', 'path' => '/laravelify/']);
        $client = app(BackupStorageClient::class);
        $environment = $client->environment($storage);

        $this->assertSame('Other', $environment['RCLONE_CONFIG_LV_PROVIDER']);
        $this->assertSame('https://nyc3.digitaloceanspaces.com', $environment['RCLONE_CONFIG_LV_ENDPOINT']);
        $this->assertSame($storage->secret_key, $environment['RCLONE_CONFIG_LV_SECRET_ACCESS_KEY']);
        $this->assertSame('lv:my-bucket/laravelify/databases/a.sql.gz', $client->remote($storage, 'databases/a.sql.gz'));

        $aws = BackupStorage::factory()->create(['endpoint' => null, 'region' => 'eu-west-1']);
        $this->assertSame('AWS', $client->environment($aws)['RCLONE_CONFIG_LV_PROVIDER']);
        $this->assertArrayNotHasKey('RCLONE_CONFIG_LV_ENDPOINT', $client->environment($aws));
    }

    public function test_storage_keys_are_encrypted_at_rest(): void
    {
        $storage = BackupStorage::factory()->create(['secret_key' => 'super-secret-value']);

        $this->assertStringNotContainsString('super-secret-value', (string) \DB::table('backup_storages')->where('id', $storage->id)->value('secret_key'));
        $this->assertSame('super-secret-value', $storage->fresh()->secret_key);
        $this->assertArrayNotHasKey('secret_key', $storage->toArray());
    }

    public function test_adding_storage_tests_the_connection_and_the_first_one_is_the_default(): void
    {
        $this->signIn();
        $this->mock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('test')->once());

        Livewire::test(Storages::class)
            ->call('create')
            ->set('name', 'Spaces')
            ->set('provider', 'spaces')
            ->set('bucket', 'my-bucket')
            ->set('access_key', 'AKIA123')
            ->set('secret_key', 'secret123')
            ->call('save')
            ->assertHasNoErrors();

        $storage = BackupStorage::query()->sole();
        $this->assertTrue($storage->is_default);
        $this->assertSame('https://nyc3.digitaloceanspaces.com', $storage->endpoint);
        $this->assertNotNull($storage->checked_at);
        $this->assertNull($storage->last_error);
    }

    public function test_a_failed_connection_test_is_saved_with_the_reason(): void
    {
        $this->signIn();
        $this->mock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('test')->andThrow(new RuntimeException('The access key or secret key is wrong.')));

        Livewire::test(Storages::class)
            ->set('name', 'Spaces')
            ->set('bucket', 'my-bucket')
            ->set('access_key', 'AKIA123')
            ->set('secret_key', 'wrong')
            ->call('save');

        $storage = BackupStorage::query()->sole();
        $this->assertNull($storage->checked_at);
        $this->assertSame('The access key or secret key is wrong.', $storage->last_error);
    }

    public function test_storage_form_rejects_a_bad_endpoint_and_a_storage_in_use_cannot_be_removed(): void
    {
        $this->signIn();

        Livewire::test(Storages::class)
            ->set('name', 'X')
            ->set('endpoint', 'https://host/path?x=1')
            ->set('bucket', 'b')
            ->set('access_key', 'a')
            ->set('secret_key', 's')
            ->call('save')
            ->assertHasErrors('endpoint');

        $schedule = BackupSchedule::factory()->create();

        Livewire::test(Storages::class)->call('delete', $schedule->backup_storage_id);
        $this->assertDatabaseHas('backup_storages', ['id' => $schedule->backup_storage_id]);
    }

    public function test_a_database_schedule_is_saved_with_its_cron_and_targets(): void
    {
        $this->signIn();
        $storage = BackupStorage::factory()->default()->create();

        Livewire::test(ScheduleForm::class, ['kind' => 'database'])
            ->set('name', 'App db hourly')
            ->set('databases', ['app_db'])
            ->set('frequency', 'hourly')
            ->set('run_time', '00:20')
            ->set('retention_count', 12)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('backups.databases'));

        $schedule = BackupSchedule::query()->sole();
        $this->assertSame($storage->id, $schedule->backup_storage_id);
        $this->assertSame('20 * * * *', $schedule->cron);
        $this->assertSame(['all' => false, 'databases' => ['app_db']], $schedule->targets);
        $this->assertSame('app_db', $schedule->describeTargets());
    }

    public function test_every_database_every_few_minutes_and_validation(): void
    {
        $this->signIn();
        BackupStorage::factory()->default()->create();

        Livewire::test(ScheduleForm::class, ['kind' => 'database'])
            ->set('name', 'Nothing chosen')
            ->call('save')
            ->assertHasErrors('databases')
            ->set('name', 'Everything')
            ->set('all_databases', true)
            ->set('frequency', 'minutes')
            ->set('interval', 5)
            ->call('save')
            ->assertHasNoErrors();

        $schedule = BackupSchedule::query()->sole();
        $this->assertSame('*/5 * * * *', $schedule->cron);
        $this->assertSame('All databases', $schedule->describeTargets());
    }

    public function test_a_files_schedule_needs_a_folder_and_keeps_its_excludes(): void
    {
        $this->signIn();
        BackupStorage::factory()->default()->create();

        Livewire::test(ScheduleForm::class, ['kind' => 'files'])
            ->set('name', 'Sites')
            ->call('save')
            ->assertHasErrors('paths')
            ->call('addFolder', 'shop')
            ->call('toggleExclude', 'node_modules')
            ->set('excludes', "node_modules\nstorage/logs")
            ->set('frequency', 'daily')
            ->set('run_time', '03:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('backups.files'));

        $schedule = BackupSchedule::query()->sole();
        $this->assertSame(['shop'], $schedule->targets['paths']);
        $this->assertSame(['node_modules', 'storage/logs'], $schedule->targets['excludes']);
        $this->assertSame('30 3 * * *', $schedule->cron);
    }

    public function test_folder_paths_cannot_leave_the_root(): void
    {
        $this->expectException(RuntimeException::class);
        FileArchiver::cleanPath('shop/../../etc');
    }

    public function test_the_scheduler_queues_only_enabled_due_schedules_once(): void
    {
        Queue::fake();
        $due = BackupSchedule::factory()->create(['cron' => '* * * * *']);
        BackupSchedule::factory()->create(['cron' => '* * * * *', 'enabled' => false]);
        BackupSchedule::factory()->create(['cron' => '0 0 1 1 *']);

        $this->artisan('laravelify:run-backups')->assertSuccessful();
        Queue::assertPushed(RunBackupSchedule::class, 1);
        Queue::assertPushed(RunBackupSchedule::class, fn ($job) => $job->scheduleId === $due->id && $job->trigger === 'schedule');

        $this->artisan('laravelify:run-backups')->assertSuccessful();
        Queue::assertPushed(RunBackupSchedule::class, 1);
    }

    public function test_a_database_backup_is_dumped_uploaded_and_old_copies_are_pruned(): void
    {
        $storage = BackupStorage::factory()->create();
        $schedule = BackupSchedule::factory()->create(['backup_storage_id' => $storage->id, 'retention_count' => 2]);

        foreach ([1, 2, 3] as $number) {
            BackupRun::factory()->create([
                'backup_schedule_id' => $schedule->id,
                'backup_storage_id' => $storage->id,
                'object_key' => 'databases/app_db/old-'.$number.'.sql.gz',
                'created_at' => now()->subDays(4 - $number),
            ]);
        }

        $deleted = [];
        $this->mock(DatabaseDumper::class, function (MockInterface $mock) {
            $mock->shouldReceive('dump')->once()->andReturnUsing(fn (string $database, string $file) => file_put_contents($file, 'dump'));
            $mock->shouldReceive('tables')->andReturn(['users', 'orders']);
        });
        $this->mock(BackupStorageClient::class, function (MockInterface $mock) use (&$deleted) {
            $mock->shouldReceive('upload')->once();
            $mock->shouldReceive('delete')->andReturnUsing(function (BackupStorage $storage, string $key) use (&$deleted) {
                $deleted[] = $key;
            });
        });

        $runs = app(BackupManager::class)->run($schedule, 'manual');

        $this->assertCount(1, $runs);
        $this->assertSame(BackupRun::SUCCESS, $runs[0]->status);
        $this->assertSame(['users', 'orders'], $runs[0]->meta['tables']);
        $this->assertStringStartsWith('databases/app_db/', $runs[0]->object_key);
        $this->assertSame(4, strlen('dump'));
        $this->assertEqualsCanonicalizing(['databases/app_db/old-1.sql.gz', 'databases/app_db/old-2.sql.gz'], $deleted);
        $this->assertSame(2, BackupRun::query()->where('status', 'success')->count());
    }

    public function test_a_failed_upload_marks_the_run_failed_with_the_reason(): void
    {
        $schedule = BackupSchedule::factory()->create();

        $this->mock(DatabaseDumper::class, function (MockInterface $mock) {
            $mock->shouldReceive('dump')->andReturnUsing(fn (string $database, string $file) => file_put_contents($file, 'dump'));
            $mock->shouldReceive('tables')->andReturn([]);
        });
        $this->mock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('upload')->andThrow(new RuntimeException('The bucket does not exist.')));

        $run = app(BackupManager::class)->run($schedule, 'manual')[0];

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertStringContainsString('The bucket does not exist.', $run->log);
        $this->assertNull($run->object_key);
    }

    public function test_all_databases_expands_to_every_non_system_database(): void
    {
        $schedule = BackupSchedule::factory()->create(['targets' => ['all' => true, 'databases' => []]]);
        $this->mock(DatabaseDumper::class, fn (MockInterface $mock) => $mock->shouldReceive('databases')->andReturn(['app_db', 'shop_db']));

        $targets = app(BackupManager::class)->targets($schedule);

        $this->assertSame(['app_db', 'shop_db'], array_column($targets, 'database'));
    }

    public function test_a_table_can_be_pulled_out_of_a_dump(): void
    {
        $dump = <<<'SQL'
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40014 SET FOREIGN_KEY_CHECKS=0 */;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (`id` int);

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` VALUES (1),(2);

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (`id` int);
INSERT INTO `users` VALUES (7);

-- Dumping events for database 'app_db'
CREATE EVENT x;
SQL;
        $gz = tempnam(sys_get_temp_dir(), 'dump').'.gz';
        file_put_contents($gz, gzencode($dump));
        $out = $gz.'.sql';

        $found = app(DatabaseDumper::class)->extractTables($gz, ['users'], $out);
        $sql = (string) file_get_contents($out);
        @unlink($gz);
        @unlink($out);

        $this->assertSame(['users'], $found);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $sql);
        $this->assertStringContainsString('INSERT INTO `users` VALUES (7)', $sql);
        $this->assertStringNotContainsString('orders', $sql);
        $this->assertStringNotContainsString('CREATE EVENT', $sql);
    }

    public function test_a_file_backup_archives_the_chosen_folders_and_skips_excludes(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-backup-'.uniqid();
        File::ensureDirectoryExists($root.'/shop/node_modules');
        File::ensureDirectoryExists($root.'/shop/public');
        file_put_contents($root.'/shop/node_modules/big.js', 'x');
        file_put_contents($root.'/shop/public/index.php', '<?php');
        config(['laravelify.files_root' => $root, 'laravelify.host_commands' => '1']);
        $archive = $root.'.tar.gz';

        if (! (new CommandRunner)->run(['tar', '--version'])->ok) {
            $this->markTestSkipped('tar is not available.');
        }

        try {
            $result = app(FileArchiver::class)->archive(['shop'], ['node_modules'], $archive);
            $list = (new CommandRunner)->run(['tar', '--force-local', '-tzf', $archive])->output;

            $this->assertStringContainsString('shop/public/index.php', $list);
            $this->assertStringNotContainsString('node_modules', $list);
            $this->assertContains('shop/public', $result['entries']);

            $restored = app(FileArchiver::class)->restore($archive, 'shop/public', false);
            $this->assertFileExists($restored.'/shop/public/index.php');
            $this->assertFileDoesNotExist($restored.'/shop/node_modules/big.js');
        } finally {
            File::deleteDirectory($root);
            @unlink($archive);
        }
    }

    public function test_a_restore_is_queued_only_for_available_backups_and_asks_for_confirmation(): void
    {
        $this->signIn();
        Queue::fake();
        $run = BackupRun::factory()->create();
        $broken = BackupRun::factory()->create(['status' => BackupRun::FAILED, 'object_key' => null]);

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('openRestore', $run->id)
            ->assertSet('restoreDatabase', 'app_db')
            ->call('restore')
            ->assertHasErrors('understood')
            ->set('understood', true)
            ->set('restoreWhole', false)
            ->call('restore')
            ->assertHasErrors('restoreTables')
            ->set('restoreTables', ['users'])
            ->call('restore')
            ->assertHasNoErrors();

        Queue::assertPushed(RunRestore::class, 1);
        $restore = BackupRun::query()->where('type', 'restore')->sole();
        $this->assertSame($run->id, $restore->source_run_id);
        $this->assertSame(['database' => 'app_db', 'tables' => ['users']], $restore->meta);

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('openRestore', $broken->id)
            ->assertSet('restoreRunId', null);
    }

    public function test_a_restore_downloads_the_file_and_loads_the_chosen_tables(): void
    {
        $source = BackupRun::factory()->create();
        $restore = BackupRun::factory()->create([
            'type' => BackupRun::TYPE_RESTORE,
            'status' => BackupRun::QUEUED,
            'source_run_id' => $source->id,
            'object_key' => null,
            'meta' => ['database' => 'copy_db', 'tables' => ['users']],
        ]);

        $this->mock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('download')->once()->andReturnUsing(fn ($storage, $key, $file) => file_put_contents($file, 'x')));
        $this->mock(DatabaseDumper::class, fn (MockInterface $mock) => $mock->shouldReceive('restore')->once()->withArgs(fn ($file, $database, $tables) => $database === 'copy_db' && $tables === ['users']));

        $result = app(BackupManager::class)->restore($restore->load('source.storage'));

        $this->assertSame(BackupRun::SUCCESS, $result->status);
        $this->assertStringContainsString('Restoring users into copy_db', $result->log);
    }

    public function test_backup_pages_open_and_show_the_data(): void
    {
        $this->signIn();
        $schedule = BackupSchedule::factory()->create(['name' => 'Nightly app']);
        BackupRun::factory()->create(['backup_schedule_id' => $schedule->id, 'backup_storage_id' => $schedule->backup_storage_id]);
        BackupSchedule::factory()->files()->create(['name' => 'Site files']);

        $this->get(route('backups.databases'))->assertOk()->assertSee('Nightly app')->assertSee('Every hour');
        $this->get(route('backups.files'))->assertOk()->assertSee('Site files');
        $this->get(route('backups.storages'))->assertOk()->assertSee('Backup storage');
        $this->get(route('backups.schedules.create', 'database'))->assertOk();
        $this->get(route('backups.schedules.edit', $schedule))->assertOk()->assertSee('Nightly app');
    }

    public function test_the_runner_passes_storage_keys_in_the_environment_only(): void
    {
        config(['laravelify.host_commands' => '1']);
        $storage = BackupStorage::factory()->create();
        $captured = [];

        $this->mock(CommandRunner::class, function (MockInterface $mock) use (&$captured) {
            $mock->shouldReceive('run')->andReturnUsing(function (array $command, ?string $cwd = null, array $env = []) use (&$captured) {
                $captured = ['command' => $command, 'env' => $env];

                return new CommandResult(true, false, $command, '', 0);
            });
        });

        app(BackupStorageClient::class)->delete($storage, 'a.sql.gz');

        $this->assertSame('rclone', $captured['command'][0]);
        $this->assertStringNotContainsString($storage->secret_key, implode(' ', $captured['command']));
        $this->assertSame($storage->secret_key, $captured['env']['RCLONE_CONFIG_LV_SECRET_ACCESS_KEY']);
    }
}
