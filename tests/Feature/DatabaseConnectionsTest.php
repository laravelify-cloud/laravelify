<?php

namespace Tests\Feature;

use App\Livewire\Database\Engine;
use App\Livewire\Database\Index;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\EngineDatabases;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class DatabaseConnectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        config(['laravelify.databases' => ['sqlite']]);
    }

    public function test_an_outside_database_is_saved_with_its_password_encrypted(): void
    {
        Livewire::test(Index::class)
            ->call('openConnection')
            ->assertSet('connection.driver', 'sqlsrv')
            ->assertSet('connection.port', '1433')
            ->set('connection.name', 'ERP')
            ->set('connection.host', 'SQL01')
            ->set('connection.instance', 'SQLEXPRESS')
            ->set('connection.port', '')
            ->set('connection.database', 'erp')
            ->set('connection.username', 'sa')
            ->call('saveConnection')
            ->assertHasErrors('connection.password')
            ->set('connection.password', 's3cret!')
            ->set('connection.host', 'sql.example.com; DROP')
            ->call('saveConnection')
            ->assertHasErrors('connection.host')
            ->set('connection.host', 'SQL01')
            ->call('saveConnection')
            ->assertHasNoErrors()
            ->assertSee('SQL Server · SQL01')
            ->assertSee(route('database.engine', ['remote', 'erp']));

        $connection = DatabaseConnection::query()->sole();
        $this->assertSame(['erp', 'sqlsrv', 0, 'SQLEXPRESS'], [$connection->slug, $connection->driver, $connection->port, $connection->instance]);
        $this->assertSame('s3cret!', $connection->password);
        $this->assertStringNotContainsString('s3cret!', (string) $connection->getRawOriginal('password'));
    }

    public function test_a_pasted_connection_string_fills_the_fields(): void
    {
        Livewire::test(Index::class)
            ->call('openConnection')
            ->set('connectionMode', 'string')
            ->set('connectionString', 'Data Source=.\SQLEXPRESS;Initial Catalog=erp;User ID=app;Password="p;w";Encrypt=True;TrustServerCertificate=False;Application Name="SQL Server Management Studio"')
            ->call('applyConnectionString')
            ->assertSet('connectionMode', 'default')
            ->assertSet('connection.host', 'localhost')
            ->assertSet('connection.instance', 'SQLEXPRESS')
            ->assertSet('connection.port', '')
            ->assertSet('connection.database', 'erp')
            ->assertSet('connection.username', 'app')
            ->assertSet('connection.password', 'p;w')
            ->assertSet('connection.encrypt', true)
            ->assertSet('connection.trust_certificate', false)
            ->assertSet('connection.name', 'erp')
            ->assertSee('sqlserver://app@localhost\SQLEXPRESS/erp')
            ->set('connectionMode', 'string')
            ->set('connectionString', 'Server=(local);Integrated Security=SSPI;Database=x')
            ->call('applyConnectionString')
            ->assertSet('connectionIntegrated', true)
            ->assertSee('A Linux server cannot');
    }

    public function test_urls_and_jdbc_strings_are_read(): void
    {
        $this->assertSame(['driver' => 'pgsql', 'host' => 'db.example.com', 'port' => 6543, 'username' => 'app', 'password' => 'p@ss', 'database' => 'main', 'ssl_mode' => 'require'],
            DatabaseConnection::parse('postgresql://app:p%40ss@db.example.com:6543/main?sslmode=require'));
        $this->assertSame(['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'database' => 'shop'], DatabaseConnection::parse('jdbc:mysql://localhost:3306/shop'));
        $this->assertSame(['driver' => 'sqlsrv', 'port' => 1433, 'host' => 'sql01', 'database' => 'erp', 'encrypt' => true],
            DatabaseConnection::parse('jdbc:sqlserver://sql01:1433;databaseName=erp;encrypt=true'));
        $this->assertSame([], DatabaseConnection::parse('hello world'));
    }

    public function test_a_read_only_connection_changes_nothing(): void
    {
        DatabaseConnection::factory()->create(['slug' => 'erp', 'read_only' => true]);
        $engines = app(EngineDatabases::class);

        $this->assertFalse($engines->writable('remote', 'erp'));
        $this->expectExceptionMessage('read-only');
        $engines->run('remote', 'erp', 'DELETE FROM users');
    }

    public function test_a_sqlite_file_is_picked_from_the_file_manager_and_opened(): void
    {
        $root = storage_path('framework/testing/files-'.uniqid());
        File::ensureDirectoryExists($root.'/shop/database');
        $pdo = new \PDO('sqlite:'.$root.'/shop/database/app.sqlite');
        $pdo->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT); INSERT INTO posts (title) VALUES ('Hello')");
        File::put($root.'/shop/notes.txt', 'x');
        config(['laravelify.files_root' => $root]);

        try {
            Livewire::test(Index::class)
                ->call('openConnection')
                ->call('pickDriver', 'sqlite')
                ->assertSee('Browse')
                ->call('openFilePicker')
                ->assertSee('shop')
                ->call('pickerGo', 'shop/database')
                ->assertSee('app.sqlite')
                ->assertDontSee('notes.txt')
                ->call('pickFile', 'shop/database/app.sqlite')
                ->assertSet('pickerOpen', false)
                ->assertSet('connection.name', 'app')
                ->call('testConnection')
                ->assertSet('connectionTest.ok', true)
                ->assertSet('connectionTest.message', fn (string $message) => str_contains($message, '1 tables'))
                ->call('saveConnection')
                ->assertHasNoErrors()
                ->assertSee('SQLite · app.sqlite');

            $connection = DatabaseConnection::query()->sole();
            $this->assertSame(['sqlite', realpath($root.'/shop/database/app.sqlite'), null], [$connection->driver, $connection->database, $connection->username]);

            Livewire::withQueryParams(['table' => 'posts'])->test(Engine::class, ['engine' => 'remote', 'database' => $connection->slug])
                ->assertSee('data-db-grid-payload', false)
                ->assertSee('key: id');

            // A file outside the file manager is refused.
            Livewire::test(Index::class)
                ->call('openConnection', $connection->slug)
                ->set('connection.database', '/etc/passwd')
                ->call('saveConnection')
                ->assertHasErrors('connection.database');
        } finally {
            unset($pdo);
            File::deleteDirectory($root);
        }
    }

    public function test_changing_a_connection_keeps_its_password_when_left_empty(): void
    {
        $connection = DatabaseConnection::factory()->create(['slug' => 'erp', 'password' => 'old']);

        Livewire::test(Index::class)
            ->call('openConnection', 'erp')
            ->assertSet('connection.password', '')
            ->call('pickDriver', 'mysql')
            ->assertSet('connection.port', '3306')
            ->set('connection.host', '10.0.0.5')
            ->call('saveConnection')
            ->assertHasNoErrors();

        $connection->refresh();
        $this->assertSame(['mysql', '10.0.0.5', 3306, 'old'], [$connection->driver, $connection->host, $connection->port, $connection->password]);
    }

    public function test_a_connection_that_cannot_be_reached_says_why(): void
    {
        Livewire::test(Index::class)
            ->call('openConnection')
            ->call('pickDriver', 'mysql')
            ->set('connection.name', 'Old shop')
            ->set('connection.host', '127.0.0.1')
            ->set('connection.port', '1')
            ->set('connection.database', 'shop')
            ->set('connection.username', 'root')
            ->set('connection.password', 'x')
            ->call('testConnection')
            ->assertSet('connectionTest.ok', false)
            ->assertSet('connectionTest.message', fn (string $message) => str_starts_with($message, 'Could not connect: '));

        $this->assertSame(0, DatabaseConnection::query()->count());
    }

    public function test_sql_server_queries_use_its_own_syntax(): void
    {
        $connection = DatabaseConnection::factory()->create(['slug' => 'erp', 'host' => '127.0.0.1', 'port' => 1]);
        $engines = app(EngineDatabases::class);

        $this->assertSame('sqlsrv', $engines->dialect('remote', 'erp'));
        $this->assertSame('SQL Server', $engines->label('remote', 'erp'));

        // Without the driver the page says how to get it; with it, nothing listens on port 1.
        $this->expectExceptionMessage(extension_loaded('pdo_sqlsrv') ? 'SQL Server: ' : 'has no pdo_sqlsrv');

        $engines->tables('remote', $connection->slug);
    }

    public function test_an_unknown_connection_is_not_found(): void
    {
        $this->get(route('database.engine', ['remote', 'nope']))->assertNotFound();

        DatabaseConnection::factory()->create(['slug' => 'erp']);
        Livewire::test(Engine::class, ['engine' => 'remote', 'database' => 'erp'])->assertSee('ERP')->assertSee('SQL Server');
    }

    public function test_new_databases_go_on_each_writable_mysql_or_postgresql_server_once(): void
    {
        DatabaseConnection::factory()->create(['name' => 'Shop', 'slug' => 'shop', 'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5433, 'username' => 'app']);
        DatabaseConnection::factory()->create(['name' => 'Shop / logs', 'slug' => 'shop-logs', 'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5433, 'username' => 'app', 'database' => 'logs']);
        DatabaseConnection::factory()->create(['name' => 'Reports', 'slug' => 'reports', 'driver' => 'mysql', 'host' => '10.0.0.5', 'port' => 3306, 'read_only' => true]);
        DatabaseConnection::factory()->create(['name' => 'ERP', 'slug' => 'erp', 'driver' => 'sqlsrv']);

        $this->assertSame(['shop'], array_keys(app(EngineDatabases::class)->databaseServers()));

        Livewire::test(Index::class)
            ->call('openNewDatabase')
            ->assertSet('newDatabaseServer', 'shop')
            ->assertSee('New database')
            ->set('newDatabaseName', 'drop table;')
            ->call('createDatabase')
            ->assertHasErrors('newDatabaseName')
            ->set('newDatabaseServer', 'erp')
            ->set('newDatabaseName', 'shop2')
            ->call('createDatabase')
            ->assertHasErrors('newDatabaseName');

        $this->assertSame(4, DatabaseConnection::query()->count(), 'nothing was made');
    }

    public function test_schemas_are_only_made_in_postgresql_and_sql_server(): void
    {
        $file = storage_path('framework/testing/schemas.sqlite');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, '');
        DatabaseConnection::factory()->create(['slug' => 'local-file', 'driver' => 'sqlite', 'database' => $file, 'host' => null, 'port' => 0]);

        Livewire::test(Engine::class, ['engine' => 'remote', 'database' => 'local-file'])
            ->assertDontSee('New schema')
            ->set('newSchema', 'reporting')
            ->call('createSchema')
            ->assertHasErrors('newSchema');

        $this->expectExceptionMessage('Use letters');
        app(EngineDatabases::class)->createSchema('remote', 'local-file', 'bad name');
    }
}
