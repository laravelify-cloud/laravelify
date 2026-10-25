<?php

namespace Tests\Feature;

use App\Livewire\Settings\General;
use App\Livewire\Websites\Form;
use App\Mcp\Servers\LaravelifyServer;
use App\Mcp\Tools\CreateWebsite;
use App\Mcp\Tools\ListPorts;
use App\Mcp\Tools\UploadFile;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\PortAllocator;
use App\Services\ServerProcesses;
use App\Services\TwoFactor;
use App\Support\ServerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use PharData;
use Tests\TestCase;
use ZipArchive;

class PortsAndUploadsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $siteRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->admin = User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->siteRoot = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-upload-test-'.uniqid();
        File::ensureDirectoryExists($this->siteRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->siteRoot);

        parent::tearDown();
    }

    /**
     * @param  array<int, array{public: bool, processes: list<string>}>  $listening
     */
    private function listening(array $listening): void
    {
        $this->partialMock(ServerProcesses::class, function (MockInterface $mock) use ($listening) {
            $mock->shouldReceive('listening')->andReturn($listening);
        });
    }

    public function test_new_ports_skip_apps_still_listening_and_ports_of_other_websites(): void
    {
        $this->listening([3000 => ['public' => false, 'processes' => ['node']], 3001 => ['public' => false, 'processes' => ['node']]]);
        Website::factory()->create(['port' => 3002]);
        Website::factory()->create(['http_port' => 3003, 'port' => null]);

        $ports = app(PortAllocator::class);

        $this->assertSame(3004, $ports->next(3000));
        $this->assertSame(3005, $ports->next(3000), 'A port handed out in this request is not given twice.');
        $this->assertSame(8081, $ports->next(8081));
        $this->assertSame(8001, $ports->next(8000), '8000 is kept for the panel.');
    }

    public function test_a_chosen_port_that_is_in_use_is_refused_with_who_holds_it(): void
    {
        $this->listening([8090 => ['public' => true, 'processes' => ['old-app']]]);
        $group = Group::factory()->create(['name' => 'Acme']);

        LaravelifyServer::actingAs($this->admin)->tool(CreateWebsite::class, [
            'group' => (string) $group->id, 'name' => 'Old', 'type' => 'express', 'repository' => 'acme/old', 'http_port' => 8090,
        ])->assertHasErrors(['Port 8090 is used by old-app (listening now)']);

        LaravelifyServer::actingAs($this->admin)->tool(CreateWebsite::class, [
            'group' => (string) $group->id, 'name' => 'Api', 'type' => 'express', 'repository' => 'acme/api',
        ])->assertOk();

        $this->assertNotSame(8090, Website::query()->sole()->http_port);
    }

    public function test_list_ports_shows_who_holds_each_port(): void
    {
        $this->listening([3000 => ['public' => false, 'processes' => ['node']]]);

        LaravelifyServer::actingAs($this->admin)->tool(ListPorts::class)
            ->assertOk()
            ->assertSee('"used_by":"node"')
            ->assertSee('next_free_node_port');
    }

    public function test_upload_file_puts_a_private_file_in_new_folders(): void
    {
        Website::factory()->create(['slug' => 'api', 'deploy_path' => $this->siteRoot]);

        LaravelifyServer::actingAs($this->admin)->tool(UploadFile::class, [
            'website' => 'api',
            'path' => 'storage/app/private/firebase.json',
            'content_base64' => base64_encode('{"type":"service_account"}'),
        ])->assertOk()->assertSee('storage/app/private/firebase.json')->assertDontSee('service_account');

        $this->assertSame('{"type":"service_account"}', file_get_contents($this->siteRoot.'/storage/app/private/firebase.json'));

        LaravelifyServer::actingAs($this->admin)->tool(UploadFile::class, [
            'website' => 'api', 'path' => 'storage/app/private/firebase.json', 'content_base64' => base64_encode('new'), 'overwrite' => false,
        ])->assertHasErrors(['already exists']);
    }

    public function test_upload_file_refuses_paths_outside_the_site_folder(): void
    {
        Website::factory()->create(['slug' => 'api', 'deploy_path' => $this->siteRoot]);

        foreach (['../escape.txt', '/etc/passwd', 'storage/../../escape.txt', 'C:/Windows/x.txt'] as $path) {
            LaravelifyServer::actingAs($this->admin)->tool(UploadFile::class, [
                'website' => 'api', 'path' => $path, 'content_base64' => base64_encode('x'),
            ])->assertHasErrors(['Use a path inside the site folder']);
        }

        $this->assertFileDoesNotExist(dirname($this->siteRoot).'/escape.txt');
    }

    public function test_upload_file_unpacks_a_tgz_and_refuses_an_unsafe_zip(): void
    {
        Website::factory()->create(['slug' => 'api', 'deploy_path' => $this->siteRoot]);
        $work = $this->siteRoot.'-archives';
        File::ensureDirectoryExists($work);

        try {
            $tar = new PharData($work.'/private.tar');
            $tar->addFromString('firebase.json', '{"key":1}');
            $tar->addFromString('keys/apple.p8', 'p8');
            $tar->compress(\Phar::GZ);
            unset($tar);

            LaravelifyServer::actingAs($this->admin)->tool(UploadFile::class, [
                'website' => 'api', 'path' => 'storage/app/private', 'unpack' => true, 'filename' => 'private.tgz',
                'content_base64' => base64_encode((string) file_get_contents($work.'/private.tar.gz')),
            ])->assertOk()->assertSee('storage/app/private/keys/apple.p8');

            $this->assertSame('{"key":1}', file_get_contents($this->siteRoot.'/storage/app/private/firebase.json'));

            $zip = new ZipArchive;
            $zip->open($work.'/evil.zip', ZipArchive::CREATE);
            $zip->addFromString('../../evil.txt', 'x');
            $zip->close();

            LaravelifyServer::actingAs($this->admin)->tool(UploadFile::class, [
                'website' => 'api', 'path' => 'storage', 'unpack' => true, 'filename' => 'evil.zip',
                'content_base64' => base64_encode((string) file_get_contents($work.'/evil.zip')),
            ])->assertHasErrors(['Use a path inside the site folder']);
        } finally {
            File::deleteDirectory($work);
        }
    }

    public function test_a_site_on_a_port_links_to_the_server_ip_and_is_checked_locally(): void
    {
        config(['laravelify.public_ip' => '187.7.72.156']);
        $website = Website::factory()->create(['domain' => null, 'http_port' => 8090]);

        $this->assertSame('http://187.7.72.156:8090', $website->publicUrl());
        $this->assertSame('http://127.0.0.1:8090', $website->checkUrl());

        config(['laravelify.public_ip' => null]);
        $this->assertSame('http://127.0.0.1:8090', $website->publicUrl(), 'Without host commands nothing is looked up.');
    }

    public function test_the_server_address_set_in_general_settings_wins_for_port_links(): void
    {
        config(['laravelify.public_ip' => '187.7.72.156']);
        $this->actingAs($this->admin);
        $website = Website::factory()->create(['domain' => null, 'http_port' => 8090]);

        Livewire::test(General::class)
            ->assertSee('Server address')
            ->set('server_address', 'not a host!')
            ->call('saveServerAddress')
            ->assertHasErrors('server_address')
            ->set('server_address', 'http://172.28.1.5:8080/')
            ->call('saveServerAddress')
            ->assertHasNoErrors()
            ->assertSet('server_address', '172.28.1.5');

        $this->assertSame('172.28.1.5', Setting::getValue(ServerAddress::SETTING));
        $this->assertSame('http://172.28.1.5:8090', $website->publicUrl());
    }

    public function test_the_add_website_form_offers_the_used_ports_list(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));

        Livewire::test(Form::class, ['group' => Group::factory()->create()])
            ->assertSee('Used ports')
            ->call('portMap')
            ->assertOk();
    }
}
