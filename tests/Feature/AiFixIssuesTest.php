<?php

namespace Tests\Feature;

use App\Livewire\Ai\Chat;
use App\Mcp\Servers\LaravelifyServer;
use App\Mcp\Tools\CommitFiles;
use App\Mcp\Tools\ListIssues;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\RepositoryWriter;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AiFixIssuesTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://127.0.0.1:8095/glitchtip/api/0';

    private User $admin;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->admin);
        Setting::putValue('glitchtip.token', 'tok');
        $this->website = Website::factory()->create(['name' => 'Shop', 'slug' => 'shop', 'glitchtip_project' => 'shop', 'glitchtip_project_id' => 7, 'repository' => 'https://github.com/acme/shop.git', 'branch' => 'main']);

        Http::fake([
            self::API.'/organizations/laravelify/issues/9/events/latest/*' => Http::response([
                'entries' => [['type' => 'exception', 'data' => ['values' => [[
                    'type' => 'ErrorException', 'value' => 'Undefined variable $cart',
                    'stacktrace' => ['frames' => [['filename' => 'app/Http/Controllers/CartController.php', 'function' => 'show', 'lineNo' => 12, 'inApp' => true, 'context' => [[12, 'return $cart->sum();']]]]],
                ]]]]],
            ]),
            self::API.'/organizations/laravelify/issues/*' => Http::response([[
                'id' => '9', 'shortId' => 'SHOP-9', 'title' => 'ErrorException: Undefined variable $cart', 'culprit' => 'CartController@show',
                'level' => 'error', 'count' => '14', 'userCount' => 3, 'metadata' => ['type' => 'ErrorException'], 'project' => ['id' => '7'],
                'firstSeen' => now()->toIso8601String(), 'lastSeen' => now()->toIso8601String(),
            ]], 200, ['X-Hits' => '1']),
        ]);
    }

    public function test_the_ai_reads_a_sites_issues_with_their_stack_traces(): void
    {
        LaravelifyServer::actingAs($this->admin)->tool(ListIssues::class, ['website' => 'shop', 'details' => true])
            ->assertOk()
            ->assertSee('Undefined variable $cart')
            ->assertSee('app/Http/Controllers/CartController.php')
            ->assertSee('return $cart->sum();');
    }

    public function test_the_ai_commits_fixes_to_the_repository_never_to_env(): void
    {
        $writer = Mockery::mock(RepositoryWriter::class);
        $writer->shouldReceive('commit')->once()
            ->withArgs(fn (Website $website, array $files, string $message) => $website->is($this->website) && array_keys($files) === ['app/Http/Controllers/CartController.php'] && $message === 'Fix the missing cart')
            ->andReturn(['commit' => 'abc1234', 'files' => ['app/Http/Controllers/CartController.php'], 'deploying' => true]);
        $this->app->instance(RepositoryWriter::class, $writer);
        $server = LaravelifyServer::actingAs($this->admin);

        $server->tool(CommitFiles::class, ['website' => 'shop', 'message' => 'x', 'files' => [['path' => '.env', 'content' => 'APP_KEY=1']]])
            ->assertHasErrors(['update-environment']);

        $server->tool(CommitFiles::class, ['website' => 'shop', 'message' => 'Fix the missing cart', 'files' => [['path' => 'app/Http/Controllers/CartController.php', 'content' => '<?php // fixed']]])
            ->assertOk()->assertSee('abc1234')->assertSee('get-deployment');
    }

    public function test_paths_outside_the_repository_are_refused_before_anything_runs(): void
    {
        foreach (['../etc/passwd', '.git/config', 'a/../../b', '/', "x\0y"] as $path) {
            try {
                app(RepositoryWriter::class)->commit($this->website, [$path => 'x'], 'm');
                $this->fail($path.' should be refused.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Not a file path', $exception->getMessage(), $path);
            }
        }
    }

    public function test_the_chat_attaches_one_issue_with_its_stack_trace(): void
    {
        Livewire::test(Chat::class)
            ->call('openPicker', 'issue')
            ->assertSee('Shop')
            ->set('issueWebsite', $this->website->id)
            ->assertSee('ErrorException')
            ->call('addIssue', '9')
            ->assertSet('picker', null)
            ->assertSet('attached.0.kind', 'issue')
            ->assertSet('attached.0.text', fn (string $text) => str_contains($text, 'Undefined variable $cart') && str_contains($text, 'CartController.php:12') && str_contains($text, '12 > return $cart->sum();'));
    }

    public function test_fix_a_website_attaches_its_issues_and_writes_the_task(): void
    {
        Livewire::test(Chat::class)
            ->call('openPicker', 'fix')
            ->call('fixWebsite', $this->website->id)
            ->assertSet('attached.0.label', 'Shop · 1 open issue')
            ->assertSet('message', fn (string $message) => str_contains($message, 'Check all open issues of Shop and fix them') && str_contains($message, 'commit-files'));
    }
}
