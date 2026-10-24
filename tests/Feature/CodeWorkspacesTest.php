<?php

namespace Tests\Feature;

use App\Http\Controllers\CodeEditorGateController;
use App\Livewire\Websites\VsCode;
use App\Livewire\Workspaces\Index;
use App\Livewire\Workspaces\Open;
use App\Models\CodeWorkspace;
use App\Models\PanelTask;
use App\Models\User;
use App\Models\Website;
use App\Services\CodeServer;
use App\Services\CodeWorkspaces;
use App\Services\HostWriter;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class CodeWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $user = User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  list<Website>  $websites
     */
    private function workspace(array $websites, array $attributes = []): CodeWorkspace
    {
        $workspace = CodeWorkspace::factory()->create($attributes);
        $workspace->websites()->attach(array_map(fn (Website $website) => $website->id, $websites));

        return $workspace;
    }

    private function editorsCanOpenEverything(): void
    {
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturn(null);
        $editors->shouldReceive('canMoveToOwnUser')->andReturn(false);
        $this->app->instance(CodeServer::class, $editors);
    }

    public function test_a_signed_in_user_reaches_a_workspace_editor(): void
    {
        $this->workspace([Website::factory()->create(), Website::factory()->create()], ['code_server_token' => str_repeat('w', 32)]);
        $uri = ['X-Original-URI' => '/code/'.str_repeat('w', 32).'/'];

        $this->get('/_access/code', $uri)->assertStatus(401);

        $this->signIn();
        $this->get('/_access/code', $uri)->assertNoContent();
    }

    public function test_a_share_link_lets_a_visitor_into_its_own_editor_only(): void
    {
        $this->workspace([Website::factory()->create(), Website::factory()->create()], ['code_server_token' => str_repeat('w', 32), 'share_token' => str_repeat('s', 40)]);
        Website::factory()->create(['code_server_token' => str_repeat('o', 32)]);

        $this->withCookie(CodeEditorGateController::SHARE_COOKIE, str_repeat('s', 40))
            ->get('/_access/code', ['X-Original-URI' => '/code/'.str_repeat('w', 32).'/static/x.js'])
            ->assertNoContent();
        $this->withCookie(CodeEditorGateController::SHARE_COOKIE, str_repeat('s', 40))
            ->get('/_access/code', ['X-Original-URI' => '/code/'.str_repeat('o', 32).'/'])
            ->assertStatus(401);
    }

    public function test_a_share_link_of_one_project_opens_that_websites_editor(): void
    {
        $website = Website::factory()->create(['code_server_token' => str_repeat('a', 32)]);
        $this->workspace([$website], ['share_token' => str_repeat('s', 40)]);

        $this->withCookie(CodeEditorGateController::SHARE_COOKIE, str_repeat('s', 40))
            ->get('/_access/code', ['X-Original-URI' => '/code/'.str_repeat('a', 32).'/'])
            ->assertNoContent();
    }

    public function test_a_link_turned_off_no_longer_lets_anyone_in(): void
    {
        $workspace = $this->workspace([Website::factory()->create(), Website::factory()->create()], ['code_server_token' => str_repeat('w', 32), 'share_token' => str_repeat('s', 40)]);
        app(CodeWorkspaces::class)->unshare($workspace);

        $this->withCookie(CodeEditorGateController::SHARE_COOKIE, str_repeat('s', 40))
            ->get('/_access/code', ['X-Original-URI' => '/code/'.str_repeat('w', 32).'/'])
            ->assertStatus(401);
        $this->get('/code-share/'.str_repeat('s', 40))->assertNotFound()->assertSee('This link is turned off');
    }

    public function test_opening_a_share_link_starts_the_editor_and_remembers_the_link(): void
    {
        $workspace = $this->workspace([Website::factory()->create(), Website::factory()->create()], ['share_token' => str_repeat('s', 40)]);
        $workspaces = Mockery::mock(CodeWorkspaces::class);
        $workspaces->shouldReceive('start')->once()->withArgs(fn ($opened) => $opened->is($workspace))
            ->andReturn(['url' => 'https://panel.test/code/'.str_repeat('w', 32).'/?workspace=x', 'installing' => null]);
        $this->app->instance(CodeWorkspaces::class, $workspaces);

        $this->get('/code-share/'.str_repeat('s', 40))
            ->assertRedirect('https://panel.test/code/'.str_repeat('w', 32).'/?workspace=x')
            ->assertCookie(CodeEditorGateController::SHARE_COOKIE, str_repeat('s', 40));
    }

    public function test_an_idle_editor_restarts_through_the_share_link_for_a_visitor(): void
    {
        $this->workspace([Website::factory()->create(), Website::factory()->create()], ['code_server_token' => str_repeat('w', 32), 'share_token' => str_repeat('s', 40)]);

        $this->get('/code-restart/'.str_repeat('w', 32))->assertRedirect(route('login'));
        $this->withCookie(CodeEditorGateController::SHARE_COOKIE, str_repeat('s', 40))
            ->get('/code-restart/'.str_repeat('w', 32))
            ->assertRedirect(route('workspaces.shared', str_repeat('s', 40)));
    }

    public function test_an_idle_editor_restarts_through_its_launcher_for_a_panel_user(): void
    {
        $this->signIn();
        $workspace = $this->workspace([Website::factory()->create(), Website::factory()->create()], ['code_server_token' => str_repeat('w', 32)]);
        $website = Website::factory()->create(['code_server_token' => str_repeat('a', 32)]);

        $this->get('/code-restart/'.str_repeat('w', 32))->assertRedirect(route('workspaces.open', $workspace));
        $this->get('/code-restart/'.str_repeat('a', 32))->assertRedirect(route('websites.vscode', $website));
    }

    public function test_a_workspace_of_picked_projects_is_named_after_them(): void
    {
        $this->signIn();
        $this->editorsCanOpenEverything();
        $shop = Website::factory()->create(['name' => 'Shop']);
        $blog = Website::factory()->create(['name' => 'Blog']);

        Livewire::test(Index::class)
            ->call('startCreate')
            ->set('selected', [$shop->id, $blog->id])
            ->call('create')
            ->assertHasNoErrors();
        Livewire::test(Index::class)
            ->call('startCreate')
            ->set('selected', [$shop->id, $blog->id])
            ->call('create');

        $this->assertSame(['Blog + Shop', 'Blog + Shop 2'], CodeWorkspace::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame([$blog->id, $shop->id], CodeWorkspace::query()->first()->websites->modelKeys());
    }

    public function test_a_workspace_needs_projects_vs_code_can_open(): void
    {
        $this->signIn();
        $shop = Website::factory()->create();
        $other = Website::factory()->create();
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturnUsing(fn (Website $website) => $website->is($other) ? 'VS Code needs a website that deploys from a GitHub repository or an example project.' : null);
        $editors->shouldReceive('canMoveToOwnUser')->andReturn(false);
        $this->app->instance(CodeServer::class, $editors);

        Livewire::test(Index::class)
            ->call('startCreate')
            ->call('create')
            ->assertHasErrors(['selected' => 'Pick a project.'])
            ->set('selected', [$shop->id, $other->id])
            ->call('create')
            ->assertHasErrors('selected');

        $this->assertSame(0, CodeWorkspace::query()->count());
    }

    public function test_a_workspace_of_one_project_is_that_websites_own_and_can_be_renamed(): void
    {
        $this->signIn();
        $this->editorsCanOpenEverything();
        $shop = Website::factory()->create(['name' => 'Shop']);

        Livewire::test(Index::class)->call('startCreate')->set('selected', [$shop->id])->call('create')->assertHasNoErrors();
        Livewire::test(Index::class)->call('startCreate')->set('selected', [$shop->id])->set('name', 'My shop')->call('create');

        $workspace = CodeWorkspace::query()->sole();
        $this->assertSame('My shop', $workspace->name);
        $this->assertSame([$shop->id], $workspace->websites->modelKeys());
    }

    public function test_the_dialog_lists_every_website_and_says_why_one_cannot_be_picked(): void
    {
        $this->signIn();
        $ready = Website::factory()->create(['name' => 'Shop']);
        $shared = Website::factory()->create(['name' => 'Legacy blog']);
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturnUsing(fn (Website $website) => $website->is($shared) ? "VS Code runs as the website's own user, and this website still runs as the shared user." : null);
        $editors->shouldReceive('canMoveToOwnUser')->andReturnUsing(fn (Website $website) => $website->is($shared));
        $this->app->instance(CodeServer::class, $editors);

        Livewire::test(Index::class)
            ->call('startCreate')
            ->assertSee('Shop')
            ->assertSee('Legacy blog')
            ->assertSee('it moves to its own user first');
        $this->assertModelExists($ready);
    }

    public function test_creating_a_workspace_moves_its_shared_user_websites_in_the_background(): void
    {
        Queue::fake();
        $this->signIn();
        $ready = Website::factory()->create(['name' => 'Shop']);
        $shared = Website::factory()->create(['name' => 'Legacy blog']);
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturnUsing(fn (Website $website) => $website->is($shared) ? "VS Code runs as the website's own user, and this website still runs as the shared user." : null);
        $editors->shouldReceive('canMoveToOwnUser')->andReturnUsing(fn (Website $website) => $website->is($shared));
        $this->app->instance(CodeServer::class, $editors);

        Livewire::test(Index::class)->call('startCreate')->set('selected', [$ready->id, $shared->id])->call('create')->assertHasNoErrors();

        $task = PanelTask::query()->sole();
        $this->assertSame('websites.own-user', $task->kind);
        $this->assertSame(['website_id' => $shared->id], $task->payload);
        $this->assertSame(1, CodeWorkspace::query()->count());
    }

    public function test_the_launcher_waits_for_a_move_then_opens_the_editor(): void
    {
        Queue::fake();
        $this->signIn();
        $shared = Website::factory()->create(['name' => 'Legacy blog']);
        $workspace = $this->workspace([$shared]);
        $moved = false;
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturnUsing(fn () => $moved ? null : "VS Code runs as the website's own user, and this website still runs as the shared user.");
        $editors->shouldReceive('canMoveToOwnUser')->andReturnUsing(fn () => ! $moved);
        $editors->shouldReceive('start')->once()->andReturn(['url' => 'https://panel.test/code/'.str_repeat('c', 32).'/', 'installing' => null]);
        $this->app->instance(CodeServer::class, $editors);

        $page = Livewire::test(Open::class, ['workspace' => $workspace])->assertSee('Moving 1 project to its own user');
        $moved = true;
        PanelTask::query()->sole()->forceFill(['status' => PanelTask::SUCCESS])->save();

        $page->call('checkMoves')->assertRedirect('https://panel.test/code/'.str_repeat('c', 32).'/');
    }

    public function test_opening_a_websites_vs_code_adds_it_to_workspaces_once(): void
    {
        $this->signIn();
        $website = Website::factory()->create(['repository' => 'https://github.com/acme/shop.git']);
        $editors = Mockery::mock(CodeServer::class);
        $editors->shouldReceive('unavailableReason')->andReturn(null);
        $editors->shouldReceive('canMoveToOwnUser')->andReturn(false);
        $editors->shouldReceive('start')->twice()->andReturn(['url' => 'https://panel.test/code/'.str_repeat('c', 32).'/', 'installing' => null]);
        $this->app->instance(CodeServer::class, $editors);

        Livewire::test(VsCode::class, ['website' => $website])->call('launch');
        Livewire::test(VsCode::class, ['website' => $website])->call('launch');

        $workspace = CodeWorkspace::query()->sole();
        $this->assertSame($website->name, $workspace->name);
        $this->assertSame([$website->id], $workspace->websites->modelKeys());
        $this->assertNotNull($workspace->opened_at);
    }

    public function test_deleting_a_workspace_of_several_projects_removes_its_editor_user_and_access(): void
    {
        $this->signIn();
        $shop = Website::factory()->create(['slug' => 'shop', 'name' => 'Shop']);
        $blog = Website::factory()->create(['slug' => 'blog', 'name' => 'Blog']);
        $workspace = $this->workspace([$shop, $blog], ['key' => 'abc123defg']);
        $host = Mockery::mock(HostWriter::class);
        $host->shouldReceive('workspaceDrop')->once()->with('abc123defg', ['blog', 'shop'])->andReturn('dropped');
        $this->app->instance(HostWriter::class, $host);

        Livewire::test(Index::class)->call('delete', $workspace->id);

        $this->assertModelMissing($workspace);
        $this->assertModelExists($shop);
    }

    public function test_sharing_makes_a_new_link_and_the_old_one_stops_working(): void
    {
        $this->signIn();
        $workspace = $this->workspace([Website::factory()->create(), Website::factory()->create()], ['code_server_token' => str_repeat('w', 32)]);

        Livewire::test(Index::class)->call('share', $workspace->id);
        $first = $workspace->fresh()->share_token;
        Livewire::test(Index::class)->call('share', $workspace->id);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{40}$/', (string) $first);
        $this->assertNotSame($first, $workspace->fresh()->share_token);
        auth()->logout();
        $this->withCookie(CodeEditorGateController::SHARE_COOKIE, (string) $first)
            ->get('/_access/code', ['X-Original-URI' => '/code/'.str_repeat('w', 32).'/'])
            ->assertStatus(401);
    }

    public function test_the_workspaces_page_lists_workspaces_and_their_projects(): void
    {
        $this->signIn();
        $this->workspace([Website::factory()->create(['name' => 'Shop']), Website::factory()->create(['name' => 'Blog'])], ['name' => 'Blog + Shop']);

        $this->get(route('workspaces.index'))->assertOk()->assertSee('Blog + Shop')->assertSee('Create workspace');
    }
}
