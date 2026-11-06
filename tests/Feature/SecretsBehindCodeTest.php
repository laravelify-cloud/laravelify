<?php

namespace Tests\Feature;

use App\Livewire\Server\Edit as ServerEdit;
use App\Livewire\Websites\Show;
use App\Models\ServerService;
use App\Models\User;
use App\Models\Website;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Saved secrets reach the page only after the authenticator code. */
class SecretsBehindCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['laravelify.host_commands' => false]);
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
    }

    public function test_a_websites_env_is_shown_and_saved_only_after_the_code(): void
    {
        $website = Website::factory()->create(['env_content' => "APP_KEY=base64:topsecret\nDB_PASSWORD=hunter22"]);

        $page = Livewire::test(Show::class, ['website' => $website])
            ->assertSet('envUnlocked', false)
            ->assertSet('env_content', '')
            ->assertDontSee('topsecret')
            ->assertSee('Show and edit with your authenticator code')
            ->call('loadEnvironment')
            ->assertSet('envUnlocked', false)
            ->set('env_content', '')
            ->call('saveEnvironment');

        $this->assertStringContainsString('hunter22', (string) $website->fresh()->env_content, 'Saving before the code never wipes the .env.');

        session(['secrets_unlocked_until' => now()->addMinutes(5)->getTimestamp()]);
        $page->call('loadEnvironment')
            ->assertSet('envUnlocked', true)
            ->assertSet('env_content', "APP_KEY=base64:topsecret\nDB_PASSWORD=hunter22");
    }

    public function test_mysql_and_redis_passwords_stay_on_the_server_until_the_code(): void
    {
        $mysql = ServerService::query()->where('key', 'mysql')->firstOrFail();
        $mysql->update(['settings' => ['bind' => '127.0.0.1', 'port' => '3306', 'username' => 'root', 'password' => 'root-secret-1']]);

        $page = Livewire::test(ServerEdit::class, ['service' => $mysql])
            ->assertSet('password', '')
            ->assertDontSee('root-secret-1');
        $this->assertSame(['ok' => false, 'locked' => true], $page->instance()->revealSecret('service_password'));

        // Saving other settings without touching the password keeps it.
        $page->set('port', '3306')->call('save')->assertHasNoErrors();
        $this->assertSame('root-secret-1', $mysql->fresh()->settings['password']);

        session(['secrets_unlocked_until' => now()->addMinutes(5)->getTimestamp()]);
        $this->assertSame(['ok' => true, 'value' => 'root-secret-1'], $page->instance()->revealSecret('service_password'));
    }
}
