<?php

namespace Tests\Feature;

use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\Backups\Storages;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Models\User;
use App\Services\BackupManager;
use App\Services\BackupStorageClient;
use App\Services\DatabaseBrowser;
use App\Services\DatabaseDumper;
use App\Services\TwoFactor;
use App\Support\SkippedTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class BackupPerSiteTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    public function test_each_chosen_folder_is_its_own_archive_and_one_run_shares_a_batch(): void
    {
        $schedule = BackupSchedule::factory()->create(['kind' => 'files', 'targets' => ['paths' => ['a-site', 'b-site'], 'excludes' => ['node_modules']]]);
        $backups = app(BackupManager::class);

        $this->assertSame(['a-site', 'b-site'], array_column($backups->targets($schedule), 'label'));
        $this->assertSame([['a-site'], ['b-site']], array_column($backups->targets($schedule), 'paths'));

        $runs = $backups->prepareRuns($schedule, 'manual');

        $this->assertCount(2, $runs);
        $this->assertNotNull($runs[0]->batch);
        $this->assertSame($runs[0]->batch, $runs[1]->batch);
        $this->assertNotSame($runs[0]->batch, $backups->prepareRuns($schedule, 'manual')[0]->batch);
    }

    public function test_everything_stays_one_archive(): void
    {
        $schedule = BackupSchedule::factory()->create(['kind' => 'files', 'targets' => ['paths' => ['', 'a-site'], 'excludes' => []]]);

        $this->assertSame(['(everything)'], array_column(app(BackupManager::class)->targets($schedule), 'label'));
    }

    public function test_the_list_shows_a_run_with_each_website_under_it(): void
    {
        $this->signIn();
        $storage = BackupStorage::factory()->create();
        $schedule = BackupSchedule::factory()->create(['name' => 'Full backup', 'kind' => 'files', 'backup_storage_id' => $storage->id, 'targets' => ['websites' => true, 'paths' => [], 'excludes' => []]]);

        foreach (['shop', 'blog'] as $site) {
            BackupRun::query()->create([
                'backup_schedule_id' => $schedule->id, 'backup_storage_id' => $storage->id, 'batch' => 'b1', 'type' => 'backup', 'kind' => 'files',
                'target' => $site, 'status' => 'success', 'trigger' => 'manual', 'object_key' => 'k/'.$site, 'size_bytes' => 1024,
            ]);
        }

        foreach (['shop', 'blog'] as $site) {
            BackupRun::query()->create([
                'backup_schedule_id' => $schedule->id, 'backup_storage_id' => $storage->id, 'batch' => 'b2', 'type' => 'backup', 'kind' => 'files',
                'target' => $site, 'status' => 'success', 'trigger' => 'manual', 'object_key' => 'k2/'.$site, 'size_bytes' => 2048,
            ]);
        }

        Livewire::test(BackupsIndex::class, ['kind' => 'files'])
            ->assertSeeInOrder(['Full backup', '2 archives, one per website', '2 runs', 'blog', 'shop', '1 older run', 'Run of', 'blog', 'shop'])
            ->assertSeeHtml('wire:key="schedule-group-'.$schedule->id);
    }

    public function test_a_folder_on_this_server_is_saved_as_storage_outside_var_www(): void
    {
        $this->signIn();
        $this->mock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('test')->once());

        Livewire::test(Storages::class)
            ->call('create')
            ->set('name', 'Local')
            ->set('provider', 'local')
            ->set('path', '/var/www/backups')
            ->call('save')
            ->assertHasErrors(['path'])
            ->set('path', '/backups/laravelify/')
            ->call('save')
            ->assertHasNoErrors();

        $storage = BackupStorage::query()->sole();
        $this->assertTrue($storage->isLocal());
        $this->assertSame('/backups/laravelify', $storage->location());
        $this->assertNotNull($storage->checked_at);
    }

    public function test_a_local_storage_is_an_rclone_local_remote_without_keys(): void
    {
        $storage = BackupStorage::factory()->local('/backups/laravelify')->create();
        $client = app(BackupStorageClient::class);

        $this->assertSame('lv:/backups/laravelify/files/shop.tar.gz', $client->remote($storage, 'files/shop.tar.gz'));
        $this->assertSame(['RCLONE_CONFIG_LV_TYPE' => 'local'], $client->environment($storage));
    }

    public function test_skipped_tables_are_cleaned_and_matched_per_database(): void
    {
        $entries = SkippedTables::normalize('shop_db.sessions
*.cache, bad entry, shop_db.sessions');

        $this->assertSame(['*.cache', 'shop_db.sessions'], $entries);
        $this->assertSame(['cache', 'sessions'], SkippedTables::forDatabase($entries, 'shop_db'));
        $this->assertSame(['cache'], SkippedTables::forDatabase($entries, 'blog_db'));
        $this->assertSame(['bad', 'entry'], SkippedTables::invalid('bad entry *.ok'));
    }

    public function test_a_database_dump_leaves_out_the_skipped_tables(): void
    {
        $storage = BackupStorage::factory()->create();
        $schedule = BackupSchedule::factory()->create(['backup_storage_id' => $storage->id, 'targets' => ['all' => false, 'databases' => ['app_db'], 'skip_tables' => ['app_db.sessions', '*.cache', 'other_db.users']]]);

        $this->mock(DatabaseDumper::class, function (MockInterface $mock) {
            $mock->shouldReceive('dump')->once()->with('app_db', \Mockery::any(), ['cache', 'sessions'])
                ->andReturnUsing(fn (string $database, string $file) => file_put_contents($file, 'dump'));
            $mock->shouldReceive('tables')->andReturn(['users', 'sessions', 'cache']);
        });
        $this->mock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('upload')->once());

        $run = app(BackupManager::class)->run($schedule, 'manual')[0];

        $this->assertSame(['users'], $run->meta['tables']);
        $this->assertSame(['cache', 'sessions'], $run->meta['skipped_tables']);
        $this->assertStringContainsString('Skipping 2 table(s): cache, sessions.', (string) $run->log);
        $this->assertSame('app_db · skips 3 tables', $schedule->describeTargets());
    }

    public function test_the_panels_own_database_is_never_offered(): void
    {
        config(['database.connections.mysql.database' => 'laravelify']);

        $this->assertTrue(DatabaseDumper::isPanelDatabase('laravelify'));
        $this->assertFalse(DatabaseDumper::isPanelDatabase('shop_db'));
        $this->assertFalse(DatabaseBrowser::validDatabase('laravelify'));
        $this->assertTrue(DatabaseBrowser::validDatabase('shop_db'));
    }
}
