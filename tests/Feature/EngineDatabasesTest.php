<?php

namespace Tests\Feature;

use App\Livewire\Database\Engine;
use App\Livewire\Database\Index;
use App\Models\BackupRun;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\EngineDatabases;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

class EngineDatabasesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        config(['laravelify.databases' => ['mysql', 'sqlite', 'mongodb'], 'laravelify.mongodb_password' => 'secret']);

        $this->root = storage_path('framework/testing/engine-'.uniqid());
        File::ensureDirectoryExists($this->root.'/.data');
        $pdo = new PDO('sqlite:'.$this->root.'/.data/database.sqlite');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Ada', 'ada@example.com'), ('Linus', 'linus@example.com')");
        $pdo->exec('CREATE TABLE logs (message TEXT)');
        Website::factory()->create(['slug' => 'blog', 'db_engine' => 'sqlite', 'deploy_path' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_the_database_list_shows_sqlite_and_mongodb_next_to_mysql(): void
    {
        $this->fakeMongo(['databases' => '[{"name":"shop","size":40960,"tables":3}]']);

        Livewire::test(Index::class)
            ->assertSee('blog')
            ->assertSee('SQLite')
            ->assertSee('shop')
            ->assertSee('MongoDB')
            ->assertSee(route('database.engine', ['sqlite', 'blog']))
            ->assertSee(route('database.engine', ['mongodb', 'shop']));
    }

    public function test_a_sqlite_table_opens_in_the_grid_with_search_filters_and_sorting(): void
    {
        $page = Livewire::test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog'])
            ->assertSee('users')
            ->assertSee('logs')
            ->set('table', 'users')
            ->assertSee('data-db-grid-payload', false)
            ->assertSee('key: id');

        $this->assertSame(['Linus', 'Ada'], array_column($page->instance()->fetchRows(app(EngineDatabases::class), 0, 100)['rows'], 'name'));
        $this->assertSame(['Ada', 'Linus'], array_column($page->instance()->fetchRows(app(EngineDatabases::class), 0, 100, 'name', 'asc')['rows'], 'name'));

        $page->set('search', 'linus');
        $this->assertSame(['Linus'], array_column($page->instance()->fetchRows(app(EngineDatabases::class), 0, 100)['rows'], 'name'));

        $page->set('search', '')->call('setColumnFilter', 'email', ['mode' => 'condition', 'operator' => 'starts', 'value' => 'ada']);
        $this->assertSame(['Ada'], array_column($page->instance()->fetchRows(app(EngineDatabases::class), 0, 100)['rows'], 'name'));

        $page->call('setColumnFilter', 'email', ['mode' => 'none']);
        $values = $page->instance()->columnValues(app(EngineDatabases::class), 'name');
        $this->assertEqualsCanonicalizing([['value' => 'Ada', 'count' => 1], ['value' => 'Linus', 'count' => 1]], $values['values']);
    }

    public function test_a_sqlite_row_is_opened_changed_added_and_deleted(): void
    {
        $page = Livewire::withQueryParams(['table' => 'users'])->test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog'])
            ->call('openRow', ['id' => 2])
            ->assertSet('editKeys', ['id' => 2])
            ->assertSet('editValues.email', 'linus@example.com')
            ->set('editValues.email', 'torvalds@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $page->call('newRow')
            ->set('editValues.name', 'Grace')
            ->set('editNulls.email', true)
            ->call('save')
            ->assertHasNoErrors()
            ->call('deleteKeys', ['{"id":1}']);

        $rows = (new PDO('sqlite:'.$this->root.'/.data/database.sqlite'))->query('SELECT name, email FROM users ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $this->assertSame([['Linus', 'torvalds@example.com'], ['Grace', null]], $rows);
    }

    public function test_rows_without_a_primary_key_are_read_only(): void
    {
        (new PDO('sqlite:'.$this->root.'/.data/database.sqlite'))->exec("INSERT INTO logs VALUES ('boot')");

        Livewire::withQueryParams(['table' => 'logs'])->test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog'])
            ->assertSee('no primary key, so rows are read-only here')
            ->assertDontSee('Add row')
            ->call('deleteKeys', ['{"message":"boot"}']);

        $this->assertSame(1, (int) (new PDO('sqlite:'.$this->root.'/.data/database.sqlite'))->query('SELECT COUNT(*) FROM logs')->fetchColumn());
    }

    public function test_sql_runs_in_the_sqlite_file(): void
    {
        Livewire::test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog'])
            ->set('tab', 'sql')
            ->set('sql', 'SELECT name FROM users ORDER BY id;')
            ->call('runSql')
            ->assertSet('sqlError', null)
            ->assertSee('Linus')
            ->set('sql', 'SELECT nope FROM users')
            ->call('runSql')
            ->assertSet('sqlError', fn (?string $error) => str_contains((string) $error, 'nope'));
    }

    public function test_mongodb_documents_are_paged_and_changed_through_mongosh(): void
    {
        $runner = $this->fakeMongo([
            'collections' => '[{"name":"products","rows":1,"size":100}]',
            'find' => '{"total":1,"rows":[{"id":"{\\"$oid\\":\\"65f0\\"}","json":"{\\"sku\\": \\"A-1\\"}"}]}',
            'save' => '{"changed":1}',
        ]);

        Livewire::test(Engine::class, ['engine' => 'mongodb', 'database' => 'shop'])
            ->set('table', 'products')
            ->assertSee('A-1')
            ->call('editRow', 0)
            ->assertSet('documentId', '{"$oid":"65f0"}')
            ->set('documentJson', '{"sku": "B-2"}')
            ->call('save')
            ->assertHasNoErrors();

        $saves = array_values(array_filter($runner->calls, fn (array $env) => $env['LFY_OP'] === 'save'));
        $this->assertCount(1, $saves);
        $this->assertSame(['db' => 'shop', 'collection' => 'products', 'id' => '{"$oid":"65f0"}', 'json' => '{"sku": "B-2"}'], json_decode($saves[0]['LFY_ARG'], true));
    }

    public function test_the_tables_list_sorts_by_name_rows_and_size(): void
    {
        $page = Livewire::test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog'])
            ->assertSeeInOrder(['logs', 'users'])
            ->call('sortTables', 'rows')
            ->assertSet('tableDirection', 'desc')
            ->assertSeeInOrder(['users', 'logs']);

        $page->call('sortTables', 'rows')->assertSet('tableDirection', 'asc')->assertSeeInOrder(['logs', 'users']);
    }

    public function test_a_sqlite_database_is_exported_as_its_gzipped_file(): void
    {
        $response = Livewire::test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog'])->call('export');

        $response->assertFileDownloaded('blog-'.now()->format('Y-m-d').'.sqlite.gz');
    }

    public function test_backups_of_other_engines_keep_their_kind_and_are_restored_by_hand(): void
    {
        $run = BackupRun::factory()->create(['kind' => 'database', 'target' => 'postgresql:shop', 'status' => BackupRun::SUCCESS, 'object_key' => 'x/shop.sql.gz']);

        $this->assertSame(['postgresql', 'shop'], $run->otherEngine());
        $this->assertSame('sql.gz', $run->fileExtension());
        $this->assertFalse($run->restorable());
        $this->assertSame('archive.gz', BackupRun::factory()->make(['kind' => 'database', 'target' => 'mongodb:shop'])->fileExtension());
        $this->assertNull(EngineDatabases::parseBackupTarget('postgresql:../etc'));
        $this->assertNull(BackupRun::factory()->make(['kind' => 'database', 'target' => 'shop'])->otherEngine());
    }

    public function test_the_sql_editor_gets_every_table_with_its_columns(): void
    {
        Livewire::test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog'])
            ->call('sqlSchema')
            ->assertReturned(['logs' => ['message'], 'users' => ['id', 'name', 'email']]);
    }

    public function test_a_selection_runs_on_its_own(): void
    {
        Livewire::test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog'])
            ->call('runSql', 'SELECT COUNT(*) AS n FROM users', "SELECT name FROM users;\nSELECT COUNT(*) AS n FROM users")
            ->assertSet('sqlResult.columns', ['n'])
            ->assertSet('sql', "SELECT name FROM users;\nSELECT COUNT(*) AS n FROM users");
    }

    public function test_a_mongodb_filter_must_be_json(): void
    {
        $this->fakeMongo([]);

        Livewire::test(Engine::class, ['engine' => 'mongodb', 'database' => 'shop'])
            ->set('table', 'products')
            ->set('search', 'status = active')
            ->assertSee('The filter must be JSON');
    }

    /**
     * @param  array<string, string>  $answers  What mongosh prints, per operation.
     */
    private function fakeMongo(array $answers): object
    {
        $runner = new class($answers) extends CommandRunner
        {
            /** @var list<array<string, string>> */
            public array $calls = [];

            public function __construct(private array $answers) {}

            public function run(array $command, ?string $cwd = null, array $env = [], ?callable $onOutput = null): CommandResult
            {
                $this->calls[] = $env;

                return new CommandResult(true, false, $command, '@@LFY@@'.($this->answers[$env['LFY_OP']] ?? '[]'), 0);
            }
        };

        $this->app->instance(CommandRunner::class, $runner);

        return $runner;
    }
}
