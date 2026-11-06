<?php

namespace Tests\Feature;

use App\Livewire\Database\Engine;
use App\Models\SqlHistory;
use App\Models\User;
use App\Models\Website;
use App\Services\DatabaseSql;
use App\Services\SqlChangeCapture;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

class SqlHistoryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        config(['laravelify.databases' => ['mysql', 'sqlite']]);

        $this->root = storage_path('framework/testing/history-'.uniqid());
        File::ensureDirectoryExists($this->root.'/.data');
        $pdo = new PDO('sqlite:'.$this->root.'/.data/database.sqlite');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, plan TEXT)');
        $pdo->exec("INSERT INTO users (name, plan) VALUES ('Ada', 'free'), ('Linus', 'free'), ('Grace', 'pro')");
        Website::factory()->create(['slug' => 'blog', 'db_engine' => 'sqlite', 'deploy_path' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_a_select_is_kept_with_how_many_rows_came_back(): void
    {
        $this->page()->call('runSql', '', 'SELECT * FROM users');

        $entry = SqlHistory::query()->sole();
        $this->assertSame(['sqlite', 'blog', 'read', 3, true], [$entry->engine, $entry->database, $entry->kind, $entry->rows, $entry->ok]);
    }

    public function test_an_update_keeps_the_rows_before_and_after(): void
    {
        $this->page()
            ->set('tab', 'history')
            ->call('runSql', '', "UPDATE users SET plan = 'pro' WHERE plan = 'free' AND name <> 'where'")
            ->call('toggleHistoryChanges', SqlHistory::query()->value('id'))
            ->assertSee('free')
            ->assertSee('Updated')
            ->assertSee('2 rows');

        $entry = SqlHistory::query()->sole();
        $change = $entry->changes[0];
        $this->assertSame(['write', 2], [$entry->kind, $entry->affected]);
        $this->assertSame(['users', 'update', ['id']], [$change['table'], $change['action'], $change['keys']]);
        $this->assertSame(['free', 'free'], array_column($change['before'], 'plan'));
        $this->assertSame(['pro', 'pro'], array_column($change['after'], 'plan'));
    }

    public function test_a_delete_keeps_the_deleted_rows(): void
    {
        $this->page()->call('runSql', '', 'DELETE FROM users WHERE id = 3');

        $change = SqlHistory::query()->sole()->changes[0];
        $this->assertSame('delete', $change['action']);
        $this->assertSame([['id' => 3, 'name' => 'Grace', 'plan' => 'pro']], $change['before']);
    }

    public function test_a_failed_run_is_kept_with_its_error(): void
    {
        $this->page()->call('runSql', '', 'SELECT nope FROM users');

        $entry = SqlHistory::query()->sole();
        $this->assertSame(['error', false], [$entry->kind, $entry->ok]);
        $this->assertStringContainsString('nope', (string) $entry->error);
    }

    public function test_run_again_puts_it_back_in_the_editor_and_the_history_is_per_database(): void
    {
        $this->page()->call('runSql', '', 'SELECT name FROM users');
        SqlHistory::record('sqlite', 'other', ['sql' => 'SELECT 1', 'kind' => 'read']);
        $id = SqlHistory::query()->where('database', 'blog')->value('id');

        $this->page()
            ->set('tab', 'history')
            ->assertSee('SELECT name FROM users')
            ->assertDontSee('SELECT 1')
            ->call('useHistory', $id, true)
            ->assertSet('tab', 'sql')
            ->assertSet('sql', 'SELECT name FROM users')
            ->assertSet('sqlResult.columns', ['name'])
            ->call('clearHistory');

        $this->assertSame(['other'], SqlHistory::query()->pluck('database')->all());
    }

    public function test_the_capture_finds_the_table_and_where_outside_quotes_and_brackets(): void
    {
        $capture = new SqlChangeCapture;
        $bare = fn (string $sql): string => app(DatabaseSql::class)->bare($sql);
        $sql = "UPDATE `orders` SET note = 'where x' WHERE id IN (SELECT id FROM t WHERE a = 1) ORDER BY id LIMIT 5";

        $this->assertSame(['action' => 'update', 'table' => '`orders`', 'where' => 'id IN (SELECT id FROM t WHERE a = 1)'], $capture->parse($sql, $bare($sql)));
        $this->assertSame(['action' => 'delete', 'table' => 'logs', 'where' => null], $capture->parse('DELETE FROM logs', $bare('DELETE FROM logs')));
        $this->assertNull($capture->parse('INSERT INTO logs VALUES (1)', $bare('INSERT INTO logs VALUES (1)')));
        $this->assertNull($capture->parse('UPDATE a JOIN b ON a.id = b.id SET a.x = 1', $bare('UPDATE a JOIN b ON a.id = b.id SET a.x = 1')));
    }

    private function page(): mixed
    {
        return Livewire::test(Engine::class, ['engine' => 'sqlite', 'database' => 'blog']);
    }
}
