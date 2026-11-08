<?php

namespace Tests\Feature;

use App\Models\PanelTask;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DatabaseImportTest extends TestCase
{
    use RefreshDatabase;

    private string $upload;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->upload = str_repeat('a', 32);
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
    }

    protected function tearDown(): void
    {
        File::delete(glob(storage_path('app/db-imports/'.str_repeat('a', 32).'.*')) ?: []);

        parent::tearDown();
    }

    private function piece(string $content, int $offset, int $total): TestResponse
    {
        return $this->post(route('database.import.chunk', 'shop'), [
            'upload' => $this->upload, 'name' => 'shop.sql.gz', 'total' => $total, 'offset' => $offset,
            'chunk' => UploadedFile::fake()->createWithContent('piece', $content),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_dump_goes_up_in_pieces_then_imports_in_the_background_once(): void
    {
        $this->piece('CREATE TABLE a (id INT);', 0, 47)->assertOk()->assertJson(['received' => 24]);
        // The same piece again (a retry) is skipped; a piece out of order is refused.
        $this->piece('CREATE TABLE a (id INT);', 0, 47)->assertOk()->assertJson(['received' => 24]);
        $this->piece('x', 30, 47)->assertStatus(409);

        $this->postJson(route('database.import.start', 'shop'), ['upload' => $this->upload, 'name' => 'shop.sql.gz', 'total' => 47])
            ->assertStatus(422)->assertJson(['message' => 'The upload is not complete. Upload the file again.']);

        // Larger than it said: the upload is thrown away and starts over.
        $this->piece('CREATE TABLE b (id INT);', 24, 47)->assertStatus(422);
        $this->piece('CREATE TABLE a (id INT);', 0, 49)->assertOk();
        $this->piece('INSERT INTO a VALUES (1);', 24, 49)->assertOk()->assertJson(['received' => 49]);

        $this->postJson(route('database.import.start', 'shop'), ['upload' => $this->upload, 'name' => 'shop.sql.gz', 'total' => 49, 'reset' => true])->assertOk();

        $task = PanelTask::query()->sole();
        $this->assertSame(['database.sql-import', 'db-import:shop', 'shop', true], [$task->kind, $task->scope, $task->payload['database'], $task->payload['reset']]);
        $this->assertFileExists($task->payload['path']);

        // A second import into the same database waits for the first.
        $this->piece('SELECT 1;', 0, 9)->assertOk();
        $this->postJson(route('database.import.start', 'shop'), ['upload' => $this->upload, 'name' => 'shop.sql', 'total' => 9])->assertStatus(409);
    }

    public function test_only_dumps_into_databases_the_tool_may_change_are_taken(): void
    {
        $this->post(route('database.import.chunk', 'shop'), [
            'upload' => $this->upload, 'name' => 'shop.exe', 'total' => 3, 'offset' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('piece', 'abc'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->piece('abc', 0, 3)->assertOk();
        $this->postJson(route('database.import.start', 'mysql'), ['upload' => $this->upload, 'name' => 'x.sql', 'total' => 3])->assertNotFound();
    }
}
