<?php

namespace Tests\Feature;

use App\Livewire\Security\Access;
use App\Models\ServerService;
use App\Models\User;
use App\Services\AccessGate;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\PhpMyAdminAccess;
use App\Services\ServerCatalog;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class AccessGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function code(): string
    {
        return app(TwoFactor::class)->currentCode($this->admin);
    }

    private function turnOn(string $target, string $method = AccessGate::METHOD_CODE, string $password = ''): void
    {
        app(AccessGate::class)->saveSettings($target, [
            'enabled' => true,
            'method' => $method,
            'username' => 'admin',
            'password_hash' => $password !== '' ? Hash::make($password) : '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function basic(string $username, string $password): array
    {
        return ['Authorization' => 'Basic '.base64_encode($username.':'.$password)];
    }

    private function phpMyAdminService(): ServerService
    {
        return ServerService::query()->firstOrCreate(
            ['kind' => 'phpmyadmin'],
            ['name' => 'phpMyAdmin', 'key' => 'phpmyadmin', 'domain' => 'phpmyadmin.example.com', 'config' => ServerCatalog::phpMyAdminNginx()],
        );
    }

    public function test_the_panel_asks_for_the_code_before_anything_else_and_then_remembers_it(): void
    {
        $this->turnOn('panel');

        $this->get('/login')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Laravelify panel: your 6-digit authenticator code", charset="UTF-8"');

        $this->withHeaders($this->basic($this->code(), ''))->get('/login')->assertOk()->assertSessionHas(AccessGate::SESSION_KEY);
        $this->flushHeaders()->withSession([AccessGate::SESSION_KEY => now()->addHour()->getTimestamp()])->get('/login')->assertOk();
    }

    public function test_webhooks_and_the_health_check_stay_open(): void
    {
        $this->turnOn('panel');

        $this->get('/up')->assertOk();
        $this->post('/hooks/github/no-such-site')->assertNotFound();
    }

    public function test_allowed_ips_skip_the_prompt_but_forged_headers_from_outside_do_not(): void
    {
        $this->turnOn('panel');
        app(AccessGate::class)->saveAllowlist(['203.0.113.0/24']);

        // Through the tunnel the request comes from this server, with the visitor's IP in a header.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['CF-Connecting-IP' => '203.0.113.9'])
            ->get('/login')->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
            ->withHeaders(['CF-Connecting-IP' => '203.0.113.9'])
            ->get('/login')->assertUnauthorized();
    }

    public function test_password_and_password_plus_code_methods(): void
    {
        $gate = app(AccessGate::class);
        $password = ['enabled' => true, 'method' => AccessGate::METHOD_PASSWORD, 'username' => 'admin', 'password_hash' => Hash::make('secret-pass')];
        $both = ['method' => AccessGate::METHOD_PASSWORD_CODE] + $password;

        $this->assertTrue($gate->accepts($password, 'admin', 'secret-pass'));
        $this->assertFalse($gate->accepts($password, 'other', 'secret-pass'));
        $this->assertFalse($gate->accepts($password, 'admin', 'wrong-pass'));

        $this->assertTrue($gate->accepts($both, 'admin', 'secret-pass'.$this->code()));
        $this->assertFalse($gate->accepts($both, 'admin', 'secret-pass'), 'The code is needed too.');
        $this->assertFalse($gate->accepts($both, 'admin', 'secret-pass000000'));
    }

    public function test_five_wrong_tries_block_the_ip_but_a_repeated_old_answer_does_not_count(): void
    {
        $this->turnOn('panel', AccessGate::METHOD_PASSWORD, 'secret-pass');

        $this->withHeaders($this->basic('admin', 'secret-pass'))->get('/login')->assertOk();

        // The pass ran out; the browser keeps sending the answer that worked, which still works.
        $this->flushSession();
        $this->withHeaders($this->basic('admin', 'secret-pass'))->get('/login')->assertOk();
        $this->flushSession();

        // The same wrong answer sent by several requests at once is one wrong try.
        foreach (range(1, 6) as $request) {
            $this->withHeaders($this->basic('admin', 'same-wrong'))->get('/login')->assertUnauthorized();
        }

        foreach (range(1, 4) as $try) {
            $this->withHeaders($this->basic('admin', 'wrong-'.$try))->get('/login')->assertUnauthorized();
        }

        $this->withHeaders($this->basic('admin', 'secret-pass'))->get('/login')->assertForbidden();
    }

    public function test_an_old_code_sent_again_is_asked_again_without_counting_as_wrong(): void
    {
        $this->turnOn('panel');
        $gate = app(AccessGate::class);
        cache()->put('access-gate:last:panel:127.0.0.1', hash('sha256', 'panel|111111|'), now()->addHour());

        foreach (range(1, 6) as $try) {
            $this->withHeaders($this->basic('111111', ''))->get('/login')->assertUnauthorized();
        }

        $this->withHeaders($this->basic($this->code(), ''))->get('/login')->assertOk();
        $this->assertTrue($gate->settings('panel')['enabled']);
    }

    public function test_phpmyadmin_check_answers_nginx_with_a_prompt_or_a_pass_cookie(): void
    {
        $this->turnOn('phpmyadmin');
        $this->get('/_access/phpmyadmin')->assertUnauthorized()->assertHeader('WWW-Authenticate');

        $response = $this->withHeaders($this->basic($this->code(), ''))->get('/_access/phpmyadmin')->assertNoContent();
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === AccessGate::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());

        $this->flushHeaders()->withUnencryptedCookie(AccessGate::COOKIE, $cookie->getValue())->get('/_access/phpmyadmin')->assertNoContent();

        $forPanel = Crypt::encryptString(json_encode(['target' => 'panel', 'until' => now()->addHour()->getTimestamp()]));
        $this->withUnencryptedCookie(AccessGate::COOKIE, $forPanel)->get('/_access/phpmyadmin')->assertUnauthorized();
    }

    public function test_phpmyadmin_nginx_gets_the_check_block_and_loses_it_again(): void
    {
        $access = app(PhpMyAdminAccess::class);
        $config = ServerCatalog::phpMyAdminNginx();

        $on = $access->withGate($config, true, '8.4', '/opt/laravelify');

        $this->assertStringContainsString('auth_request /__laravelify_access;', $on);
        $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME /opt/laravelify/public/index.php;', $on);
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.4-fpm.sock;', $on);
        $this->assertSame($on, $access->withGate($on, true, '8.4', '/opt/laravelify'), 'Adding it twice keeps one block.');
        $this->assertSame(1, substr_count($on, PhpMyAdminAccess::START));
        $this->assertSame(trim($config), trim($access->withGate($on, false, '8.4', '/opt/laravelify')));
    }

    public function test_saving_access_needs_the_code_and_writes_phpmyadmin_nginx(): void
    {
        $this->actingAs($this->admin);
        $service = $this->phpMyAdminService();
        $calls = new \ArrayObject;
        config(['laravelify.host_commands' => '1']);
        $this->mock(CommandRunner::class, function (MockInterface $mock) use ($calls) {
            $mock->shouldReceive('supportsHostCommands')->andReturnTrue();
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use ($calls) {
                $calls[] = end($command);

                return new CommandResult(true, false, $command, 'ok', 0);
            });
        });

        Livewire::test(Access::class)
            ->set('targets.panel.method', AccessGate::METHOD_PASSWORD)
            ->call('check')
            ->assertHasNoErrors('targets.panel.username');

        $page = Livewire::test(Access::class)
            ->set('targets.phpmyadmin.enabled', true)
            ->set('targets.phpmyadmin.method', AccessGate::METHOD_PASSWORD)
            ->set('allowlist', "203.0.113.7\nnot-an-ip")
            ->call('check')
            ->assertHasErrors(['targets.phpmyadmin.username', 'targets.phpmyadmin.password', 'allowlist']);

        $page->set('targets.phpmyadmin.username', 'dba')
            ->set('targets.phpmyadmin.password', 'secret-pass')
            ->set('allowlist', '203.0.113.7, 198.51.100.0/24')
            ->call('check')
            ->assertHasNoErrors()
            ->call('save', '000000')
            ->assertHasErrors('code');
        $this->assertFalse(app(AccessGate::class)->settings('phpmyadmin')['enabled']);

        $page->call('save', $this->code())->assertHasNoErrors()->assertReturned(true);

        $gate = app(AccessGate::class);
        $this->assertTrue($gate->settings('phpmyadmin')['enabled']);
        $this->assertTrue($gate->accepts($gate->settings('phpmyadmin'), 'dba', 'secret-pass'));
        $this->assertSame(['203.0.113.7', '198.51.100.0/24'], $gate->allowlist());
        $this->assertTrue(app(PhpMyAdminAccess::class)->hasGate($service->fresh()->config));
        $this->assertContains('phpmyadmin', $calls->getArrayCopy());
        $this->assertStringContainsString('auth_request', (string) file_get_contents(storage_path('app/rendered/services/phpmyadmin.conf')));
    }

    public function test_the_off_command_turns_the_prompt_off(): void
    {
        $this->turnOn('panel');
        $this->turnOn('phpmyadmin');
        $service = $this->phpMyAdminService();
        $service->update(['config' => app(PhpMyAdminAccess::class)->withGate($service->config, true, '8.4', base_path())]);

        $this->artisan('laravelify:access-off')->assertSuccessful();

        $this->assertFalse(app(AccessGate::class)->settings('panel')['enabled']);
        $this->assertFalse(app(AccessGate::class)->settings('phpmyadmin')['enabled']);
        $this->assertFalse(app(PhpMyAdminAccess::class)->hasGate($service->fresh()->config));
        $this->get('/login')->assertOk();
    }
}
