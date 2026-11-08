<?php

namespace Tests\Feature;

use App\Livewire\Auth\CloudLogin;
use App\Models\Setting;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CloudLoginTest extends TestCase
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
        Setting::putValue('cloud_api', 'https://app.laravelify.cloud/api/panel');
        Setting::putValue('cloud_token', 'panel-token');
    }

    public function test_a_code_cloud_vouches_for_still_needs_the_authenticator_code(): void
    {
        Http::fake(['app.laravelify.cloud/*' => Http::response(['ok' => true])]);
        $code = str_repeat('a', 64);

        $this->get('/cloud-login?code='.$code)->assertRedirect(route('cloud-login.confirm'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://app.laravelify.cloud/api/panel/login'
            && $request->hasHeader('Authorization', 'Bearer panel-token') && $request['code'] === $code);
        $this->assertGuest();

        Livewire::test(CloudLogin::class)->set('code', '000000')->call('confirm')->assertHasErrors('code');
        $this->assertGuest();

        $valid = app(TwoFactor::class)->currentCode($this->admin);
        Livewire::test(CloudLogin::class)->set('code', $valid)->call('confirm')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_a_code_cloud_refuses_or_a_panel_not_linked_signs_nobody_in(): void
    {
        Http::fake(['app.laravelify.cloud/*' => Http::response(['ok' => false, 'message' => 'This link expired or was already used.'], 403)]);

        $this->get('/cloud-login?code='.str_repeat('b', 64))->assertRedirect(route('login'))->assertSessionHas('status', 'This link expired or was already used.');
        $this->get(route('cloud-login.confirm'))->assertRedirect(route('login'));

        Setting::putValue('cloud_token', null);
        $this->get('/cloud-login?code='.str_repeat('c', 64))->assertRedirect(route('login'));
        Http::assertSentCount(1);
        $this->assertGuest();
    }
}
