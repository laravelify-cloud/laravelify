<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\ExampleRepositories;
use App\Services\SiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ExampleRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-examples-'.uniqid();
        File::ensureDirectoryExists($this->storage);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_the_deploy_makes_the_example_repository_in_the_site_users_home(): void
    {
        $examples = app(ExampleRepositories::class);
        $website = Website::factory()->create(['type' => 'express', 'slug' => 'try-express', 'branch' => 'main']);
        $website->update(['repository' => $examples->pathFor('try-express')]);
        $site = app(SiteUser::class)->for($website);

        $this->assertSame('Made the example repository in '.$site->home().'/examples/try-express.', $examples->ensure($website, $site));

        $repository = $website->fresh()->repository;
        $this->assertSame($site->home().'/examples/try-express', $repository);
        $this->assertFileExists($repository.'/server.js');
        $this->assertSame('Example project', trim(Process::run(['git', '-C', $repository, 'log', '-1', '--format=%s', 'main'])->output()));

        // The next deploy fetches from it as it is.
        $this->assertNull($examples->ensure($website->fresh(), $site));
    }

    public function test_repositories_in_the_panels_storage_move_out_with_their_history(): void
    {
        $legacy = storage_path('app/examples/old-shop');
        File::ensureDirectoryExists($legacy);
        File::put($legacy.'/index.html', 'edited by hand');
        Process::path($legacy)->run(['git', 'init', '-q', '-b', 'main'])->throw();
        Process::path($legacy)->run(['git', 'add', '-A'])->throw();
        Process::path($legacy)->run(['git', '-c', 'user.name=Ada', '-c', 'user.email=ada@localhost', 'commit', '-q', '-m', 'Kept commit'])->throw();

        $website = Website::factory()->create(['type' => 'static', 'slug' => 'old-shop', 'branch' => 'main', 'repository' => $legacy]);
        $site = app(SiteUser::class)->for($website);

        app(ExampleRepositories::class)->ensure($website, $site);

        $repository = $website->fresh()->repository;
        $this->assertSame($site->home().'/examples/old-shop', $repository);
        $this->assertStringEqualsFile($repository.'/index.html', 'edited by hand');
        $this->assertSame('Kept commit', trim(Process::run(['git', '-C', $repository, 'log', '-1', '--format=%s'])->output()));

        // Windows cannot delete git's read-only object files this way; the server can.
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertDirectoryDoesNotExist($legacy);
        }
    }

    public function test_other_repositories_are_left_alone(): void
    {
        $website = Website::factory()->create(['slug' => 'shop', 'repository' => 'https://github.com/acme/shop.git']);
        $elsewhere = Website::factory()->create(['slug' => 'blog', 'repository' => storage_path('app/examples/shop')]);

        $this->assertNull(app(ExampleRepositories::class)->ensure($website, app(SiteUser::class)->for($website)));
        $this->assertNull(app(ExampleRepositories::class)->ensure($elsewhere, app(SiteUser::class)->for($elsewhere)));
        $this->assertSame('https://github.com/acme/shop.git', $website->fresh()->repository);
        $this->assertSame(storage_path('app/examples/shop'), $elsewhere->fresh()->repository);
    }
}
