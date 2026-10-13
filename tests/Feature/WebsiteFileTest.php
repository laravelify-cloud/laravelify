<?php

namespace Tests\Feature;

use App\Http\Middleware\PanelGate;
use App\Livewire\Websites\Files;
use App\Livewire\Websites\Terminal;
use App\Models\User;
use App\Models\Website;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteFileTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-fm-'.uniqid();
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_download_returns_a_file_from_the_site_folder(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'hello.txt', 'hello file');
        $website = Website::factory()->create(['deploy_path' => $this->root]);
        $this->withoutMiddleware(PanelGate::class);

        $this->get(route('websites.files.download', ['website' => $website, 'path' => 'hello.txt']))
            ->assertOk()
            ->assertDownload('hello.txt');
    }

    public function test_download_rejects_a_path_outside_the_site_folder(): void
    {
        $website = Website::factory()->create(['deploy_path' => $this->root]);
        $this->withoutMiddleware(PanelGate::class);

        $this->get(route('websites.files.download', ['website' => $website, 'path' => '../']))
            ->assertNotFound();
    }

    public function test_images_preview_inline_in_a_sandbox(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $website = Website::factory()->create(['deploy_path' => $this->root]);
        $this->withoutMiddleware(PanelGate::class);

        $response = $this->get(route('websites.files.download', ['website' => $website, 'path' => 'logo.svg', 'inline' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_inline_is_ignored_for_files_that_are_not_images(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'page.html', '<script>alert(1)</script>');
        $website = Website::factory()->create(['deploy_path' => $this->root]);
        $this->withoutMiddleware(PanelGate::class);

        $this->get(route('websites.files.download', ['website' => $website, 'path' => 'page.html', 'inline' => 1]))
            ->assertOk()
            ->assertDownload('page.html');
    }

    public function test_a_folder_downloads_as_a_zip(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is not installed.');
        }

        mkdir($this->root.DIRECTORY_SEPARATOR.'public');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.html', 'hi');
        $website = Website::factory()->create(['deploy_path' => $this->root]);
        $this->withoutMiddleware(PanelGate::class);

        $this->get(route('websites.files.download', ['website' => $website, 'path' => 'public']))
            ->assertOk()
            ->assertDownload('public.zip');
    }

    public function test_a_text_file_can_be_opened_edited_and_saved(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'notes.txt', 'first');
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->call('openFile', 'notes.txt')
            ->assertSet('openKind', 'text')
            ->assertSet('openContent', 'first')
            ->set('openContent', 'second')
            ->call('saveFile')
            ->assertDispatched('file-saved');

        $this->assertSame('second', file_get_contents($this->root.DIRECTORY_SEPARATOR.'notes.txt'));
    }

    public function test_saving_a_laravel_env_clears_the_cached_config(): void
    {
        mkdir($this->root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache', 0777, true);
        $cached = $this->root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php';
        file_put_contents($cached, '<?php return [];');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', 'DB_DATABASE=old');
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->call('openFile', '.env')
            ->set('openContent', 'DB_DATABASE=chat_app')
            ->call('saveFile')
            ->assertDispatched('file-saved');

        $this->assertFileDoesNotExist($cached);
        $this->assertSame('DB_DATABASE=chat_app', file_get_contents($this->root.DIRECTORY_SEPARATOR.'.env'));
    }

    public function test_binary_files_are_not_loaded_into_the_editor(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'blob.bin', "ab\0cd");
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->call('openFile', 'blob.bin')
            ->assertSet('openKind', 'binary')
            ->assertSet('openContent', '')
            ->call('saveFile');

        $this->assertSame("ab\0cd", file_get_contents($this->root.DIRECTORY_SEPARATOR.'blob.bin'));
    }

    public function test_files_and_folders_can_be_created_and_renamed(): void
    {
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->set('folderName', 'assets')
            ->call('createFolder')
            ->set('fileName', 'app.css')
            ->call('createFile')
            ->assertSet('openPath', 'app.css')
            ->call('renameEntry', 'app.css', 'site.css')
            ->call('renameEntry', 'site.css', '../escape.css');

        $this->assertDirectoryExists($this->root.DIRECTORY_SEPARATOR.'assets');
        $this->assertFileExists($this->root.DIRECTORY_SEPARATOR.'site.css');
        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'app.css');
        $this->assertFileDoesNotExist(dirname($this->root).DIRECTORY_SEPARATOR.'escape.css');
    }

    public function test_uploads_can_target_a_sub_folder(): void
    {
        mkdir($this->root.DIRECTORY_SEPARATOR.'storage');
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->set('uploads', [UploadedFile::fake()->createWithContent('backup.sql', 'select 1;')])
            ->call('storeUploads', 'storage')
            ->assertSet('uploads', []);

        $this->assertSame('select 1;', file_get_contents($this->root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'backup.sql'));
    }

    public function test_uploads_cannot_leave_the_site_folder(): void
    {
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->set('uploads', [UploadedFile::fake()->createWithContent('evil.php', '<?php')])
            ->call('storeUploads', '..');

        $this->assertFileDoesNotExist(dirname($this->root).DIRECTORY_SEPARATOR.'evil.php');
        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'evil.php');
    }

    public function test_deleting_requires_the_typed_name(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'old.log', 'x');
        $website = $this->siteForSignedInUser();

        $component = Livewire::test(Files::class, ['website' => $website])
            ->set('confirmName', 'wrong')
            ->call('deleteEntry', 'old.log')
            ->assertHasErrors('confirmName');

        $this->assertFileExists($this->root.DIRECTORY_SEPARATOR.'old.log');

        $component->set('confirmName', 'old.log')
            ->call('deleteEntry', 'old.log')
            ->assertDispatched('entry-deleted');

        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'old.log');
    }

    public function test_several_entries_are_deleted_after_typing_delete(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'a.txt', 'a');
        mkdir($this->root.DIRECTORY_SEPARATOR.'logs');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'b.log', 'b');
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->set('confirmName', 'delete')
            ->call('deleteEntries', ['a.txt', 'logs'])
            ->assertDispatched('entry-deleted');

        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'a.txt');
        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'logs');
    }

    public function test_entries_can_be_duplicated_copied_and_moved(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'app.css', 'body{}');
        mkdir($this->root.DIRECTORY_SEPARATOR.'public');
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->call('duplicateEntry', 'app.css')
            ->call('transferEntries', ['app.css'], 'public', 'copy')
            ->call('transferEntries', ['app copy.css'], 'public', 'move')
            ->call('transferEntries', ['public'], 'public', 'move');

        $this->assertFileExists($this->root.DIRECTORY_SEPARATOR.'app.css');
        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'app copy.css');
        $this->assertSame('body{}', file_get_contents($this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'app.css'));
        $this->assertFileExists($this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'app copy.css');
        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'public');
    }

    public function test_permissions_must_be_three_octal_digits(): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'run.sh', 'echo');
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->call('chmodEntry', 'run.sh', '999')
            ->assertNotDispatched('entry-chmodded')
            ->call('chmodEntry', 'run.sh', '755')
            ->assertDispatched('entry-chmodded');
    }

    public function test_entries_can_be_compressed_and_extracted(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is not installed.');
        }

        mkdir($this->root.DIRECTORY_SEPARATOR.'docs');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'readme.md', '# hi');
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->call('compressEntries', ['docs'])
            ->call('extractEntry', 'docs.zip');

        $this->assertFileExists($this->root.DIRECTORY_SEPARATOR.'docs.zip');
        $this->assertSame('# hi', file_get_contents($this->root.DIRECTORY_SEPARATOR.'docs copy'.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'readme.md'));
    }

    public function test_archives_with_unsafe_paths_are_not_extracted(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is not installed.');
        }

        $zip = new \ZipArchive;
        $zip->open($this->root.DIRECTORY_SEPARATOR.'evil.zip', \ZipArchive::CREATE);
        $zip->addFromString('../escaped.txt', 'nope');
        $zip->close();
        $website = $this->siteForSignedInUser();

        Livewire::test(Files::class, ['website' => $website])
            ->call('extractEntry', 'evil.zip');

        $this->assertFileDoesNotExist(dirname($this->root).DIRECTORY_SEPARATOR.'escaped.txt');
        $this->assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'evil');
    }

    public function test_the_folder_and_file_come_from_the_url(): void
    {
        mkdir($this->root.DIRECTORY_SEPARATOR.'config');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php', '<?php return [];');
        $website = $this->siteForSignedInUser();

        Livewire::withQueryParams(['path' => 'config', 'file' => 'config/app.php'])
            ->test(Files::class, ['website' => $website])
            ->assertSet('directory', 'config')
            ->assertSet('openPath', 'config/app.php')
            ->assertSet('openContent', '<?php return [];');
    }

    public function test_a_bad_folder_in_the_url_falls_back_to_the_site_root(): void
    {
        $website = $this->siteForSignedInUser();

        Livewire::withQueryParams(['path' => '../../etc'])
            ->test(Files::class, ['website' => $website])
            ->assertSet('directory', '')
            ->assertSet('listingError', '');
    }

    public function test_a_typed_path_opens_a_folder_or_a_file(): void
    {
        mkdir($this->root.DIRECTORY_SEPARATOR.'public');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'robots.txt', 'User-agent: *');
        $website = $this->siteForSignedInUser();
        $absolute = str_replace('\\', '/', realpath($this->root)).'/public';

        Livewire::test(Files::class, ['website' => $website])
            ->call('goToPath', '/public/')
            ->assertReturned(true)
            ->assertSet('directory', 'public')
            ->call('goToPath', '/')
            ->assertSet('directory', '')
            ->call('goToPath', $absolute.'/robots.txt')
            ->assertReturned(true)
            ->assertSet('directory', 'public')
            ->assertSet('openPath', 'public/robots.txt')
            ->call('goToPath', '/missing/../../outside')
            ->assertReturned(false)
            ->assertSet('directory', 'public');
    }

    public function test_the_global_file_manager_browses_the_configured_root(): void
    {
        config(['laravelify.files_root' => $this->root]);
        mkdir($this->root.DIRECTORY_SEPARATOR.'site-a');
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'site-a'.DIRECTORY_SEPARATOR.'.env', 'APP_NAME=A');
        $this->siteForSignedInUser();

        $this->get(route('files.index'))->assertOk()->assertSee('File manager');

        Livewire::test(Files::class)
            ->assertSet('listingError', '')
            ->call('openDirectory', 'site-a')
            ->call('openFile', 'site-a/.env')
            ->assertSet('openContent', 'APP_NAME=A')
            ->set('openContent', 'APP_NAME=B')
            ->call('saveFile')
            ->call('goToPath', '/../../')
            ->assertReturned(false);

        $this->assertSame('APP_NAME=B', file_get_contents($this->root.DIRECTORY_SEPARATOR.'site-a'.DIRECTORY_SEPARATOR.'.env'));
    }

    public function test_global_downloads_stay_inside_the_root_and_never_zip_the_root(): void
    {
        config(['laravelify.files_root' => $this->root]);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'notes.txt', 'hi');
        $this->withoutMiddleware(PanelGate::class);

        $this->get(route('files.download', ['path' => 'notes.txt']))->assertOk()->assertDownload('notes.txt');
        $this->get(route('files.download', ['path' => '../']))->assertNotFound();
        $this->get(route('files.download', ['path' => '']))->assertNotFound();
    }

    public function test_the_global_terminal_runs_in_a_folder_of_the_sites_root(): void
    {
        config(['laravelify.sites_root' => $this->root, 'laravelify.host_commands' => false]);
        mkdir($this->root.DIRECTORY_SEPARATOR.'site-a');
        $this->siteForSignedInUser();

        $this->get(route('terminal.index'))->assertOk()->assertSee('Terminal');

        Livewire::withQueryParams(['dir' => 'site-a'])
            ->test(Terminal::class)
            ->assertSet('directory', 'site-a')
            ->assertSet('workingDirectory', realpath($this->root.DIRECTORY_SEPARATOR.'site-a'))
            ->set('directory', '../outside')
            ->assertSet('directory', '')
            ->assertSet('workingDirectory', realpath($this->root))
            ->set('command', 'ls')
            ->call('run')
            ->assertSet('exitCode', 1);
    }

    public function test_the_terminal_suggests_commands_for_the_site_type(): void
    {
        config(['laravelify.host_commands' => false]);
        $website = $this->siteForSignedInUser();
        $website->update(['type' => 'laravel']);

        Livewire::test(Terminal::class, ['website' => $website])
            ->assertViewHas('suggestions', fn (array $suggestions): bool => collect($suggestions)->contains('command', 'php artisan optimize:clear'))
            ->call('artisanCommands')
            ->assertReturned([]);

        $website->update(['type' => 'nextjs']);

        Livewire::test(Terminal::class, ['website' => $website->fresh()])
            ->assertViewHas('suggestions', fn (array $suggestions): bool => collect($suggestions)->contains('command', 'npm run build')
                && ! collect($suggestions)->contains('command', 'php artisan optimize:clear'));
    }

    private function siteForSignedInUser(): Website
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));

        return Website::factory()->create(['deploy_path' => $this->root]);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$name;
            is_dir($full) && ! is_link($full) ? $this->removeDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
