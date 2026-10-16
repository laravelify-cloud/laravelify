<?php

namespace Tests\Feature;

use App\Livewire\Database\Index as DatabaseIndex;
use App\Livewire\Database\Show as DatabaseShow;
use App\Livewire\Database\Table as DatabaseTable;
use App\Models\CryptoMethod;
use App\Models\DatabaseChange;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\DatabaseBrowser;
use App\Services\DatabaseSql;
use App\Services\SiteCommand;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Runs against a real MySQL server (LARAVELIFY_TEST_MYSQL_* or root/root on 127.0.0.1), and is
 * skipped where there is none.
 */
class DatabaseToolTest extends TestCase
{
    use RefreshDatabase;

    private const DB = 'lv_tool_test';

    private DatabaseBrowser $browser;

    protected function setUp(): void
    {
        parent::setUp();

        $config = [
            'driver' => 'mysql',
            'host' => env('LARAVELIFY_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('LARAVELIFY_TEST_MYSQL_PORT', '3306'),
            'username' => env('LARAVELIFY_TEST_MYSQL_USER', 'root'),
            'password' => env('LARAVELIFY_TEST_MYSQL_PASSWORD', 'root'),
            'database' => 'information_schema',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        try {
            new PDO('mysql:host='.$config['host'].';port='.$config['port'], $config['username'], $config['password'], [PDO::ATTR_TIMEOUT => 2]);
        } catch (Throwable) {
            $this->markTestSkipped('No MySQL server to test the Database tool against.');
        }

        config(['database.connections.'.DatabaseBrowser::CONNECTION => $config]);
        DB::purge(DatabaseBrowser::CONNECTION);
        $this->browser = app(DatabaseBrowser::class);
        $db = $this->browser->connection();
        $db->unprepared('DROP DATABASE IF EXISTS `'.self::DB.'`');
        $db->unprepared('CREATE DATABASE `'.self::DB.'`');
        $db->unprepared('CREATE TABLE `'.self::DB.'`.`people` (id INT AUTO_INCREMENT PRIMARY KEY, firstname VARCHAR(80), passport VARCHAR(20), city VARCHAR(80) NULL)');
        $db->unprepared('CREATE TABLE `'.self::DB.'`.`logs` (line TEXT)');
        $db->insert('INSERT INTO `'.self::DB.'`.`people` (firstname, passport, city) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)', ['Arshad', 'AZE1234567', 'Baku', 'Leyla', 'AZE7654321', 'Ganja', 'Murad', 'X99', null]);

        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->browser)) {
            $this->browser->connection()->unprepared('DROP DATABASE IF EXISTS `'.self::DB.'`');
        }

        parent::tearDown();
    }

    public function test_databases_tables_columns_and_keys_are_listed(): void
    {
        $this->assertContains(self::DB, array_column($this->browser->databases(), 'name'));
        $this->assertSame(['logs', 'people'], array_column($this->browser->tables(self::DB), 'name'));
        $this->assertSame(['id', 'firstname', 'passport', 'city'], array_column($this->browser->columns(self::DB, 'people'), 'name'));
        $this->assertSame(['id'], $this->browser->keyColumns(self::DB, 'people'));
        $this->assertSame([], $this->browser->keyColumns(self::DB, 'logs'));
    }

    public function test_rows_are_paged_sorted_searched_and_filtered(): void
    {
        $page = $this->browser->rows(self::DB, 'people', ['per_page' => 10, 'sort' => 'firstname', 'direction' => 'desc']);
        $this->assertSame(3, $page['total']);
        $this->assertSame(['Murad', 'Leyla', 'Arshad'], array_column($page['rows'], 'firstname'));

        $this->assertSame(['Leyla'], array_column($this->browser->rows(self::DB, 'people', ['search' => 'ganja'])['rows'], 'firstname'));
        $this->assertSame(['Murad'], array_column($this->browser->rows(self::DB, 'people', ['filters' => [['column' => 'city', 'operator' => 'null']]])['rows'], 'firstname'));
        $this->assertSame(['Arshad', 'Leyla'], array_column($this->browser->rows(self::DB, 'people', ['filters' => [['column' => 'passport', 'operator' => 'regex', 'value' => '^AZE[0-9]{7}$']]])['rows'], 'firstname'));
        $this->assertSame(0, $this->browser->rows(self::DB, 'people', ['search' => '%'])['total'], 'A % in the search is a plain character.');
    }

