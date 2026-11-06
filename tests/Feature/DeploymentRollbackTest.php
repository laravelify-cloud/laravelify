<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Livewire\Websites\Show;
use App\Models\User;
use App\Models\Website;
use App\Services\Deployer;
use App\Services\DeploymentLogger;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
    }

    public function test_an_earlier_deploy_can_be_rolled_back_to_and_the_live_one_is_marked(): void
    {
        Queue::fake();
        $website = Website::factory()->create(['enabled' => true]);
        $old = $website->deployments()->create(['status' => 'success', 'trigger' => 'webhook', 'commit_sha' => str_repeat('a', 40), 'commit_message' => "Add the cart\nmore", 'log' => '', 'created_at' => now()->subHour()]);
        $live = $website->deployments()->create(['status' => 'success', 'trigger' => 'webhook', 'commit_sha' => str_repeat('b', 40), 'commit_message' => 'Break the cart', 'log' => '']);

        $this->assertTrue($live->is($website->liveDeployment()));

        Livewire::test(Show::class, ['website' => $website])
            ->assertSee('Roll back to this')
            ->call('rollBack', $old->id);

        $rollback = $website->deployments()->where('trigger', 'rollback')->sole();
        $this->assertSame([str_repeat('a', 40), 'queued', 'Roll back to aaaaaaa: Add the cart'], [$rollback->target_sha, $rollback->status, $rollback->commit_message]);
        Queue::assertPushed(RunDeployment::class);
    }

    public function test_a_rollback_saves_the_live_code_then_puts_the_older_commit_live(): void
    {
        config(['laravelify.host_commands' => true]);
        $path = storage_path('framework/testing/rollback-'.uniqid());
        File::ensureDirectoryExists($path);
        $git = fn (string ...$args) => tap(new Process(['git', '-C', $path, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args]))->mustRun()->getOutput();
        $git('init', '-q', '-b', 'main');
        File::put($path.'/app.txt', "version 1\n");
        $git('add', '.');
        $git('commit', '-qm', 'one');
        $first = trim($git('rev-parse', 'HEAD'));
        File::put($path.'/app.txt', "version 2\n");
        $git('commit', '-qam', 'two');
        $second = trim($git('rev-parse', 'HEAD'));

        $website = Website::factory()->create(['deploy_path' => $path, 'branch' => 'main', 'slug' => 'rollsite']);
        $deployment = $website->deployments()->create(['status' => 'running', 'trigger' => 'rollback', 'target_sha' => $first, 'log' => '']);
        $deployer = app(Deployer::class);
        (new ReflectionMethod($deployer, 'actAsSiteOf'))->invoke($deployer, $website);
        (new ReflectionMethod($deployer, 'rollBackTo'))->invoke($deployer, $website, $deployment, $first, $path, new DeploymentLogger($deployment));

        $this->assertSame("version 1\n", str_replace("\r\n", "\n", File::get($path.'/app.txt')), 'The older commit is live.');
        $this->assertSame($first, trim($git('rev-parse', 'HEAD')));

        $snapshot = (string) $deployment->fresh()->snapshot_path;
        $this->assertStringEndsWith('-'.substr($second, 0, 7).'.tar.gz', $snapshot, 'The code that was live is saved, named by its commit.');
        $this->assertFileExists($snapshot);
        $this->assertStringContainsString('version 2', (string) gzdecode((string) file_get_contents($snapshot)));

        File::deleteDirectory($path);
        File::delete($snapshot);
    }
}
