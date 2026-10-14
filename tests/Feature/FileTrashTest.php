<?php

namespace Tests\Feature;

use App\Livewire\Websites\Files;
use App\Models\TrashItem;
use App\Models\User;
use App\Models\Website;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class FileTrashTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-trash-'.uniqid();
        File::ensureDirectoryExists($this->root.'/docs');
        file_put_contents($this->root.'/docs/readme.md', 'first');
        file_put_contents($this->root.'/notes.txt', 'v1');

        $this->user = User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function website(): Website
    {
        return Website::factory()->create(['deploy_path' => $this->root]);
    }

    public function test_a_deleted_folder_comes_back_where_it_was(): void
    {
        $website = $this->website();
        $component = Livewire::test(Files::class, ['website' => $website])->call('deleteEntries', ['docs']);

        $this->assertDirectoryDoesNotExist($this->root.'/docs');
        $item = TrashItem::query()->sole();
        $this->assertTrue($item->is_dir);
        $this->assertSame('files:website:'.$website->id, $item->scope);
        $this->assertSame($this->user->id, $item->user_id);

        $component->call('openTrash')
            ->assertSee('docs')
            ->assertSee('from /')
            ->call('restoreTrash', $item->id)
            ->assertDispatched('toast', type: 'success', message: 'Restored to /docs.');

        $this->assertSame('first', file_get_contents($this->root.'/docs/readme.md'));
        $this->assertModelMissing($item);
        $this->assertDirectoryDoesNotExist($item->folder());
    }

    public function test_each_delete_of_the_same_path_is_a_version_to_choose_from(): void
    {
        $website = $this->website();
        $component = Livewire::test(Files::class, ['website' => $website]);

        foreach (['v1', 'v2', 'v3'] as $content) {
            file_put_contents($this->root.'/notes.txt', $content);
            $component->call('refreshList')->call('deleteEntries', ['notes.txt']);
        }

        $versions = TrashItem::query()->orderBy('id')->get();
        $this->assertCount(3, $versions);

        $component->call('openTrash')->assertSee('3 versions')->assertSee('Version 1');

        // Bring back the oldest one.
        $component->call('restoreTrash', $versions[0]->id);
        $this->assertSame('v1', file_get_contents($this->root.'/notes.txt'));

        // The name is taken now, so the next one comes back next to it.
        $component->call('restoreTrash', $versions[2]->id);
        $this->assertSame('v3', file_get_contents($this->root.'/notes (restored).txt'));
        $this->assertSame(1, TrashItem::query()->count());
    }

    public function test_deleting_for_good_needs_the_authenticator_code(): void
    {
        $website = $this->website();
        $component = Livewire::test(Files::class, ['website' => $website])->call('deleteEntries', ['notes.txt', 'docs']);
        [$notes, $docs] = TrashItem::query()->orderBy('name', 'desc')->get()->all();

        $component->call('purgeTrash', $notes->id, '000000')->assertHasErrors('code');
        $this->assertModelExists($notes);
        $this->assertFileExists($notes->storedPath());

        $component->call('purgeTrash', $notes->id, app(TwoFactor::class)->currentCode($this->user))->assertHasNoErrors();
        $this->assertModelMissing($notes);
        $this->assertDirectoryDoesNotExist($notes->folder());

        $component->call('emptyTrash', 'nope')->assertHasErrors('code');
        $this->assertModelExists($docs);

        $component->call('emptyTrash', app(TwoFactor::class)->currentCode($this->user))->assertHasNoErrors();
        $this->assertSame(0, TrashItem::query()->count());
        $this->assertDirectoryDoesNotExist($docs->folder());
    }

    public function test_the_trash_of_one_site_is_not_shown_or_restored_in_another(): void
    {
        $website = $this->website();
        Livewire::test(Files::class, ['website' => $website])->call('deleteEntries', ['notes.txt']);
        $item = TrashItem::query()->sole();

        $other = Website::factory()->create(['deploy_path' => $this->root]);

        Livewire::test(Files::class, ['website' => $other])
            ->call('openTrash')
            ->assertSee('The trash is empty')
            ->call('restoreTrash', $item->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertModelExists($item);
    }
}
