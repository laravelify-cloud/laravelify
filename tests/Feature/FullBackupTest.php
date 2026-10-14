<?php

namespace Tests\Feature;

use App\Jobs\RunBackupSchedule;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\Backups\ScheduleForm;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupStorage;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\BackupManager;
use App\Services\BackupStorageClient;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\DatabaseDumper;
use App\Services\FileArchiver;
use App\Services\TwoFactor;
use App\Support\BackupCron;
use App\Support\BackupExcludes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class FullBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-full-'.uniqid();
        File::ensureDirectoryExists($this->root.'/shop/vendor');
        File::ensureDirectoryExists($this->root.'/front/dist');
        config(['laravelify.files_root' => $this->root, 'laravelify.sites_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    private function sites(): void
    {
        Website::factory()->create(['slug' => 'shop', 'type' => 'laravel', 'deploy_path' => $this->root.'/shop']);
        Website::factory()->create(['slug' => 'front', 'type' => 'react', 'deploy_path' => $this->root.'/front']);
        Website::factory()->create(['slug' => 'gone', 'type' => 'nextjs', 'deploy_path' => $this->root.'/not-deployed']);
    }

    public function test_each_site_type_skips_what_a_deploy_rebuilds(): void
    {
        $this->assertContains('vendor', BackupExcludes::forType('laravel'));
        $this->assertContains('storage/logs', BackupExcludes::forType('laravel'));
        $this->assertSame(['.next'], BackupExcludes::forType('nextjs'));
        $this->assertSame(['.nuxt', '.output'], BackupExcludes::forType('nuxt'));
        $this->assertSame(['dist', '.angular'], BackupExcludes::forType('angular'));
        $this->assertSame(['dist', 'build'], BackupExcludes::forType('react'));
        $this->assertSame([], BackupExcludes::forType('static'));

        $website = Website::factory()->make(['type' => 'react']);
        $this->assertSame(['node_modules', '.git', 'front/dist', 'front/build'], BackupExcludes::forWebsite($website, 'front'));
    }

    public function test_every_website_becomes_one_archive_each_with_its_skips(): void
    {
        $this->sites();
        $schedule = BackupSchedule::factory()->create(['kind' => 'files', 'targets' => ['websites' => true, 'paths' => [], 'excludes' => ['*.log']]]);

        $targets = app(BackupManager::class)->targets($schedule);

        $this->assertSame(['front', 'shop'], array_column($targets, 'label'), 'The site that is not deployed is left out.');
        $this->assertSame(['front'], $targets[0]['paths']);
        $this->assertContains('front/dist', $targets[0]['excludes']);
        $this->assertContains('shop/vendor', $targets[1]['excludes']);
        $this->assertContains('node_modules', $targets[1]['excludes']);
        $this->assertContains('*.log', $targets[1]['excludes']);
        $this->assertSame('Every website', $schedule->describeTargets());
    }

    public function test_full_backup_runs_every_database_now_from_a_by_hand_schedule(): void
    {
        $this->signIn();
        Queue::fake();
        BackupStorage::factory()->default()->create();
        $this->mock(DatabaseDumper::class, fn (MockInterface $mock) => $mock->shouldReceive('databases')->andReturn(['app_db', 'shop_db']));

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('fullBackup')
            ->assertSee('Full backup')
            ->assertSee('By hand (Run now)');

        $schedule = BackupSchedule::query()->sole();
        $this->assertSame('manual', $schedule->frequency);
        $this->assertFalse(BackupCron::isDue($schedule->cron), 'A by-hand schedule never runs on its own.');
        $this->assertSame(['app_db', 'shop_db'], BackupRun::query()->orderBy('id')->pluck('target')->all());
        Queue::assertPushed(RunBackupSchedule::class);

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])->call('fullBackup');
        $this->assertSame(1, BackupSchedule::query()->count(), 'The same schedule is used again.');
        $this->assertSame(2, BackupRun::query()->count(), 'Nothing new starts while it is still running.');
    }

    public function test_adding_a_website_folder_skips_its_rebuildable_folders(): void
    {
        $this->signIn();
        $this->sites();
        BackupStorage::factory()->default()->create();

        Livewire::test(ScheduleForm::class, ['kind' => 'files'])
            ->call('includePaths', ['shop'])
            ->assertDispatched('toast', type: 'success')
            ->assertSet('paths', ['shop'])
            ->tap(fn ($component) => $this->assertContains('shop/vendor', explode("\n", $component->get('excludes'))))
            ->set('all_websites', true)
            ->assertSee('Skipped automatically')
            ->assertSee('front')
            ->set('name', 'All sites')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(BackupSchedule::query()->sole()->targets['websites']);
    }

    public function test_bulk_actions_run_pause_and_delete(): void
    {
        $this->signIn();
        Queue::fake();
        $first = BackupSchedule::factory()->create(['name' => 'One']);
        $second = BackupSchedule::factory()->create(['name' => 'Two']);
        $backups = BackupRun::factory()->count(2)->create();
        $this->mock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('delete')->twice());

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->call('runSchedules', [$first->id, $second->id])
            ->assertDispatched('bulk-done')
            ->call('setSchedulesEnabled', [$first->id, $second->id], false)
            ->call('deleteBackups', $backups->pluck('id')->all());

        Queue::assertPushed(RunBackupSchedule::class, 2);
        $this->assertSame(0, BackupSchedule::query()->whereKey([$first->id, $second->id])->where('enabled', true)->count());
        $this->assertSame(0, BackupRun::query()->whereKey($backups->pluck('id'))->count());

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])->call('deleteSchedules', [$first->id]);
        $this->assertModelMissing($first);
        $this->assertModelExists($second);
    }

    public function test_upload_progress_is_read_from_rclone(): void
    {
        $storage = BackupStorage::factory()->create();
        $this->mock(CommandRunner::class, function (MockInterface $mock) {
            $mock->shouldReceive('run')->andReturnUsing(function (array $command, ?string $cwd = null, array $env = [], ?callable $onOutput = null) {
                $onOutput("2026/10/14 NOTICE:    1.000 MiB / 4.000 MiB, 25%, 1.0 MiB/s, ETA 3s\n");
                $onOutput("2026/10/14 NOTICE:    4.000 MiB / 4.000 MiB, 100%, 1.0 MiB/s, ETA 0s\n");

                return new CommandResult(true, false, $command, '', 0);
            });
        });
        $seen = [];

        app(BackupStorageClient::class)->upload($storage, '/tmp/x.sql.gz', 'k', function (int $percent) use (&$seen) {
            $seen[] = $percent;
        });

        $this->assertSame([25, 100], $seen);
    }

    public function test_download_links_are_signed_like_aws_does(): void
    {
        Carbon::setTestNow(Carbon::parse('2013-05-24 00:00:00', 'UTC'));
        $storage = new BackupStorage(['endpoint' => null, 'region' => 'us-east-1', 'bucket' => 'examplebucket', 'path' => '', 'access_key' => 'AKIAIOSFODNN7EXAMPLE', 'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY']);

        $url = app(BackupStorageClient::class)->temporaryUrl($storage, 'test.txt', 86400);

        // The example from the AWS documentation for query string authentication.
        $this->assertStringStartsWith('https://examplebucket.s3.amazonaws.com/test.txt?', $url);
        $this->assertStringEndsWith('X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);

        $spaces = new BackupStorage(['endpoint' => 'fra1.digitaloceanspaces.com', 'region' => 'fra1', 'bucket' => 'b', 'path' => 'laravelify', 'access_key' => 'a', 'secret_key' => 's']);
        $this->assertStringStartsWith('https://fra1.digitaloceanspaces.com/b/laravelify/databases/x.sql.gz?', app(BackupStorageClient::class)->temporaryUrl($spaces, 'databases/x.sql.gz'));
        Carbon::setTestNow();
    }

    public function test_telegram_gets_one_message_with_download_links(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Setting::putValue('telegram_enabled', '1');
        Setting::putValue('telegram_bot_token', '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0');
        Setting::putValue('telegram_chat_id', '42');
        $storage = BackupStorage::factory()->create(['endpoint' => 'https://fra1.digitaloceanspaces.com', 'region' => 'fra1']);
        $schedule = BackupSchedule::factory()->create(['backup_storage_id' => $storage->id, 'targets' => ['all' => false, 'databases' => ['app_db', 'shop_db']]]);

        $this->mock(DatabaseDumper::class, function (MockInterface $mock) {
            $mock->shouldReceive('dump')->andReturnUsing(fn (string $database, string $file) => file_put_contents($file, gzencode('-- dump')));
            $mock->shouldReceive('tables')->andReturn([]);
        });
        $this->partialMock(BackupStorageClient::class, fn (MockInterface $mock) => $mock->shouldReceive('upload'));

        $runs = app(BackupManager::class)->run($schedule->load('storage'), 'manual');
        $this->assertSame(['success', 'success'], array_map(fn ($run) => $run->status, $runs), (string) $runs[0]->log);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $text = (string) $request['text'];

            return str_contains($text, 'Backup finished')
                && substr_count($text, '<a href="https://fra1.digitaloceanspaces.com/') === 2
                && str_contains($text, 'X-Amz-Expires=86400')
                && str_contains($text, 'work for 24 hours');
        });
    }

    public function test_a_running_backup_shows_its_phase_and_percent(): void
    {
        $this->signIn();
        BackupRun::factory()->create(['status' => 'running', 'phase' => 'Uploading to Spaces', 'progress' => 42, 'object_key' => null]);

        Livewire::test(BackupsIndex::class, ['kind' => 'database'])
            ->assertSee('Uploading to Spaces')
            ->assertSee('42%');
    }

    public function test_tar_patterns_from_site_skips_really_skip(): void
    {
        File::ensureDirectoryExists($this->root.'/front/src');
        file_put_contents($this->root.'/front/src/app.js', 'x');
        file_put_contents($this->root.'/front/dist/bundle.js', 'y');
        config(['laravelify.host_commands' => '1']);

        if (! (new CommandRunner)->run(['tar', '--version'])->ok) {
            $this->markTestSkipped('tar is not available.');
        }

        $archive = $this->root.'.tar.gz';
        $website = Website::factory()->make(['type' => 'react']);

        try {
            app(FileArchiver::class)->archive(['front'], BackupExcludes::forWebsite($website, 'front'), $archive);
            $list = (new CommandRunner)->run(['tar', '--force-local', '-tzf', $archive])->output;
        } finally {
            @unlink($archive);
        }

        $this->assertStringContainsString('front/src/app.js', $list);
        $this->assertStringNotContainsString('dist', $list);
    }
}
