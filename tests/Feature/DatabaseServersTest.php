<?php

namespace Tests\Feature;

use App\Jobs\RunPanelTask;
use App\Livewire\Websites\Show;
use App\Models\PanelTask;
use App\Models\User;
use App\Models\Website;
use App\Services\DatabaseProvisioner;
use App\Services\DatabaseServers;
use App\Services\HostWriter;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class DatabaseServersTest extends TestCase
{
    use RefreshDatabase;

    private string $envDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['laravelify.databases' => ['mysql', 'sqlite']]);
        $this->envDirectory = storage_path('framework/testing/env-'.uniqid());
        File::ensureDirectoryExists($this->envDirectory);
        File::put($this->envDirectory.'/.env', "APP_NAME=Laravelify\nLARAVELIFY_DATABASES=\"mysql sqlite\"\n");
        $this->app->useEnvironmentPath($this->envDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->envDirectory);
        parent::tearDown();
    }

    public function test_creating_a_database_in_an_engine_the_server_lacks_asks_to_install_it(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $website = Website::factory()->create(['type' => 'laravel', 'db_name' => null]);

        Livewire::test(Show::class, ['website' => $website])
            ->assertSee('Create PostgreSQL')
            ->assertSee('not installed')
            ->call('provisionDatabase', 'postgresql')
            ->assertSet('installEngine', 'postgresql')
            ->assertSee('PostgreSQL is not installed on this server')
            ->call('installDatabaseEngine')
            ->assertSet('installEngine', '');

        $task = PanelTask::query()->sole();
        $this->assertSame(['databases.install', ['engine' => 'postgresql', 'website_id' => $website->id]], [$task->kind, $task->payload]);
        Queue::assertPushed(RunPanelTask::class);
        $this->assertNull($website->fresh()->db_name);
    }

    public function test_installing_saves_the_panels_login_and_creates_the_sites_database(): void
    {
        Artisan::shouldReceive('call')->once()->with('queue:restart');
        $host = Mockery::mock(HostWriter::class);
        $host->shouldReceive('databaseInstall')->once()->with('mongodb')->andReturn(['user' => 'laravelify', 'password' => 'pw123']);
        $this->app->instance(HostWriter::class, $host);
        $provisioner = Mockery::mock(DatabaseProvisioner::class);
        $website = Website::factory()->create(['db_name' => null]);
        $provisioner->shouldReceive('provisionFor')->once()->withArgs(fn (Website $site, string $engine) => $site->is($website) && $engine === 'mongodb')->andReturn('MongoDB database shop is ready.');
        $this->app->instance(DatabaseProvisioner::class, $provisioner);

        $message = app(DatabaseServers::class)->install('mongodb', $website);

        $this->assertSame('MongoDB is installed. MongoDB database shop is ready.', $message);
        $this->assertSame(['mysql', 'sqlite', 'mongodb'], config('laravelify.databases'));
        $this->assertSame('laravelify', config('laravelify.mongodb_user'));
    }

    public function test_only_postgresql_and_mongodb_are_installed_from_the_panel(): void
    {
        $this->expectExceptionMessage('Only PostgreSQL and MongoDB');

        app(DatabaseServers::class)->install('mysql');
    }
}
