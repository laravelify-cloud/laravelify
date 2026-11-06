<?php

namespace Tests\Feature;

use App\Livewire\Monitoring\Glitchtip;
use App\Livewire\Monitoring\Index as MonitoringIndex;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MonitoringGlitchtipTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://127.0.0.1:8095/glitchtip/api/0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
        Setting::putValue('glitchtip.token', 'tok');
        Website::factory()->create(['name' => 'Shop', 'glitchtip_project' => 'shop', 'glitchtip_project_id' => 7]);
    }

    public function test_issues_show_in_the_panel_and_open_their_stack_trace(): void
    {
        Http::fake([
            self::API.'/organizations/laravelify/issues/7/events/latest/*' => Http::response([
                'eventID' => 'abc',
                'entries' => [
                    ['type' => 'exception', 'data' => ['values' => [[
                        'type' => 'ErrorException',
                        'value' => 'Undefined variable $cart',
                        'stacktrace' => ['frames' => [
                            ['filename' => 'vendor/laravel/framework/Router.php', 'function' => 'dispatch', 'lineNo' => 10, 'inApp' => false],
                            ['filename' => 'app/Http/Controllers/CheckoutController.php', 'function' => 'store', 'lineNo' => 42, 'inApp' => true, 'context' => [[41, '$total = 0;'], [42, 'return $cart->sum();']]],
                        ]],
                    ]]]],
                    ['type' => 'request', 'data' => ['method' => 'POST', 'url' => 'https://shop.test/checkout']],
                ],
                'tags' => [['key' => 'environment', 'value' => 'production'], ['key' => 'secret', 'value' => 'x']],
            ]),
            self::API.'/organizations/laravelify/issues/7/' => Http::response(['status' => 'resolved']),
            self::API.'/organizations/laravelify/issues/*' => Http::response([[
                'id' => '7', 'shortId' => 'SHOP-7', 'title' => 'ErrorException: Undefined variable $cart', 'culprit' => 'CheckoutController@store',
                'level' => 'error', 'status' => 'unresolved', 'count' => '1520', 'userCount' => 31,
                'firstSeen' => now()->subDay()->toIso8601String(), 'lastSeen' => now()->subMinutes(5)->toIso8601String(),
                'metadata' => ['type' => 'ErrorException'], 'project' => ['id' => '7', 'name' => 'shop'],
            ]]),
        ]);

        Livewire::withoutLazyLoading()->test(Glitchtip::class, ['section' => 'issues'])
            ->assertSee('ErrorException')
            ->assertSee('Undefined variable $cart')
            ->assertSee('1.5k')
            ->assertSee('Shop')
            ->call('toggleIssue', '7')
            ->assertSee('app/Http/Controllers/CheckoutController.php:42')
            ->assertSee('return $cart->sum();')
            ->assertSee('POST https://shop.test/checkout')
            ->assertSee('production')
            ->assertDontSee('secret')
            ->call('setStatus', '7', 'resolved')
            ->assertSet('open', null);

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/issues/7/') && $request['status'] === 'resolved');
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains(urldecode($request->url()), 'query=is:unresolved'));
    }

    public function test_performance_lists_transactions_by_speed(): void
    {
        Http::fake([self::API.'/organizations/laravelify/transaction-groups/*' => Http::response([[
            'id' => 3, 'project' => 7, 'transaction' => '/checkout', 'op' => 'http.server', 'method' => 'POST',
            'count' => 900, 'avgDuration' => 420.5, 'p50' => 300, 'p95' => 1850, 'errorRate' => 0.02, 'throughput' => 1.5,
            'lastSeen' => now()->toIso8601String(),
        ]])]);

        Livewire::withoutLazyLoading()->test(Glitchtip::class, ['section' => 'performance'])
            ->set('project', 7)
            ->set('sort', '-count')
            ->assertSee('/checkout')
            ->assertSee('421 ms')
            ->assertSee('1.85 s')
            ->assertSee('2%');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'transaction-groups') && str_contains($request->url(), 'project=7') && str_contains($request->url(), 'sort=-count'));
    }

    public function test_logs_can_be_filtered_by_level_and_text(): void
    {
        Http::fake([self::API.'/organizations/laravelify/logs/*' => Http::response([[
            'id' => '0190e3b8-0000-7000-8000-000000000001', 'timestamp' => now()->toIso8601String(), 'level' => 'error',
            'body' => 'Payment gateway timed out', 'service' => 'shop-api', 'environment' => 'production', 'data' => ['order' => 55], 'projectId' => 7,
        ]])]);

        Livewire::withoutLazyLoading()->test(Glitchtip::class, ['section' => 'logs'])
            ->set('level', 'error')
            ->set('query', 'gateway')
            ->assertSee('Payment gateway timed out')
            ->assertSee('shop-api');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/logs/') && str_contains($request->url(), 'level=error') && str_contains($request->url(), 'query=gateway'));
    }

    public function test_logs_come_a_page_at_a_time_with_totals_for_every_level(): void
    {
        $line = fn (int $n) => ['id' => sprintf('0190e3b8-0000-7000-8000-%012d', $n), 'timestamp' => now()->toIso8601String(), 'level' => 'info', 'body' => 'Line '.$n, 'projectId' => 7];
        Http::fake([
            self::API.'/organizations/laravelify/logs/stats/*' => Http::response(['intervals' => ['a', 'b'], 'series' => [['name' => 'info', 'data' => [30, 10]], ['name' => 'error', 'data' => [2, 1]]]]),
            self::API.'/organizations/laravelify/logs/?*cursor=page2*' => Http::response([$line(26)], 200, ['X-Hits' => '1', 'Link' => '<x>; rel="previous"; results="true"; cursor="page1", <y>; rel="next"; results="false"; cursor="page3"']),
            self::API.'/organizations/laravelify/logs/*' => Http::response(array_map($line, range(1, 25)), 200, ['X-Hits' => '26', 'Link' => '<x>; rel="previous"; results="false"; cursor="page0", <y>; rel="next"; results="true"; cursor="page2"']),
        ]);

        Livewire::withoutLazyLoading()->test(Glitchtip::class, ['section' => 'logs'])
            ->assertSee('1–25 of 26')
            ->assertSee('Line 25')
            ->assertSeeHtml('<b>43</b>')
            ->assertSeeHtml('<b>3</b>')
            ->call('goTo', 'page2', 1)
            ->assertSee('26–26 of 26') // GlitchTip says 1 here: only what is left.
            ->assertSee('Line 26')
            ->assertDontSee('Line 25')
            ->set('query', 'x')
            ->assertSet('cursor', null)
            ->assertSet('offset', 0);
    }

    public function test_without_glitchtip_the_tabs_explain_how_to_turn_it_on(): void
    {
        Setting::putValue('glitchtip.token', '');
        Http::fake();

        Livewire::withoutLazyLoading()->test(Glitchtip::class, ['section' => 'issues'])->assertSee('Error tracking is off');
        Livewire::withoutLazyLoading()->test(MonitoringIndex::class)->set('tab', 'logs')->assertSee('Logs')->assertOk();
        Http::assertNothingSent();
    }

    public function test_when_glitchtip_does_not_answer_the_page_still_works(): void
    {
        Http::fake([self::API.'/*' => Http::response('down', 502)]);

        Livewire::withoutLazyLoading()->test(Glitchtip::class, ['section' => 'issues'])->assertSee('GlitchTip did not answer');
    }
}
