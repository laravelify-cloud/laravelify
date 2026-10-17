<?php

namespace Tests\Feature;

use App\Livewire\Deployments\Index as DeploymentsIndex;
use App\Livewire\Websites\Terminal;
use App\Models\Deployment;
use App\Models\User;
use App\Models\Website;
use App\Services\TerminalSessions;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InteractiveTerminalTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'abcdefghijklmnopqrstuvwxyz012345';

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/terminal/'.self::SESSION));

        parent::tearDown();
    }

    public function test_a_command_that_asks_a_question_gets_the_answer_typed_in_the_console(): void
    {
        if (! Process::isPtySupported()) {
            $this->markTestSkipped('This machine cannot give commands a terminal.');
        }

        $user = $this->signIn();
        $this->makeSession(['bash', '-c', 'read -p "Proceed? (Y/n) " answer; echo "got:$answer"'], $user);

        $sessions = app(TerminalSessions::class);
        $this->assertTrue($sessions->write(self::SESSION, "yes\n", $user->id));
        Artisan::call('terminal:run', ['id' => self::SESSION]);

        $result = $sessions->read(self::SESSION, 0, $user->id);
        $output = base64_decode($result['data']);
        $this->assertStringContainsString('Proceed? (Y/n)', $output);
        $this->assertStringContainsString('got:yes', $output);
        $this->assertSame([true, 0], [$result['done'], $result['exit']]);

        $this->getJson(route('terminal.sessions.output', ['session' => self::SESSION, 'offset' => $result['offset']]))
            ->assertOk()
            ->assertJson(['data' => '', 'done' => true, 'exit' => 0]);
    }

    public function test_only_the_person_who_started_a_session_can_read_or_type_into_it(): void
    {
        $owner = $this->signIn();
        $this->makeSession(['true'], $owner);

        $this->actingAs($this->confirmedUser());
        $this->getJson(route('terminal.sessions.output', ['session' => self::SESSION]))->assertNotFound();
        $this->postJson(route('terminal.sessions.input', ['session' => self::SESSION]), ['data' => 'x'])->assertNotFound();
        $this->postJson(route('terminal.sessions.stop', ['session' => self::SESSION]))->assertNotFound();
        $this->getJson(route('terminal.sessions.output', ['session' => '../../etc/passwd']))->assertNotFound();

        $this->actingAs($owner);
        $this->postJson(route('terminal.sessions.input', ['session' => self::SESSION]), ['data' => 'ls'])->assertOk();
        $this->assertSame('ls', File::get(storage_path('app/terminal/'.self::SESSION.'/in')));
    }

    public function test_running_a_command_starts_a_session_the_console_follows(): void
    {
        $this->signIn();
        $website = Website::factory()->create();
        $this->mock(TerminalSessions::class, function ($mock): void {
            $mock->shouldReceive('start')->once()->andReturn(self::SESSION);
        });

        Livewire::test(Terminal::class, ['website' => $website])
            ->set('command', 'pnpm install')
            ->call('run')
            ->assertDispatched('terminal-session', id: self::SESSION, command: 'pnpm install')
            ->assertSet('command', '');
    }

    public function test_a_deployment_log_streams_in_parts_with_its_state(): void
    {
        $this->signIn();
        $deployment = Deployment::query()->create([
            'website_id' => Website::factory()->create()->id,
            'status' => 'running',
            'trigger' => 'manual',
            'log' => "Deploying app.\n$ git fetch",
        ]);

        $first = $this->getJson(route('deployments.log', $deployment))->assertOk()->json();
        $this->assertTrue($first['live']);
        $this->assertSame(strlen((string) $deployment->log), $first['offset']);
        $this->assertStringContainsString("\e[36m$ git fetch\e[0m", $first['text']);

        $deployment->update(['log' => $deployment->log."\nDeploy finished in 3s.", 'status' => 'success']);

        $second = $this->getJson(route('deployments.log', ['deployment' => $deployment, 'offset' => $first['offset']]))->json();
        $this->assertFalse($second['live']);
        $this->assertStringContainsString('Deploy finished in 3s.', $second['text']);
        $this->assertStringNotContainsString('git fetch', $second['text']);
    }

    public function test_the_deployments_page_shows_each_website_with_its_latest_deployment_and_deploys(): void
    {
        $this->signIn();
        $running = Website::factory()->create(['name' => 'Shop Backend']);
        $never = Website::factory()->create(['name' => 'Blog Frontend']);
        Deployment::query()->create(['website_id' => $running->id, 'status' => 'success', 'trigger' => 'push', 'log' => '']);
        Deployment::query()->create(['website_id' => $running->id, 'status' => 'running', 'trigger' => 'manual', 'commit_sha' => 'abc1234def', 'commit_message' => 'Fix checkout', 'log' => '']);

        Livewire::test(DeploymentsIndex::class)
            ->assertSee('Shop Backend')
            ->assertSee('Blog Frontend')
            ->assertSee('Fix checkout')
            ->assertSee('Never deployed')
            ->assertSee('1 in progress')
            ->set('filter', 'running')
            ->assertSee('Shop Backend')
            ->assertDontSee('Blog Frontend')
            ->set('filter', 'all')
            ->call('deploy', $never->id);

        $this->assertSame(1, $never->deployments()->count());
        $this->get(route('deployments.index'))->assertOk()->assertSee('All deployments');
    }

    /**
     * @param  list<string>  $argv
     */
    private function makeSession(array $argv, User $user): void
    {
        $folder = storage_path('app/terminal/'.self::SESSION);
        File::ensureDirectoryExists($folder);
        File::put($folder.'/meta.json', (string) json_encode(['argv' => $argv, 'user' => $user->id, 'command' => 'test', 'started' => time()]));
        File::put($folder.'/out', '');
        File::put($folder.'/in', '');
    }

    private function confirmedUser(): User
    {
        return User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function signIn(): User
    {
        $user = $this->confirmedUser();
        $this->actingAs($user);

        return $user;
    }
}
