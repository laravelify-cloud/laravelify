<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Setup\CreateAdmin;
use App\Models\GithubApp;
use App\Models\User;
use App\Services\GithubWebhook;
use App\Services\TwoFactor;
use App\Support\GitInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_a_wrong_authenticator_code_never_signs_in(): void
    {
        $this->admin();

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('code', '000000')
            ->call('authenticate')
            ->assertHasErrors(['code']);

        $this->assertGuest();
    }

    public function test_guessing_passwords_or_codes_is_stopped_after_five_tries(): void
    {
        $this->admin();

        foreach (range(1, 5) as $try) {
            Livewire::test(Login::class)
                ->set('email', 'admin@example.com')
                ->set('password', 'wrong-'.$try)
                ->call('authenticate')
                ->assertHasErrors(['email']);
        }

        $code = app(TwoFactor::class);
        $user = User::query()->sole();

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('code', (new Google2FA)->getCurrentOtp($user->two_factor_secret))
            ->call('authenticate')
            ->assertHasErrors(['email'])
            ->assertSee('Too many attempts');

        $this->assertGuest();
        $this->assertTrue($code->verify($user, (new Google2FA)->getCurrentOtp($user->two_factor_secret)));
    }

    public function test_the_right_password_and_code_sign_in(): void
    {
        $user = $this->admin();

        Livewire::test(Login::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('code', (new Google2FA)->getCurrentOtp($user->two_factor_secret))
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_panel_pages_send_browser_protections(): void
    {
        $this->get(route('login'))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_git_values_that_would_be_options_are_refused(): void
    {
        $this->assertTrue(GitInput::validRepository('https://github.com/owner/repo.git'));
        $this->assertTrue(GitInput::validRepository('git@github.com:owner/repo.git'));
        $this->assertTrue(GitInput::validRepository('owner/repo'));
        $this->assertTrue(GitInput::validRepository('/opt/laravelify/storage/app/examples/static'));
        $this->assertFalse(GitInput::validRepository('--upload-pack=touch /tmp/pwned'));
        $this->assertFalse(GitInput::validRepository("https://x.com/a.git\n--config"));

        $this->assertTrue(GitInput::validBranch('main'));
        $this->assertTrue(GitInput::validBranch('feature/new-ui'));
        $this->assertFalse(GitInput::validBranch('--orphan'));
        $this->assertFalse(GitInput::validBranch('a..b'));
        $this->assertFalse(GitInput::validBranch('main.lock'));
    }

    public function test_a_webhook_without_a_secret_is_never_valid(): void
    {
        $body = '{"ref":"refs/heads/main"}';
        $webhook = app(GithubWebhook::class);

        $this->assertFalse($webhook->valid('', $body, 'sha256='.hash_hmac('sha256', $body, '')));
        $this->assertTrue($webhook->valid('secret', $body, 'sha256='.hash_hmac('sha256', $body, 'secret')));
    }

    public function test_the_github_app_install_link_needs_the_signed_in_admin(): void
    {
        $app = GithubApp::query()->create(['name' => 'app', 'slug' => 'app', 'html_url' => 'https://github.com', 'api_url' => 'https://api.github.com', 'git_user' => 'git', 'git_port' => 22]);

        $this->get(route('hooks.github-app.install', ['githubApp' => $app, 'installation_id' => '999']))
            ->assertRedirect(route('login'));

        $this->assertNull($app->fresh()->installation_id);
    }

    public function test_creating_the_superadmin_needs_the_installer_token(): void
    {
        config(['laravelify.setup_token' => 'install-secret-token']);

        Livewire::test(CreateAdmin::class)
            ->assertSet('allowed', false)
            ->assertSee('Open the setup link from the installer')
            ->set('allowed', true)
            ->set('name', 'Intruder')
            ->set('email', 'x@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertSame(0, User::query()->count());

        Livewire::withQueryParams(['token' => 'install-secret-token'])
            ->test(CreateAdmin::class)
            ->assertSet('allowed', true)
            ->set('name', 'Owner')
            ->set('email', 'owner@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, User::query()->count());
    }

    public function test_the_setup_form_fields_are_inside_the_livewire_component(): void
    {
        config(['laravelify.setup_token' => 'install-secret-token']);

        $html = $this->get('/setup?token=install-secret-token')->assertOk()->getContent();

        $marker = strpos($html, 'wire:id=');
        $this->assertNotFalse($marker);
        $tagStart = strrpos(substr($html, 0, $marker), '<');
        preg_match('/<([a-zA-Z0-9]+)/', substr($html, $tagStart), $matches);
        $tag = $matches[1];
        $cursor = strpos($html, '>', $tagStart) + 1;
        $depth = 1;

        while ($depth > 0) {
            $nextOpen = stripos($html, '<'.$tag, $cursor);
            $nextClose = stripos($html, '</'.$tag, $cursor);
            $this->assertNotFalse($nextClose);

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $cursor = $nextOpen + strlen($tag) + 1;

                continue;
            }

            $depth--;
            $cursor = $nextClose + strlen($tag) + 3;
        }

        $inner = substr($html, $tagStart, $cursor - $tagStart);

        $this->assertStringContainsString('wire:model="name"', $inner);
        $this->assertStringContainsString('wire:model="password"', $inner);
        $this->assertStringContainsString('wire:submit="save"', $inner);
    }
}
