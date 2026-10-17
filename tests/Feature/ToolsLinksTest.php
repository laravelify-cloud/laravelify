<?php

namespace Tests\Feature;

use App\Livewire\Settings\Links;
use App\Livewire\Websites\Terminal;
use App\Models\ServerService;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\ProcessCommands;
use App\Services\ServerCatalog;
use App\Services\ServerProcesses;
use App\Services\TwoFactor;
use App\Support\SidebarLinks;
use App\Support\TerminalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ToolsLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['laravelify.host_commands' => false]);
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    public function test_pm2_and_supervisor_commands_run_through_the_panel(): void
    {
        $this->mock(ServerProcesses::class, function ($mock): void {
            $mock->shouldReceive('list')->andReturn([
                ['manager' => 'pm2', 'name' => 'betaexampleorg', 'pid' => '81889', 'status' => 'online', 'detail' => '0% cpu, 32.0 MB'],
                ['manager' => 'supervisor', 'name' => 'shop-queue', 'pid' => '12', 'status' => 'running', 'detail' => 'pid 12, uptime 1:00:00'],
            ]);
            $mock->shouldReceive('restart')->with('pm2', 'betaexampleorg')->once()->andReturn('[PM2] Applying action restartProcessId on app [betaexampleorg]');
            $mock->shouldReceive('logs')->with('supervisor', 'shop-queue')->once()->andReturn('job done');
        });
        $commands = app(ProcessCommands::class);

        $list = $commands->handle('pm2   list');
        $this->assertTrue($list->ok);
        $this->assertStringContainsString('betaexampleorg', $list->output);
        $this->assertStringNotContainsString('shop-queue', $list->output);

        $this->assertStringContainsString('shop-queue', $commands->handle('supervisorctl status')->output);
        $this->assertStringContainsString('restartProcessId', $commands->handle('pm2 restart betaexampleorg')->output);
        $this->assertSame('job done', $commands->handle('supervisorctl tail -f shop-queue')->output);

        $missing = $commands->handle('pm2 logs');
        $this->assertFalse($missing->ok);
        $this->assertStringContainsString('Add the process name', $missing->output);

        $this->assertNull($commands->handle('ls -la'));
        $this->assertNull($commands->handle('pm2 restart app && rm -rf /'));
    }

    public function test_the_terminal_menu_lists_this_servers_processes(): void
    {
        Website::factory()->create(['type' => 'nextjs', 'slug' => 'storefront', 'process_config' => '']);
        Website::factory()->create(['slug' => 'shop', 'process_config' => "[program:shop-queue]\nstdout_logfile=/x.log\n"]);

        $global = collect(TerminalCommands::for(null))->pluck('command');

        $this->assertTrue($global->contains('df -h'));
        $this->assertTrue($global->contains('pm2 list'));
        $this->assertTrue($global->contains('pm2 logs storefront'));
        $this->assertTrue($global->contains('supervisorctl restart shop-queue'));

        Livewire::test(Terminal::class)
            ->set('command', 'pm2 list')
            ->call('run')
            ->assertDispatched('terminal-text', command: 'pm2 list', text: 'No PM2 apps are running.', exit: 0);
    }

    public function test_phpmyadmin_opens_with_a_one_time_token_and_sets_up_sign_in(): void
    {
        // The sign-on script runs on its own and reads the real storage folder.
        $this->app->useStoragePath(base_path('storage'));

        $service = ServerService::query()->firstOrCreate(
            ['kind' => 'phpmyadmin'],
            ['name' => 'phpMyAdmin', 'key' => 'phpmyadmin', 'domain' => 'phpmyadmin.internal', 'config' => ServerCatalog::phpMyAdminNginx()],
        );
        $service->update(['domain' => 'phpmyadmin.internal']);

        $this->get(route('tools.phpmyadmin'))->assertRedirect(route('server.edit', $service));
        $this->assertFalse(SidebarLinks::phpMyAdminAvailable());

        $service->update(['domain' => 'phpmyadmin.example.com', 'config' => ServerCatalog::phpMyAdminNginx()]);
        $this->assertTrue(SidebarLinks::phpMyAdminAvailable());

        $response = $this->get(route('tools.phpmyadmin'));
        $location = (string) $response->headers->get('Location');

        $this->assertMatchesRegularExpression('#^https://phpmyadmin\.example\.com/laravelify-signon\.php\?token=[A-Za-z0-9]{64}$#', $location);
        $token = substr($location, strpos($location, 'token=') + 6);
        $file = storage_path('app/pma-sso/'.hash('sha256', $token).'.json');
        $this->assertFileExists($file);
        $this->assertStringNotContainsString($token, (string) file_get_contents($file));
        $this->assertSame('1', Setting::getValue('phpmyadmin_sso'));
        $this->assertStringContainsString('location = /laravelify-signon.php', $service->fresh()->config);
        $this->assertStringContainsString(base_path('scripts/phpmyadmin-signon.php'), $service->fresh()->config);

        try {
            $first = $this->runSignon($token);
            $this->assertStringNotContainsString('expired', $first);
            $this->assertFileDoesNotExist($file, 'A token works once.');
            $this->assertStringContainsString('expired', $this->runSignon($token));
            $this->assertStringContainsString('Invalid sign-in link', $this->runSignon('bad;token'));
        } finally {
            @unlink($file);
        }
    }

    public function test_sidebar_links_are_saved_and_unsafe_addresses_rejected(): void
    {
        Livewire::test(Links::class)
            ->assertSet('links', SidebarLinks::DEFAULTS)
            ->set('links', [['label' => 'Bad', 'url' => 'javascript:alert(1)']])
            ->call('save')
            ->assertHasErrors('links.0.url')
            ->set('links', [['label' => 'Grafana', 'url' => 'https://grafana.example.com'], ['label' => '', 'url' => 'https://']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([['label' => 'Grafana', 'url' => 'https://grafana.example.com']], SidebarLinks::custom());
        $this->get(route('dashboard'))->assertSee('Grafana')->assertSee('https://grafana.example.com', false);
    }

    private function runSignon(string $token): string
    {
        $sessions = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-pma-sessions';
        File::ensureDirectoryExists($sessions);
        $code = '$_GET = ["token" => '.var_export($token, true).']; include '.var_export(base_path('scripts/phpmyadmin-signon.php'), true).';';
        $process = new Process([PHP_BINARY, '-d', 'session.save_path='.$sessions, '-d', 'display_errors=0', '-r', $code]);
        $process->run();

        return $process->getOutput();
    }
}
