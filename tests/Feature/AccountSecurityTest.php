<?php

namespace Tests\Feature;

use App\Livewire\Account\Security;
use App\Livewire\Setup\EnableTwoFactor;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** A key the user already uses in another app (32 base32 characters). */
    private const OWN_KEY = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => 'old-password',
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($this->user);
    }

    private function codeFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function currentCode(): string
    {
        return app(TwoFactor::class)->currentCode($this->user->fresh());
    }

    public function test_pasted_keys_are_cleaned_up_or_rejected(): void
    {
        $twoFactor = app(TwoFactor::class);

        $this->assertSame(self::OWN_KEY, $twoFactor->normalizeSecret(' jbsw y3dp-ehpk 3pxp jbsw y3dp ehpk 3pxp== '));
        $this->assertSame(self::OWN_KEY, $twoFactor->normalizeSecret('otpauth://totp/GitHub:me?secret='.self::OWN_KEY.'&issuer=GitHub'));
        $this->assertNull($twoFactor->normalizeSecret('JBSWY3DPEHPK3PX1'), 'Base32 has no 0, 1, 8, or 9.');
        $this->assertNull($twoFactor->normalizeSecret('JBSWY3DPEHPK'), 'Shorter than 16 characters.');
    }

    public function test_the_security_page_is_in_the_account_menu(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertSee(route('account.security'));
        $this->get(route('account.security'))->assertOk()->assertSee('Two-factor authentication is on');
    }

    public function test_password_changes_only_after_the_authenticator_code(): void
    {
        $page = Livewire::test(Security::class)
            ->set('currentPassword', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('checkPassword')
            ->assertHasErrors('currentPassword');

        $page->set('currentPassword', 'old-password')
            ->call('checkPassword')
            ->assertHasNoErrors()
            ->assertReturned(true)
            ->call('savePassword', '000000')
            ->assertHasErrors('code')
            ->assertReturned(false);
        $this->assertTrue(Hash::check('old-password', $this->user->fresh()->password));

        $page->call('clearCodeError')->assertHasNoErrors('code');

        $page->call('savePassword', $this->currentCode())->assertHasNoErrors()->assertReturned(true);
        $this->assertTrue(Hash::check('new-password', $this->user->fresh()->password));
    }

    public function test_own_key_replaces_the_key_after_its_code_and_the_current_code(): void
    {
        $oldKey = $this->user->two_factor_secret;

        $page = Livewire::test(Security::class)
            ->call('startOwnKey')
            ->set('ownSecret', 'not a key!')
            ->call('checkNewKey')
            ->assertHasErrors('ownSecret');

        $page->set('ownSecret', 'otpauth://totp/GitHub:me?secret='.self::OWN_KEY)
            ->set('newCode', '000000')
            ->call('checkNewKey')
            ->assertHasErrors('newCode')
            ->call('saveNewKey', $this->currentCode())
            ->assertReturned(false);
        $this->assertSame($oldKey, $this->user->fresh()->two_factor_secret, 'Nothing changes before the new key is checked.');

        $page->set('newCode', $this->codeFor(self::OWN_KEY))
            ->call('checkNewKey')
            ->assertReturned(true)
            ->call('saveNewKey', '000000')
            ->assertHasErrors('code');
        $this->assertSame($oldKey, $this->user->fresh()->two_factor_secret);

        $page->call('saveNewKey', $this->codeFor($oldKey))->assertReturned(true);
        $this->assertSame(self::OWN_KEY, $this->user->fresh()->two_factor_secret);
        $this->assertTrue(app(TwoFactor::class)->verify($this->user->fresh(), $this->codeFor(self::OWN_KEY)));
    }

    public function test_a_generated_key_is_saved_and_the_same_key_is_refused(): void
    {
        $page = Livewire::test(Security::class)->call('startNewKey');
        $newKey = $page->get('newSecret');
        $oldKey = $this->user->two_factor_secret;

        $page->set('newCode', $this->codeFor($newKey))
            ->call('checkNewKey')
            ->call('saveNewKey', $this->codeFor($oldKey))
            ->assertReturned(true);
        $this->assertSame($newKey, $this->user->fresh()->two_factor_secret);

        Livewire::test(Security::class)
            ->call('startOwnKey')
            ->set('ownSecret', $newKey)
            ->set('newCode', $this->codeFor($newKey))
            ->call('checkNewKey')
            ->assertHasErrors('ownSecret')
            ->assertReturned(false);
    }

    public function test_the_current_key_is_shown_only_after_unlocking(): void
    {
        $page = Livewire::test(Security::class);

        $page->call('revealSecret', 'two_factor_secret')->assertReturned(['ok' => false, 'locked' => true]);

        $page->call('unlockSecrets', $this->currentCode())
            ->call('revealSecret', 'two_factor_secret')
            ->assertReturned(['ok' => true, 'value' => $this->user->two_factor_secret]);
    }

    public function test_setup_accepts_a_key_the_admin_already_has(): void
    {
        $admin = User::factory()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $this->actingAs($admin);

        $screen = Livewire::test(EnableTwoFactor::class)
            ->call('useOwnKey')
            ->set('ownSecret', 'JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')
            ->set('code', '000000')
            ->call('confirm')
            ->assertHasErrors('code');
        $this->assertFalse($admin->fresh()->hasConfirmedTwoFactor());

        $screen->set('code', $this->codeFor(self::OWN_KEY))
            ->call('confirm')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(self::OWN_KEY, $admin->fresh()->two_factor_secret);
        $this->assertTrue($admin->fresh()->hasConfirmedTwoFactor());
    }

    public function test_setup_can_make_a_different_key(): void
    {
        $admin = User::factory()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $this->actingAs($admin);

        $screen = Livewire::test(EnableTwoFactor::class);
        $first = $admin->fresh()->two_factor_secret;

        $screen->call('useNewKey', true);
        $second = $admin->fresh()->two_factor_secret;

        $this->assertNotSame($first, $second);
        $screen->assertSet('secret', $second)
            ->set('code', $this->codeFor($second))
            ->call('confirm')
            ->assertRedirect(route('dashboard'));
    }
}