    public function test_a_global_search_finds_a_record_by_its_value_and_by_column_name(): void
    {
        $found = $this->browser->search('AZE1234567', ['mode' => 'data', 'match' => 'equals', 'databases' => [self::DB]]);
        $this->assertCount(1, $found['results']);
        $this->assertSame(['database' => self::DB, 'table' => 'people', 'keys' => ['id' => 1], 'found' => ['passport' => 'AZE1234567']], $found['results'][0]);

        $byColumn = $this->browser->search('arshad', ['mode' => 'data', 'column' => 'firstname', 'databases' => [self::DB]]);
        $this->assertSame(['firstname' => 'Arshad'], $byColumn['results'][0]['found']);

        $regex = $this->browser->search('^AZE7', ['mode' => 'data', 'match' => 'regex', 'databases' => [self::DB]]);
        $this->assertSame(['id' => 2], $regex['results'][0]['keys']);

        $columns = $this->browser->search('first', ['mode' => 'column', 'databases' => [self::DB]]);
        $this->assertSame('firstname', $columns['results'][0]['column']);

        $tables = $this->browser->search('^peo', ['mode' => 'table', 'match' => 'regex', 'databases' => [self::DB]]);
        $this->assertSame('people', $tables['results'][0]['table']);

        $this->expectException(RuntimeException::class);
        $this->browser->search('([', ['mode' => 'data', 'match' => 'regex', 'databases' => [self::DB]]);
    }

    public function test_every_change_is_logged_and_undone_only_for_its_row(): void
    {
        $keys = $this->browser->insertRow(self::DB, 'people', ['firstname' => 'Nigar', 'passport' => 'AZE0000001', 'city' => null]);
        $this->browser->updateRow(self::DB, 'people', ['id' => 1], ['city' => 'Sumqayit']);
        $this->browser->deleteRows(self::DB, 'people', [['id' => 2]]);

        $this->assertSame(['insert', 'update', 'delete'], DatabaseChange::query()->orderBy('id')->pluck('action')->all());
        $update = DatabaseChange::query()->where('action', 'update')->sole();
        $this->assertSame(['city'], $update->changedColumns());

        // Someone else adds a row meanwhile: undoing the delete puts back only row 2.
        $this->browser->connection()->insert('INSERT INTO `'.self::DB.'`.`people` (firstname) VALUES (?)', ['Other']);
        $this->browser->revert(DatabaseChange::query()->where('action', 'delete')->sole());
        $this->assertSame('Leyla', $this->browser->row(self::DB, 'people', ['id' => 2])['firstname']);
        $this->assertNotNull($this->browser->row(self::DB, 'people', ['firstname' => 'Other']));

        // The edited value was changed again since: undo stops, unless forced.
        $this->browser->connection()->update('UPDATE `'.self::DB.'`.`people` SET city = ? WHERE id = 1', ['Shaki']);

        try {
            $this->browser->revert($update);
            $this->fail('Undo should stop when the value changed since.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed again since: city', $exception->getMessage());
        }

        $this->browser->revert($update->fresh(), force: true);
        $this->assertSame('Baku', $this->browser->row(self::DB, 'people', ['id' => 1])['city']);

        $this->browser->revert(DatabaseChange::query()->where('action', 'insert')->orderBy('id')->first());
        $this->assertNull($this->browser->row(self::DB, 'people', $keys));
        $this->assertNotNull(DatabaseChange::query()->whereNotNull('reverts_id')->first());
    }

    public function test_rows_of_a_table_without_a_key_cannot_be_changed(): void
    {
        $this->expectExceptionMessage('no primary or unique key');
        $this->browser->insertRow(self::DB, 'logs', ['line' => 'x']);
    }

