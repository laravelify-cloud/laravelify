<?php

namespace Tests\Feature;

use App\Livewire\Settings\PanelAddress;
use App\Models\User;
use App\Services\HostWriter;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class PanelAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    public function test_a_domain_that_reaches_this_panel_becomes_its_address(): void
    {
        Http::fake(fn () => Http::response((string) Cache::get(PanelAddress::CHECK_KEY)));
        $this->mock(HostWriter::class, function (MockInterface $host): void {
            $host->shouldReceive('panelDomainAdd')->once()->with('panel.example.com');
            $host->shouldReceive('panelDomainCommit')->once()->with('panel.example.com');
            $host->shouldNotReceive('panelDomainRestore');
        });

        Livewire::test(PanelAddress::class)
            ->set('method', 'dns')
            ->set('domain', 'Panel.Example.com')
            ->call('move')
            ->assertRedirect('https://panel.example.com/settings/panel-address');
    }

    public function test_a_name_the_servers_dns_does_not_know_yet_is_found_through_cloudflare(): void
    {
        $tries = 0;
        Http::fake(function ($request) use (&$tries) {
            if (str_starts_with($request->url(), 'https://1.1.1.1/dns-query')) {
                return Http::response(['Answer' => [['type' => 5, 'data' => 'x.cdn.'], ['type' => 1, 'data' => '104.21.57.54']]]);
            }

            if (++$tries === 1) {
                throw new ConnectionException('cURL error 6: Could not resolve host: panel.example.com');
            }

            return Http::response((string) Cache::get(PanelAddress::CHECK_KEY));
        });
        $this->mock(HostWriter::class, function (MockInterface $host): void {
            $host->shouldReceive('panelDomainAdd')->once();
            $host->shouldReceive('panelDomainCommit')->once()->with('panel.example.com');
            $host->shouldNotReceive('panelDomainRestore');
        });
        $this->partialMockSleep();

        Livewire::test(PanelAddress::class)
            ->set('method', 'dns')
            ->set('domain', 'panel.example.com')
            ->call('move')
            ->assertRedirect('https://panel.example.com/settings/panel-address');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'name=panel.example.com'));
    }

    public function test_a_domain_this_server_cannot_reach_says_why(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: panel.example.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://panel.example.com/_laravelify/panel-check'));
        $this->mock(HostWriter::class, function (MockInterface $host): void {
            $host->shouldReceive('panelDomainAdd')->once();
            $host->shouldReceive('panelDomainRestore')->once();
            $host->shouldNotReceive('panelDomainCommit');
        });
        $this->partialMockSleep();

        Livewire::test(PanelAddress::class)
            ->set('method', 'dns')
            ->set('domain', 'panel.example.com')
            ->call('move')
            ->assertSet('problem', fn (?string $problem): bool => str_contains((string) $problem, '(Could not resolve host: panel.example.com)')
                && ! str_contains((string) $problem, 'curl.haxx.se'));
    }

    public function test_a_domain_answered_by_another_server_changes_nothing(): void
    {
        Http::fake(fn () => Http::response('someone else'));
        $this->mock(HostWriter::class, function (MockInterface $host): void {
            $host->shouldReceive('panelDomainAdd')->once();
            $host->shouldReceive('panelDomainRestore')->once();
            $host->shouldNotReceive('panelDomainCommit');
        });
        $this->partialMockSleep();

        Livewire::test(PanelAddress::class)
            ->set('method', 'dns')
            ->set('domain', 'panel.example.com')
            ->call('move')
            ->assertNoRedirect()
            ->assertSet('problem', fn (?string $problem): bool => str_contains((string) $problem, 'not from this panel'));
    }

    public function test_an_ip_or_url_is_not_a_domain(): void
    {
        $this->mock(HostWriter::class, fn (MockInterface $host) => $host->shouldNotReceive('panelDomainAdd'));

        Livewire::test(PanelAddress::class)
            ->set('method', 'dns')
            ->set('domain', 'https://1.2.3.4')
            ->call('move')
            ->assertHasErrors('domain');
    }

    private function partialMockSleep(): void
    {
        // The retries wait 5 seconds each in real use.
        Sleep::fake();
    }
}
