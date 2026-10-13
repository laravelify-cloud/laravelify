<?php

namespace Tests\Feature;

use App\Jobs\RunPanelTask;
use App\Livewire\Websites\Files;
use App\Models\PanelTask;
use App\Models\User;
use App\Models\Website;
use App\Services\FileOperations;
use App\Services\PanelTaskRunner;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class FileTasksTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-tasks-'.uniqid();
        File::ensureDirectoryExists($this->root.'/docs/guides');
        file_put_contents($this->root.'/docs/readme.md', '# hi');
        file_put_contents($this->root.'/docs/guides/start.md', 'start');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function site(): Website
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));

        return Website::factory()->create(['deploy_path' => $this->root]);
    }

    public function test_compressing_runs_in_the_queue_and_reports_when_done(): void
    {
        if (! FileOperations::canZip()) {
            $this->markTestSkipped('The zip extension is not installed.');
        }

        $website = $this->site();
        Queue::fake();

        $component = Livewire::test(Files::class, ['website' => $website])
            ->call('compressEntries', ['docs'])
            ->assertDispatched('entries-queued')
            ->assertSee('Compressing docs');

        $task = PanelTask::query()->sole();
        $this->assertSame('files.compress', $task->kind);
        $this->assertSame('files:website:'.$website->id, $task->scope);
        Queue::assertPushed(RunPanelTask::class, fn ($job) => $job->taskId === $task->id);
        $this->assertFileDoesNotExist($this->root.'/docs.zip');

        (new RunPanelTask($task->id))->handle(app(PanelTaskRunner::class));

        $this->assertSame(PanelTask::SUCCESS, $task->fresh()->status);
        $this->assertSame(100, $task->fresh()->progress);
        $this->assertFileExists($this->root.'/docs.zip');

        $component->call('pollTasks')
            ->assertDispatched('toast', type: 'success', message: 'Created docs.zip.')
            ->assertSee('docs.zip');

        $zip = new \ZipArchive;
        $zip->open($this->root.'/docs.zip');
        $this->assertSame('start', $zip->getFromName('docs/guides/start.md'));
        $zip->close();
    }

    public function test_a_folder_download_is_zipped_first_then_served(): void
    {
        if (! FileOperations::canZip()) {
            $this->markTestSkipped('The zip extension is not installed.');
        }

        $website = $this->site();

        Livewire::test(Files::class, ['website' => $website])
            ->call('prepareDownload', 'docs')
            ->assertDispatched('download-ready');

        $task = PanelTask::query()->sole();
        $this->assertSame('docs.zip', $task->downloadName());
        $this->get(route('tasks.download', $task))->assertOk()->assertDownload('docs.zip');
    }

    public function test_big_deletes_and_copies_go_to_the_queue(): void
    {
        $website = $this->site();
        Queue::fake();
        $this->partialMock(FileOperations::class, fn (MockInterface $mock) => $mock->shouldReceive('isSmall')->andReturn(false));

        Livewire::test(Files::class, ['website' => $website])
            ->set('confirmName', 'docs')
            ->call('deleteEntries', ['docs'])
            ->assertDispatched('entry-deleted')
            ->call('transferEntries', ['docs'], '', 'copy');

        $this->assertDirectoryExists($this->root.'/docs', 'Nothing is deleted until the queue runs it.');
        $this->assertSame(['files.delete', 'files.copy'], PanelTask::query()->orderBy('id')->pluck('kind')->all());
        Queue::assertPushed(RunPanelTask::class, 2);
    }

    public function test_operations_stay_inside_the_root(): void
    {
        $operations = app(FileOperations::class);

        $this->assertSame(1, $operations->delete($this->root, ['docs/guides']));
        $this->assertDirectoryDoesNotExist($this->root.'/docs/guides');
        $this->assertSame(1, $operations->copy($this->root, ['docs'], ''));
        $this->assertFileExists($this->root.'/docs copy/readme.md');

        $this->expectException(\RuntimeException::class);
        $operations->delete($this->root, ['../']);
    }

    public function test_a_failed_task_shows_its_reason(): void
    {
        $website = $this->site();
        $task = PanelTask::factory()->create([
            'scope' => 'files:website:'.$website->id,
            'status' => PanelTask::FAILED,
            'label' => 'Extracting broken.zip',
            'message' => 'That archive cannot be read.',
        ]);

        Livewire::test(Files::class, ['website' => $website])
            ->assertSee('That archive cannot be read.')
            ->call('dismissTask', $task->id)
            ->assertDontSee('Extracting broken.zip');

        $this->assertModelMissing($task);
    }
}