    public function test_triggers_are_created_changed_dropped_and_undone(): void
    {
        $this->browser->saveObject(self::DB, 'trigger', 'CREATE TRIGGER upper_name BEFORE INSERT ON people FOR EACH ROW SET NEW.firstname = UPPER(NEW.firstname);');
        $this->assertSame(['upper_name'], array_column($this->browser->objects(self::DB)['trigger'], 'name'));

        $this->browser->insertRow(self::DB, 'people', ['firstname' => 'kamran']);
        $this->assertSame(1, $this->browser->rows(self::DB, 'people', ['search' => 'KAMRAN'])['total']);

        $this->browser->saveObject(self::DB, 'trigger', 'CREATE TRIGGER upper_name BEFORE INSERT ON people FOR EACH ROW SET NEW.city = \'Baku\'', 'upper_name');
        $this->assertStringContainsString("NEW.city = 'Baku'", $this->browser->definition(self::DB, 'trigger', 'upper_name'));

        try {
            $this->browser->saveObject(self::DB, 'trigger', 'CREATE TRIGGER upper_name BEFORE INSERT ON nowhere FOR EACH ROW SET NEW.x = 1', 'upper_name');
            $this->fail('A broken trigger should be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('old one was put back', $exception->getMessage());
        }

        $this->assertStringContainsString("NEW.city = 'Baku'", $this->browser->definition(self::DB, 'trigger', 'upper_name'));

        $this->browser->dropObject(self::DB, 'trigger', 'upper_name');
        $this->assertSame([], $this->browser->objects(self::DB)['trigger']);

        $this->browser->revert(DatabaseChange::query()->where('action', 'drop')->sole());
        $this->assertSame(['upper_name'], array_column($this->browser->objects(self::DB)['trigger'], 'name'));
    }

    public function test_the_pages_show_databases_tables_rows_and_the_change_log(): void
    {
        Livewire::test(DatabaseIndex::class)->assertSee(self::DB);

        Livewire::test(DatabaseShow::class, ['database' => self::DB])
            ->assertSee('people')
            ->assertSee('logs');

        $page = Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])->assertSee('3 rows');
        $this->assertSame(['Arshad', 'Leyla', 'Murad'], array_column($page->instance()->fetchRows($this->browser, 0, 100)['rows'], 'firstname'));
        $this->assertSame(['Murad'], array_column($page->instance()->fetchRows($this->browser, 0, 1, 'firstname', 'desc')['rows'], 'firstname'), 'The grid asks for blocks, sorted on the server.');

        Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])
            ->set('search', 'Leyla')
            ->assertSee('1 row match')
            ->call('openRow', ['id' => 2])
            ->assertSet('rowOpen', true)
            ->call('editRow')
            ->set('form.city.value', 'Lankaran')
            ->call('saveRow')
            ->assertHasNoErrors();

        $this->assertSame('Lankaran', $this->browser->row(self::DB, 'people', ['id' => 2])['city']);

        Livewire::test(DatabaseIndex::class)
            ->set('tab', 'changes')
            ->assertSee('Edited a row in people');
    }

    public function test_search_objects_and_delete_dialogs_work_on_the_pages(): void
    {
        Livewire::test(DatabaseIndex::class)
            ->set('tab', 'search')
            ->set('query', 'AZE7654321')
            ->set('match', 'equals')
            ->call('runSearch')
            ->assertSee('Open record')
            ->assertSee('passport:');

        Livewire::test(DatabaseShow::class, ['database' => self::DB])
            ->set('tab', 'objects')
            ->call('newObject', 'trigger')
            ->set('editorSql', 'CREATE TRIGGER stamp BEFORE INSERT ON people FOR EACH ROW SET NEW.city = IFNULL(NEW.city, "Baku")')
            ->call('saveObject')
            ->assertHasNoErrors()
            ->assertSee('stamp')
            ->call('viewObject', 'trigger', 'stamp')
            ->assertSet('editorReadOnly', true)
            ->call('dropObject', 'trigger', 'stamp')
            ->set('tab', 'search')
            ->set('query', 'Ganja')
            ->call('runSearch')
            ->assertSee('Open record');

        Livewire::withQueryParams(['row' => json_encode(['id' => 3])])
            ->test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])
            ->assertSet('rowOpen', true)
            ->call('deleteOpenRow')
            ->assertSet('deleteOpen', true)
            ->set('saveCopy', false)
            ->call('confirmDelete')
            ->assertSet('deleteOpen', false);

        $this->assertNull($this->browser->row(self::DB, 'people', ['id' => 3]));

        Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'logs'])
            ->assertSee('rows are read-only here')
            ->set('tab', 'structure')
            ->assertSee('line');
    }

    public function test_table_headers_sort_and_row_counts_are_current(): void
    {
        $tables = collect($this->browser->tables(self::DB))->keyBy('name');
        $this->assertSame(3, $tables['people']['rows'], 'Row counts are read fresh, not from the day-long cache of MySQL.');

        Livewire::test(DatabaseShow::class, ['database' => self::DB])
            ->call('sortBy', 'rows')
            ->assertSet('direction', 'desc')
            ->assertSeeInOrder(['people', 'logs'])
            ->call('sortBy', 'rows')
            ->assertSet('direction', 'asc')
            ->assertSeeInOrder(['logs', 'people']);
    }

    public function test_sql_console_runs_selects_and_refuses_the_rest(): void
    {
        $sql = app(DatabaseSql::class);
        $ran = $sql->run(self::DB, "SELECT firstname FROM people WHERE firstname = 'Arshad'; SELECT * FROM mysql.user");

        $this->assertSame('That database is not available here.', $ran['error']);
        $this->assertSame(['Arshad'], array_column($ran['results'][0]['rows'], 'firstname'));

        $refused = $sql->run(self::DB, "SELECT * INTO OUTFILE '/tmp/laravelify-test.sql' FROM people");
        $this->assertSame('Writing a file on the server is not allowed.', $refused['error']);
        $this->assertSame([], $refused['results']);

        $before = DatabaseChange::query()->count();
        $wrote = $sql->run(self::DB, "INSERT INTO people (firstname, city) VALUES ('Nigar', 'Sheki')");
        $this->assertSame('1 row changed.', $wrote['results'][0]['message']);
        $this->assertSame($before, DatabaseChange::query()->count());

        $this->browser->connection()->unprepared('CREATE TABLE `'.self::DB.'`.`many` (n INT)');
        $this->browser->connection()->unprepared('INSERT INTO `'.self::DB.'`.`many` (n) VALUES '.implode(',', array_fill(0, 205, '(1)')));
        $capped = $sql->run(self::DB, 'SELECT n FROM many');
        $this->assertTrue($capped['results'][0]['truncated']);
        $this->assertCount(DatabaseSql::MAX_ROWS, $capped['results'][0]['rows']);
        $this->assertSame('many', $capped['results'][0]['table']);

        Livewire::test(DatabaseShow::class, ['database' => self::DB])
            ->set('tab', 'sql')
            ->assertSee('not added to the change log')
            ->call('runSql', "SELECT city FROM people WHERE firstname = 'Leyla'", 'SELECT firstname FROM people')
            ->assertSee('Ganja')
            ->call('downloadInserts', 0)
            ->assertFileDownloaded(self::DB.'-people-inserts.sql');
    }

    public function test_export_dialog_and_download_guards(): void
    {
        Livewire::test(DatabaseIndex::class)
            ->call('openExport', self::DB)
            ->assertSet('exportOpen', true)
            ->assertSet('exportTables', ['logs', 'people'])
            ->assertSee('Download .sql');

        Livewire::test(DatabaseShow::class, ['database' => self::DB])
            ->assertSee('SQL')
            ->call('openExport')
            ->assertSet('exportTables', ['logs', 'people']);

        Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])
            ->assertSee('Export SQL')
            ->call('openExport')
            ->assertSet('exportSingle', true)
            ->assertSeeHtml('name="single"');

        $this->post(route('database.export'), [
            'database' => 'mysql',
            'contents' => 'both',
            'tables' => ['user'],
        ])->assertNotFound();

        $this->post(route('database.export'), [
            'database' => self::DB,
            'contents' => 'sideways',
            'tables' => ['people'],
        ])->assertRedirect()->assertSessionHas('export_error', 'Choose structure, data, or both.');

        $this->post(route('database.export'), [
            'database' => self::DB,
            'contents' => 'structure',
            'tables' => [],
        ])->assertRedirect()->assertSessionHas('export_error', 'Choose at least one table.');

    }

    public function test_one_table_exports_as_plain_sql(): void
    {
        $response = $this->post(route('database.export'), [
            'database' => self::DB,
            'contents' => 'structure',
            'single' => '1',
            'tables' => ['people'],
        ]);

        if ($response->isRedirect()) {
            $response->assertSessionHas('export_error', 'This server cannot run mysqldump.');

            return;
        }

        $content = $response->getFile()->getContent();
        $response->assertOk();
        $this->assertStringContainsString('people', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('CREATE TABLE', $content);
    }

    public function test_foreign_keys_link_rows_both_ways_and_open_the_related_rows(): void
    {
        $db = $this->browser->connection();
        $db->unprepared('CREATE TABLE `'.self::DB.'`.`orders` (id INT AUTO_INCREMENT PRIMARY KEY, person_id INT NULL, status ENUM(\'new\',\'done\') NOT NULL DEFAULT \'new\', placed_at DATETIME NULL, CONSTRAINT orders_person FOREIGN KEY (person_id) REFERENCES people(id))');
        $db->insert('INSERT INTO `'.self::DB.'`.`orders` (person_id, placed_at) VALUES (1, ?), (2, ?), (1, NULL)', ['2025-02-23 17:59:37', '2025-03-01 08:00:00']);

        $orders = $this->browser->relations(self::DB, 'orders');
        $this->assertSame(['database' => self::DB, 'table' => 'people', 'column' => 'id', 'name' => 'orders_person'], $orders['out']['person_id']);
        $this->assertSame('orders', $this->browser->relations(self::DB, 'people')['in']['id'][0]['table']);

        // From a person: the orders that point at them only.
        $this->get(route('database.table', [self::DB, 'orders']).'?where[person_id]=1')->assertOk();
        Livewire::withQueryParams(['where' => ['person_id' => '1']])
            ->test(DatabaseTable::class, ['database' => self::DB, 'table' => 'orders'])
            ->assertSet('filters', [['column' => 'person_id', 'operator' => 'equals', 'value' => '1']])
            ->assertSee('2 rows match')
            ->assertViewHas('relations', fn (array $relations): bool => $relations['out']['person_id']['table'] === 'people');

        // The grid gets the relations both ways with each page of rows.
        Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])
            ->assertSeeHtml('data-db-grid-payload=')
            ->assertSeeHtml('&quot;in&quot;:[{&quot;database&quot;:&quot;'.self::DB.'&quot;,&quot;table&quot;:&quot;orders&quot;,&quot;column&quot;:&quot;person_id&quot;')
            ->call('sortTo', 'firstname', 'desc')
            ->assertSet('sort', 'firstname')
            ->assertSet('direction', 'desc');
    }

    public function test_the_row_form_uses_pickers_and_stores_mysql_formats(): void
    {
        $this->browser->connection()->unprepared('CREATE TABLE `'.self::DB.'`.`events` (id INT AUTO_INCREMENT PRIMARY KEY, kind ENUM(\'new\',\'it\'\'s done\') NOT NULL, starts_at DATETIME NULL, day DATE NULL, at TIME NULL)');
        $this->browser->connection()->insert('INSERT INTO `'.self::DB.'`.`events` (kind, starts_at, day, at) VALUES (?, ?, ?, ?)', ['new', '2025-02-23 17:59:37', '2025-02-23', '08:30:00']);
        $columns = collect($this->browser->columns(self::DB, 'events'))->keyBy('name');

        $this->assertSame(['new', "it's done"], DatabaseBrowser::enumOptions($columns['kind']['type']));
        $this->assertSame('2025-02-23T17:59:37', DatabaseBrowser::toInput($columns['starts_at'], '2025-02-23 17:59:37'));
        $this->assertSame('datetime', DatabaseBrowser::inputKind($columns['starts_at'], '2025-02-23T17:59:37'));
        $this->assertSame('plain', DatabaseBrowser::inputKind($columns['starts_at'], '0000-00-00 00:00:00'));
        $this->assertSame('2025-02-23 18:00:00', DatabaseBrowser::fromInput($columns['starts_at'], '2025-02-23T18:00'));
        $this->assertSame('09:15:00', DatabaseBrowser::fromInput($columns['at'], '09:15'));

        Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'events'])
            ->call('openRow', ['id' => 1])
            ->call('editRow')
            ->assertSet('form.starts_at.value', '2025-02-23T17:59:37')
            ->assertSeeHtml('type="datetime-local"')
            ->assertSeeHtml('type="date"')
            ->assertSeeHtml('type="time"')
            ->set('form.starts_at.value', '2025-03-10T09:30')
            ->set('form.kind.value', "it's done")
            ->call('saveRow')
            ->assertHasNoErrors();

        $row = $this->browser->row(self::DB, 'events', ['id' => 1]);
        $this->assertSame('2025-03-10 09:30:00', $row['starts_at']);
        $this->assertSame("it's done", $row['kind']);
        $this->assertSame('08:30:00', $row['at'], 'An unchanged field is not written.');
    }

    public function test_sql_runs_from_the_database_page_and_a_table_page(): void
    {
        Livewire::test(DatabaseIndex::class)
            ->set('tab', 'sql')
            ->call('runSql', '', 'SELECT 1 AS one')
            ->assertSet('sqlError', 'Choose a database to run it in.')
            ->set('sqlIn', self::DB)
            ->call('runSql', '', 'SELECT COUNT(*) AS people FROM people')
            ->assertSet('sqlError', null)
            ->assertSee('people');

        Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])
            ->set('tab', 'sql')
            ->assertSet('sql', 'SELECT * FROM `people` LIMIT 100;')
            ->call('runSql')
            ->assertSee('AZE1234567');
    }

    public function test_encrypted_columns_are_searched_filtered_and_shown_decrypted(): void
    {
        $key = 'site-secret';
        $iv = substr(hash('sha256', $key), 0, 16);
        $encrypted = openssl_encrypt('AZE1234567', 'AES-256-CBC', $key, 0, $iv);
        $this->browser->connection()->unprepared('ALTER TABLE `'.self::DB.'`.`people` ADD secret_passport VARCHAR(200) NULL');
        $this->browser->connection()->update('UPDATE `'.self::DB.'`.`people` SET secret_passport = ? WHERE id = 1', [$encrypted]);

        $method = $this->encryptionMethod();

        Livewire::test(DatabaseIndex::class)
            ->set('tab', 'search')
            ->set('query', 'AZE1234567')
            ->set('match', 'equals')
            ->set('scope', [self::DB])
            ->set('searchCrypto', (string) $method->id)
            ->call('runSearch')
            ->assertSet('searchedFor', $encrypted)
            ->assertSee('secret_passport:');

        Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])
            ->call('addFilter')
            ->set('filters.0.column', 'secret_passport')
            ->set('filters.0.operator', 'equals')
            ->set('filters.0.crypto', (string) $method->id)
            ->set('filters.0.value', 'AZE1234567')
            ->assertSee('1 row match')
            ->call('openRow', ['id' => 1])
            ->set('decryptWith', (string) $method->id)
            ->call('decryptRow')
            ->assertSet('decrypted', ['secret_passport' => 'AZE1234567']);
    }

    /**
     * A method like a site's own (AES with a fixed IV, so equal texts encrypt alike), run locally.
     */
    private function encryptionMethod(): CryptoMethod
    {
        $this->mock(SiteCommand::class, function ($mock): void {
            $mock->shouldReceive('run')->andReturnUsing(function ($website, string $command) {
                $process = Process::fromShellCommandline((string) preg_replace('/^php[0-9.]*/', escapeshellarg(PHP_BINARY), $command));
                $process->run();

                return new CommandResult(true, false, [$command], $process->getOutput(), 0);
            });
        });

        return CryptoMethod::factory()->create([
            'website_id' => Website::factory()->create(['type' => 'laravel'])->id,
            'boot_laravel' => false,
            'encrypt_code' => "return openssl_encrypt(\$text, 'AES-256-CBC', 'site-secret', 0, substr(hash('sha256', 'site-secret'), 0, 16));",
            'decrypt_code' => "return openssl_decrypt(\$text, 'AES-256-CBC', 'site-secret', 0, substr(hash('sha256', 'site-secret'), 0, 16));",
        ]);
    }

    public function test_a_column_marked_encrypted_is_shown_filtered_searched_and_saved_decrypted(): void
    {
        $encrypt = fn (string $text): string => openssl_encrypt($text, 'AES-256-CBC', 'site-secret', 0, substr(hash('sha256', 'site-secret'), 0, 16));
        $db = $this->browser->connection();
        $db->unprepared('ALTER TABLE `'.self::DB.'`.`people` ADD secret_passport VARCHAR(200) NULL');
        $db->update('UPDATE `'.self::DB.'`.`people` SET secret_passport = ? WHERE id = 1', [$encrypt('AZE1234567')]);
        $db->update('UPDATE `'.self::DB.'`.`people` SET secret_passport = ? WHERE id = 2', [$encrypt('AZE7654321')]);
        $method = $this->encryptionMethod();

        $page = Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])
            ->call('setColumnEncryption', 'secret_passport', $method->id);
        $this->assertDatabaseHas('encrypted_columns', ['database' => self::DB, 'table' => 'people', 'column' => 'secret_passport', 'crypto_method_id' => $method->id]);
        $this->assertSame(['AZE1234567', 'AZE7654321', null], array_column($page->instance()->fetchRows($this->browser, 0, 100)['rows'], 'secret_passport'));

        $values = collect($page->instance()->columnValues($this->browser, 'secret_passport', '7654')['values']);
        $this->assertSame([['value' => $encrypt('AZE7654321'), 'count' => 1, 'label' => 'AZE7654321']], $values->all(), 'The value list shows decrypted labels and searches them.');

        // "Is exactly" and the search encrypt the text first; the column's own method is used.
        $page->call('setColumnFilter', 'secret_passport', ['mode' => 'condition', 'operator' => 'equals', 'value' => 'AZE7654321'])
            ->assertSee('1 row match')
            ->call('setColumnFilter', 'secret_passport', ['mode' => 'none'])
            ->set('search', 'AZE1234567')
            ->assertSee('1 row match')
            ->set('search', '');

        // Shown as stored on request.
        $page->call('toggleRawColumn', 'secret_passport');
        $this->assertSame($encrypt('AZE1234567'), $page->instance()->fetchRows($this->browser, 0, 1)['rows'][0]['secret_passport']);
        $page->call('toggleRawColumn', 'secret_passport');

        // The record and the form show it decrypted; a changed value is saved encrypted.
        $page->call('openRow', ['id' => 2])
            ->assertSet('decrypted', ['secret_passport' => 'AZE7654321'])
            ->call('editRow')
            ->assertSet('form.secret_passport.value', 'AZE7654321')
            ->set('form.secret_passport.value', 'AZE0000001')
            ->call('saveRow')
            ->assertHasNoErrors();
        $this->assertSame($encrypt('AZE0000001'), $db->selectOne('SELECT secret_passport AS v FROM `'.self::DB.'`.`people` WHERE id = 2')->v);

        // The search on the databases page finds it without choosing a method.
        Livewire::test(DatabaseIndex::class)
            ->set('tab', 'search')
            ->set('query', 'AZE0000001')
            ->set('scope', [self::DB])
            ->call('runSearch')
            ->assertSee('secret_passport:');

        $page->call('setColumnEncryption', 'secret_passport', '');
        $this->assertDatabaseMissing('encrypted_columns', ['column' => 'secret_passport']);
        $this->assertSame($encrypt('AZE1234567'), $page->instance()->fetchRows($this->browser, 0, 1)['rows'][0]['secret_passport']);
    }

    public function test_the_header_filter_lists_values_with_counts_and_filters_by_them(): void
    {
        $this->browser->connection()->insert('INSERT INTO `'.self::DB.'`.`people` (firstname, city) VALUES (?, ?)', ['Nigar', 'Baku']);

        $values = $this->browser->distinctValues(self::DB, 'people', 'city');
        $this->assertSame([['value' => 'Baku', 'count' => 2], ['value' => null, 'count' => 1], ['value' => 'Ganja', 'count' => 1]], $values['values']);
        $this->assertSame([['value' => 'Ganja', 'count' => 1]], $this->browser->distinctValues(self::DB, 'people', 'city', '', [], 'gan')['values']);

        $page = Livewire::test(DatabaseTable::class, ['database' => self::DB, 'table' => 'people'])
            ->call('setColumnFilter', 'city', ['mode' => 'values', 'values' => ['Ganja'], 'blanks' => true])
            ->assertSee('2 rows match')
            ->assertSee('(empty)');
        $this->assertSame(['Leyla', 'Murad'], array_column($page->instance()->fetchRows($this->browser, 0, 100)['rows'], 'firstname'));
        $this->assertSame(['Baku', 'Ganja'], array_column(array_filter($page->instance()->columnValues($this->browser, 'city')['values'], fn ($row) => $row['value'] !== null), 'value'), 'The list ignores the column’s own filter.');

        $page->call('setColumnFilter', 'city', ['mode' => 'condition', 'operator' => 'starts', 'value' => 'Ba'])
            ->assertSee('2 rows match')
            ->call('setColumnFilter', 'city', ['mode' => 'none'])
            ->assertSet('filters', []);
    }
}
