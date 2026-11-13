<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\DatabaseImporter;
use App\Support\DatabaseEngines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PostgresImportTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    /** @var list<array{command: list<string>, env: array<string, string>, passfile: string}> */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-pg-import-'.uniqid();
        File::ensureDirectoryExists($this->root);
        File::put($this->root.'/dump.sql.gz', gzencode("CREATE TABLE shops (id int);\n"));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function site(): Website
    {
        return Website::factory()->create([
            'type' => 'spring', 'deploy_path' => $this->root, 'provision_database' => true, 'db_engine' => DatabaseEngines::POSTGRESQL,
            'db_name' => 'shop', 'db_username' => 'shop', 'db_password' => 'pa:ss\\word',
        ]);
    }

    private function runner(bool $ok = true, string $output = ''): void
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command, ?string $cwd = null, array $env = []) use ($ok, $output): CommandResult {
            $this->ran[] = ['command' => $command, 'env' => $env, 'passfile' => is_file($env['PGPASSFILE'] ?? '') ? (string) file_get_contents($env['PGPASSFILE']) : ''];

            return new CommandResult($ok, false, $command, $output, $ok ? 0 : 3);
        });
        $this->app->instance(CommandRunner::class, $runner);
    }

    public function test_a_gzipped_dump_goes_through_psql_as_the_site_role(): void
    {
        $this->runner();

        $message = app(DatabaseImporter::class)->import($this->site(), 'dump.sql.gz', reset: true);

        $this->assertSame('Imported dump.sql.gz into shop (the database was emptied first).', $message);
        [$reset, $import] = $this->ran;
        $this->assertContains('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;', $reset['command']);
        $this->assertStringContainsString('psql -X -q -v ON_ERROR_STOP=1 -f -', $import['command'][2]);
        $this->assertStringContainsString('OWNER TO', $import['command'][2]);
        $this->assertSame(['dump.sql.gz', '1'], [basename($import['command'][4]), $import['command'][5]]);
        $this->assertSame(['shop', 'shop'], [$import['env']['PGUSER'], $import['env']['PGDATABASE']]);

        // The password is only in the private passfile (escaped), never in the command, and the file is gone after.
        $this->assertSame("127.0.0.1:5432:shop:shop:pa\\:ss\\\\word\n", $import['passfile']);
        $this->assertStringNotContainsString('pa:ss', implode(' ', $import['command']));
        $this->assertFileDoesNotExist($import['env']['PGPASSFILE']);
    }

    public function test_the_first_postgres_error_is_shown(): void
    {
        $this->runner(false, "psql:<stdin>:12: ERROR:  relation \"shops\" already exists\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PostgreSQL stopped the import: psql:<stdin>:12: ERROR:  relation "shops" already exists If a table already exists');

        app(DatabaseImporter::class)->import($this->site(), 'dump.sql.gz', reset: false);
    }

    public function test_other_engines_are_still_refused(): void
    {
        $site = $this->site();
        $site->update(['db_engine' => DatabaseEngines::MONGODB]);

        $this->expectExceptionMessage('works for MySQL, MariaDB, and PostgreSQL only');

        app(DatabaseImporter::class)->prepare($site, 'dump.sql.gz');
    }
}
