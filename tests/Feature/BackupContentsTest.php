<?php

namespace Tests\Feature;

use App\Jobs\RunBackupSchedule;
use App\Jobs\RunPanelTask;
use App\Jobs\RunRestore;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\Backups\ScheduleForm;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Models\PanelTask;
use App\Models\User;
use App\Services\BackupInspector;
use App\Services\BackupManager;
use App\Services\BackupStorageClient;
use App\Services\CommandRunner;
use App\Services\DatabaseDumper;
use App\Services\FileArchiver;
use App\Services\PanelTaskRunner;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class BackupContentsTest extends TestCase
{
    use RefreshDatabase;

    /** A small mysqldump with a table, rows, a trigger, a procedure, a function, and an event. */
    private const DUMP = <<<'SQL'
-- MySQL dump 10.13
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` int NOT NULL,
  `total` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
INSERT INTO `orders` VALUES (1,10),(2,20),(3,30);
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `orders_total` BEFORE INSERT ON `orders` FOR EACH ROW SET NEW.total = IFNULL(NEW.total, 0) */;;
DELIMITER ;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL
) ENGINE=InnoDB;
INSERT INTO `users` VALUES (7);

--
-- Dumping events for database 'shop'
--
DELIMITER ;;
/*!50106 CREATE*/ /*!50117 DEFINER=`root`@`localhost`*/ /*!50106 EVENT `cleanup` ON SCHEDULE EVERY 1 DAY DO DELETE FROM orders WHERE total = 0 */ ;;
DELIMITER ;

--
-- Dumping routines for database 'shop'
--
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `recount`()
BEGIN
  SELECT COUNT(*) FROM orders;
END ;;
DELIMITER ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `double_it`(x INT) RETURNS int
    DETERMINISTIC
RETURN x * 2 ;;
DELIMITER ;
SQL;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->work = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-contents-'.uniqid();
        File::ensureDirectoryExists($this->work);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);

        parent::tearDown();
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    private function dumpFile(): string
    {
        $file = $this->work.'/dump.sql.gz';
        file_put_contents($file, gzencode(self::DUMP));

        return $file;
    }

    public function test_a_dump_is_read_into_tables_routines_triggers_and_events(): void
    {
        $manifest = app(BackupInspector::class)->readDump($this->dumpFile());

        $this->assertSame(['orders', 'users'], array_column($manifest['tables'], 'name'));
        $this->assertSame(3, $manifest['tables'][0]['rows']);
        $this->assertSame(1, $manifest['tables'][1]['rows']);
        $this->assertStringStartsWith('CREATE TABLE `orders`', $manifest['tables'][0]['create']);
        $this->assertStringEndsWith('ENGINE=InnoDB;', $manifest['tables'][0]['create']);

        $this->assertSame('orders_total', $manifest['triggers'][0]['name']);
        $this->assertSame('orders', $manifest['triggers'][0]['table']);
        $this->assertStringStartsWith('CREATE DEFINER=`root`@`localhost` TRIGGER `orders_total`', $manifest['triggers'][0]['sql']);

        $this->assertSame(['recount'], array_column($manifest['procedures'], 'name'));
        $this->assertStringContainsString('SELECT COUNT(*) FROM orders;', $manifest['procedures'][0]['sql']);
        $this->assertSame(['double_it'], array_column($manifest['functions'], 'name'));
        $this->assertSame(['cleanup'], array_column($manifest['events'], 'name'));
        $this->assertStringStartsWith('CREATE DEFINER=`root`@`localhost` EVENT `cleanup`', $manifest['events'][0]['sql']);
    }

    public function test_chosen_parts_are_exported_as_runnable_sql(): void
    {
        $file = $this->dumpFile();
        $inspector = app(BackupInspector::class);
        $manifest = $inspector->readDump($file);

        $inspector->exportSql($file, $manifest, ['tables', 'triggers'], ['orders'], $this->work.'/orders.sql');
        $orders = (string) file_get_contents($this->work.'/orders.sql');

        $this->assertStringContainsString('SET @OLD_FOREIGN_KEY_CHECKS', $orders);
        $this->assertStringContainsString('INSERT INTO `orders` VALUES (1,10)', $orders);
        $this->assertStringNotContainsString('`users`', $orders);
        $this->assertSame(1, substr_count($orders, 'TRIGGER `orders_total`'), 'The trigger is written once, after the tables.');
        $this->assertStringContainsString("DROP TRIGGER IF EXISTS `orders_total`;\nDELIMITER ;;", $orders);
        $this->assertStringNotContainsString('PROCEDURE', $orders);
        $this->assertStringNotContainsString('EVENT', $orders);

        $inspector->exportSql($file, $manifest, ['procedures', 'functions', 'events'], null, $this->work.'/routines.sql');
        $routines = (string) file_get_contents($this->work.'/routines.sql');

        $this->assertStringNotContainsString('INSERT INTO', $routines);
        $this->assertStringContainsString("DROP PROCEDURE IF EXISTS `recount`;\nDELIMITER ;;\nCREATE DEFINER=`root`@`localhost` PROCEDURE `recount`()", $routines);
        $this->assertStringContainsString('DROP FUNCTION IF EXISTS `double_it`', $routines);
        $this->assertStringContainsString('DROP EVENT IF EXISTS `cleanup`', $routines);
        $this->assertStringContainsString(" ;;\nDELIMITER ;", $routines);
    }

    public function test_a_new_backup_keeps_its_contents_list(): void
    {
        $schedule = BackupSchedule::factory()->create();

        $this->mock(DatabaseDumper::class, function (MockInterface $mock) {
            $mock->shouldReceive('dump')->andReturnUsing(fn (string $database, string $file) => file_put_contents($file, gzencode(self::DUMP)));
            $mock->shouldReceive('tables')->andReturn(['orders', 'users']);
        });
        $this->mock(BackupStorageClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('upload')->once();
            $mock->shouldReceive('delete')->once();
        });

        $run = app(BackupManager::class)->run($schedule, 'manual')[0];
        $manifest = app(BackupInspector::class)->manifest($run);

        $this->assertSame(BackupRun::SUCCESS, $run->status);
        $this->assertSame(['orders', 'users'], array_column($manifest['tables'], 'name'));
        $this->assertCount(1, $manifest['procedures']);

        app(BackupManager::class)->deleteCopy($run);
        $this->assertNull(app(BackupInspector::class)->manifest($run));
    }

    public function test_run_now_lists_the_waiting_backups_right_away(): void
    {
        $this->signIn();
        Queue::fake();
        $schedule = BackupSchedule::factory()->create(['targets' => ['all' => false, 'databases' => ['app_db', 'shop_db']]]);

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('runNow', $schedule->id)
            ->assertSee('app_db')
            ->assertSee('shop_db')
            ->assertSee('Waiting', false);

        $this->assertSame(['app_db', 'shop_db'], BackupRun::query()->where('status', BackupRun::QUEUED)->orderBy('id')->pluck('target')->all());
        Queue::assertPushed(RunBackupSchedule::class, fn ($job) => count($job->runIds) === 2);

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])->call('runNow', $schedule->id);
        $this->assertSame(2, BackupRun::query()->count(), 'A schedule that is already waiting does not start twice.');

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])->call('cancelWaiting', $schedule->id);
        $this->assertSame(0, BackupRun::query()->where('status', BackupRun::QUEUED)->count());
    }

    public function test_the_restore_wizard_goes_from_database_to_backup_to_tables(): void
    {
        $this->signIn();
        Queue::fake();
        $old = BackupRun::factory()->create(['target' => 'shop', 'created_at' => now()->subDay()]);
        $new = BackupRun::factory()->create(['target' => 'shop', 'backup_storage_id' => $old->backup_storage_id]);
        BackupRun::factory()->create(['target' => 'blog']);

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('openRestore')
            ->assertSet('restoreStep', 1)
            ->assertSee('blog')
            ->call('chooseRestoreTarget', 'shop')
            ->assertSet('restoreStep', 2)
            ->call('chooseRestoreRun', $old->id)
            ->assertSet('restoreStep', 3)
            ->assertSet('restoreDatabase', 'shop')
            ->set('restoreWhole', false)
            ->set('tableSearch', 'ord')
            ->call('selectTables', true)
            ->assertSet('restoreTables', ['orders'])
            ->set('restoreDatabase', 'shop_copy')
            ->set('understood', true)
            ->call('restore')
            ->assertHasNoErrors()
            ->assertSet('restoreOpen', false);

        $restore = BackupRun::query()->where('type', 'restore')->sole();
        $this->assertSame($old->id, $restore->source_run_id);
        $this->assertSame(['database' => 'shop_copy', 'tables' => ['orders']], $restore->meta);
        Queue::assertPushed(RunRestore::class);
        $this->assertNotSame($old->id, $new->id);
    }

    public function test_files_are_restored_from_picked_folders(): void
    {
        $this->signIn();
        Queue::fake();
        $run = BackupRun::factory()->create(['kind' => 'files', 'target' => 'shop', 'object_key' => 'files/x.tar.gz', 'meta' => []]);
        file_put_contents(app(BackupInspector::class)->manifestPath($run), json_encode([
            'type' => 'files',
            'entries' => [
                ['path' => 'shop', 'dir' => true, 'size' => 0, 'modified' => '2026-10-13 10:00'],
                ['path' => 'shop/public', 'dir' => true, 'size' => 0, 'modified' => '2026-10-13 10:00'],
                ['path' => 'shop/public/index.php', 'dir' => false, 'size' => 20, 'modified' => '2026-10-13 10:00'],
                ['path' => 'shop/.env', 'dir' => false, 'size' => 5, 'modified' => '2026-10-13 10:00'],
            ],
        ]));

        Livewire::test(BackupsIndex::class, ['kind' => 'files'])
            ->call('openRestore', $run->id)
            ->assertSet('restoreStep', 3)
            ->set('restoreWhole', false)
            ->call('restore')
            ->assertHasErrors('restoreMembers')
            ->call('openArchiveFolder', 'shop')
            ->assertSee('public')
            ->assertSee('.env')
            ->call('toggleMember', 'shop/public')
            ->call('toggleMember', 'shop/.env')
            ->call('restore')
            ->assertHasNoErrors();

        $restore = BackupRun::query()->where('type', 'restore')->sole();
        $this->assertSame(['members' => ['shop/public', 'shop/.env'], 'original' => false], $restore->meta);
    }

    public function test_viewing_an_old_backup_reads_it_in_the_background_first(): void
    {
        $this->signIn();
        Queue::fake();
        $run = BackupRun::factory()->create();

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('openView', $run->id)
            ->assertSee('Reading the backup');

        $task = PanelTask::query()->sole();
        $this->assertSame('backup.inspect', $task->kind);
        $this->assertSame($run->id, $task->payload['run_id']);
        Queue::assertPushed(RunPanelTask::class);

        file_put_contents(app(BackupInspector::class)->manifestPath($run), json_encode(app(BackupInspector::class)->readDump($this->dumpFile())));

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('openView', $run->id)
            ->assertSee('orders')
            ->call('setViewTab', 'procedures')
            ->assertSee('recount')
            ->call('showItem', 'recount')
            ->assertSee('SELECT COUNT(*) FROM orders;');

        $this->assertSame(1, PanelTask::query()->count(), 'A backup with a contents list is not read again.');
    }

    public function test_a_download_of_some_parts_is_prepared_and_served(): void
    {
        $this->signIn();
        $run = BackupRun::factory()->create(['target' => 'shop']);
        $this->mock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('download')->andReturnUsing(fn ($storage, $key, $file) => file_put_contents($file, gzencode(self::DUMP))));

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('openDownload', $run->id)
            ->set('downloadParts', [])
            ->call('startDownload')
            ->assertHasErrors('downloadParts')
            ->set('downloadParts', ['tables', 'functions'])
            ->set('downloadAllTables', false)
            ->set('downloadTables', ['users'])
            ->call('startDownload')
            ->assertHasNoErrors()
            ->assertDispatched('download-ready');

        $task = PanelTask::query()->where('kind', 'backup.export')->sole();
        $this->assertSame(PanelTask::SUCCESS, $task->status, (string) $task->message);
        $this->assertStringEndsWith('-tables-functions.sql', $task->downloadName());

        $response = $this->get(route('tasks.download', $task))->assertOk()->assertDownload($task->downloadName());
        $sql = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('INSERT INTO `users` VALUES (7)', $sql);
        $this->assertStringNotContainsString('INSERT INTO `orders`', $sql);
        $this->assertStringContainsString('FUNCTION `double_it`', $sql);
        $this->assertStringNotContainsString('PROCEDURE `recount`', $sql);
    }

    public function test_the_file_picker_adds_and_skips_folders_in_bulk(): void
    {
        $this->signIn();
        BackupStorage::factory()->default()->create();
        File::ensureDirectoryExists($this->work.'/shop/node_modules');
        File::ensureDirectoryExists($this->work.'/shop/storage/logs');
        File::ensureDirectoryExists($this->work.'/blog');
        file_put_contents($this->work.'/notes.txt', 'n');
        config(['laravelify.files_root' => $this->work]);

        Livewire::test(ScheduleForm::class, ['kind' => 'files'])
            ->assertSee('notes.txt')
            ->call('includePaths', ['shop', 'blog', 'notes.txt'])
            ->assertSet('paths', ['shop', 'blog', 'notes.txt'])
            ->call('openPath', 'shop')
            ->assertSee('node_modules')
            ->assertSee('Included')
            ->call('excludePaths', ['shop/node_modules', 'shop/storage/logs'])
            ->assertSee('Skipped')
            ->call('excludePaths', ['blog'])
            ->assertSet('paths', ['shop', 'notes.txt'])
            ->call('includePaths', ['shop/storage/logs'])
            ->set('name', 'Sites')
            ->call('save')
            ->assertHasNoErrors();

        $schedule = BackupSchedule::query()->sole();
        $this->assertSame(['shop', 'notes.txt', 'shop/storage/logs'], $schedule->targets['paths']);
        $this->assertSame(['shop/node_modules', 'blog'], $schedule->targets['excludes']);
    }

    public function test_skip_patterns_match_names_paths_and_wildcards(): void
    {
        $this->assertTrue(FileArchiver::excluded('shop/node_modules', ['node_modules']));
        $this->assertTrue(FileArchiver::excluded('shop/node_modules/x/y.js', ['node_modules']));
        $this->assertTrue(FileArchiver::excluded('shop/storage/logs/laravel.log', ['shop/storage/logs']));
        $this->assertTrue(FileArchiver::excluded('shop/storage/logs', ['storage/logs']));
        $this->assertTrue(FileArchiver::excluded('shop/app.log', ['*.log']));
        $this->assertFalse(FileArchiver::excluded('shop/public', ['node_modules', 'storage/logs']));
        $this->assertFalse(FileArchiver::excluded('blog/logs', ['shop/storage/logs']));
    }

    public function test_an_archive_listing_becomes_a_browsable_tree(): void
    {
        File::ensureDirectoryExists($this->work.'/site/public/img');
        file_put_contents($this->work.'/site/public/index.html', 'hello');
        file_put_contents($this->work.'/site/readme.txt', 'readme');
        config(['laravelify.files_root' => $this->work, 'laravelify.host_commands' => '1']);

        if (! (new CommandRunner)->run(['tar', '--version'])->ok) {
            $this->markTestSkipped('tar is not available.');
        }

        $archive = $this->work.'.tar.gz';

        try {
            app(FileArchiver::class)->archive(['site'], [], $archive);
            $manifest = app(BackupInspector::class)->readArchive($archive);
        } finally {
            @unlink($archive);
        }

        $root = BackupInspector::children($manifest, '');
        $site = BackupInspector::children($manifest, 'site');

        $this->assertSame(['site'], array_column($root, 'name'));
        $this->assertSame(['public', 'readme.txt'], array_column($site, 'name'));
        $this->assertTrue($site[0]['dir']);
        $this->assertSame(6, $site[1]['size']);
        $this->assertSame(['img', 'index.html'], array_column(BackupInspector::children($manifest, 'site/public'), 'name'));
    }

    public function test_failed_tasks_say_why_and_others_cannot_download_them(): void
    {
        $this->signIn();
        $task = app(PanelTaskRunner::class)->start('backup.export', BackupsIndex::TASK_SCOPE, 'Preparing nothing', ['run_id' => 999]);

        $this->assertSame(PanelTask::FAILED, $task->status);
        $this->assertSame('That backup is no longer available.', $task->message);
        $this->get(route('tasks.download', $task))->assertNotFound();
    }
}
