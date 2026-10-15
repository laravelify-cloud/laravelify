<?php

namespace Tests\Feature;

use App\Livewire\Monitoring\Index as MonitoringIndex;
use App\Livewire\Monitoring\Server as MonitoringServer;
use App\Livewire\Websites\Monitoring;
use App\Models\Heartbeat;
use App\Models\RequestMinute;
use App\Models\ServerSample;
use App\Models\Setting;
use App\Models\SiteIssue;
use App\Models\SiteRequest;
use App\Models\UptimeCheck;
use App\Models\User;
use App\Models\Website;
use App\Services\HeartbeatMonitor;
use App\Services\IssueCollector;
use App\Services\LatencyHistogram;
use App\Services\MonitorAlerts;
use App\Services\RequestCollector;
use App\Services\ServerMonitor;
use App\Services\ServerStats;
use App\Services\SiteMetrics;
use App\Services\SiteSecurity;
use App\Services\ThreatDetector;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHfakeTokenForTests_abcdefghij';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-monitoring-'.uniqid();
        File::ensureDirectoryExists($this->root.'/metrics');
        File::ensureDirectoryExists($this->root.'/site/storage/logs');
        config(['laravelify.host_commands' => false, 'laravelify.metrics_log_dir' => $this->root.'/metrics']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_request_log_is_stored_once_with_threats_flagged_and_static_files_skipped(): void
    {
        $website = Website::factory()->create();
        $log = SiteSecurity::metricsLog($website);

        File::put($log, implode("\n", [
            $this->line('/orders/123?page=2', 200, 0.120),
            $this->line('/build/assets/app.js', 200, 0.002),
            $this->line('/missing.png', 404, 0.003),
            $this->line("/search?q=1'%20UNION%20SELECT%20password%20FROM%20users--", 500, 1.5, '198.51.100.9'),
            'not json',
        ])."\n".'{"t":"'.now()->timestamp.'","s":"200"');

        $collector = app(RequestCollector::class);

        $this->assertSame(3, $collector->collect($website));
        $this->assertSame(0, $collector->collect($website));

        $order = SiteRequest::query()->where('route', '/orders/{id}')->firstOrFail();
        $this->assertSame('/orders/123?page=2', $order->uri);
        $this->assertSame(120, $order->duration_ms);
        $this->assertSame('DE', $order->country);

        $attack = SiteRequest::query()->where('ip', '198.51.100.9')->firstOrFail();
        $this->assertStringContainsString('sql_injection', (string) $attack->threat);
        $this->assertSame('critical', ThreatDetector::level($attack->risk));

        $minute = RequestMinute::query()->sole();
        $this->assertSame([3, 1, 1], [$minute->requests, $minute->client_errors, $minute->server_errors]);

        // The half-written line is read once it is complete; a rotated (truncated) log starts over.
        File::append($log, ',"u":"/late","m":"GET","d":"0.010"}'."\n");
        $this->assertSame(1, $collector->collect($website));

        File::put($log, $this->line('/after-rotation', 200, 0.05)."\n");
        $this->assertSame(1, $collector->collect($website));
        $this->assertSame(5, SiteRequest::query()->count());
    }

    public function test_routes_group_ids_and_the_threat_detector_knows_common_attacks(): void
    {
        $this->assertSame('/orders/{id}/items/{uuid}', RequestCollector::route('/orders/42/items/9b2e1c7e-4a1f-4c8e-9d6f-0a1b2c3d4e5f'));
        $this->assertSame('/', RequestCollector::route('/'));

        $detector = new ThreatDetector;
        $this->assertNull($detector->inspect('/products?sort=price&page=2', 'Mozilla/5.0')['threat']);
        $this->assertSame('scanner', $detector->inspect('/.env')['threat']);
        $this->assertStringContainsString('path_traversal', (string) $detector->inspect('/download?file=..%2F..%2Fetc%2Fpasswd')['threat']);
        $this->assertStringContainsString('xss', (string) $detector->inspect('/?q=%3Cscript%3Ealert(1)%3C/script%3E')['threat']);
        $this->assertSame('attack_tool', $detector->inspect('/', 'sqlmap/1.7')['threat']);
    }

    public function test_p95_comes_from_the_merged_histograms(): void
    {
        $histogram = LatencyHistogram::empty();

        foreach (range(1, 95) as $index) {
            $histogram = LatencyHistogram::add($histogram, 40);
        }

        foreach (range(1, 5) as $index) {
            $histogram = LatencyHistogram::add($histogram, 2500);
        }

        $this->assertLessThanOrEqual(50, LatencyHistogram::percentile($histogram, 0.95, 2500));
        $this->assertGreaterThan(2000, LatencyHistogram::percentile(LatencyHistogram::merge($histogram, $histogram), 0.99, 2500));
        $this->assertNull(LatencyHistogram::percentile(LatencyHistogram::empty(), 0.95));
    }

    public function test_laravel_errors_become_grouped_issues_that_reopen_after_resolving(): void
    {
        $website = Website::factory()->create(['deploy_path' => $this->root.'/site']);
        $log = $this->root.'/site/storage/logs/laravel.log';
        $entry = fn (string $time): string => '['.$time.'] production.ERROR: Connection refused {"userId":1,"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 2002): SQLSTATE[HY000] [2002] Connection refused at '.$this->root.'/site/vendor/laravel/framework/src/Illuminate/Database/Connection.php:825)'."\n[stacktrace]\n#0 ".$this->root.'/site/app/Http/Controllers/HomeController.php(12): query()'."\n#1 {main}\n\"} \n";

        File::put($log, $entry('2026-10-12 10:00:00').'[2026-10-12 10:00:01] production.INFO: Just info'."\n".$entry('2026-10-12 10:00:05'));

        $collector = app(IssueCollector::class);
        $changes = $collector->collect($website);

        $this->assertCount(1, $changes);
        $this->assertSame('new', $changes[0]['kind']);

        $issue = SiteIssue::query()->sole();
        $this->assertSame('Illuminate\\Database\\QueryException', $issue->exception);
        $this->assertSame('QueryException', $issue->title());
        $this->assertSame('vendor/laravel/framework/src/Illuminate/Database/Connection.php', $issue->file);
        $this->assertSame(825, $issue->line);
        $this->assertSame(2, $issue->occurrences);
        $this->assertStringContainsString('app/Http/Controllers/HomeController.php(12)', (string) $issue->trace);

        $issue->update(['status' => 'resolved', 'resolved_at' => now()]);
        File::append($log, $entry('2026-10-12 11:00:00'));

        $this->assertSame('returned', $collector->collect($website)[0]['kind']);
        $this->assertSame('open', $issue->fresh()->status);
    }

    public function test_spikes_and_attacks_alert_once_per_cooldown(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Setting::putValue('telegram_enabled', '1');
        Setting::putValue('telegram_bot_token', self::TOKEN);
        Setting::putValue('telegram_chat_id', '-100123');
        Setting::putValue('telegram_events', json_encode(['error_spike', 'threat_detected']));

        $website = Website::factory()->create(['monitor_error_threshold' => 3]);
        RequestMinute::query()->create([
            'website_id' => $website->id, 'minute' => now()->startOfMinute(), 'requests' => 10, 'client_errors' => 0,
            'server_errors' => 4, 'duration_sum' => 1000, 'duration_max' => 200, 'histogram' => LatencyHistogram::empty(),
        ]);
        SiteRequest::factory()->count(6)->threat()->create(['website_id' => $website->id, 'ip' => '203.0.113.5', 'at' => now()]);

        $alerts = app(MonitorAlerts::class);
        $alerts->traffic($website);
        $alerts->traffic($website);
        $alerts->threats($website);
        $alerts->threats($website);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Error spike') && str_contains($request['text'], '4 of 10'));
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Attack detected') && str_contains($request['text'], '203.0.113.5'));
    }

    public function test_blocked_ips_and_the_request_log_are_written_into_the_nginx_snippets(): void
    {
        $website = Website::factory()->create(['blocked_ips' => "203.0.113.5\nnot-an-ip\n198.51.100.0/24\n10.0.0.0/2"]);
        $security = app(SiteSecurity::class);

        $this->assertSame(['203.0.113.5', '198.51.100.0/24'], $security->blockedIps($website));
        $this->assertTrue($security->active($website));
        $this->assertStringContainsString('geo $laravelify_client_ip $laravelify_blocked_', $security->httpConfig($website));
        $this->assertStringContainsString('198.51.100.0/24 1;', $security->httpConfig($website));
        $this->assertStringContainsString('access_log '.$this->root.'/metrics/'.$website->slug.'.metrics.log laravelify_metrics;', $security->serverConfig($website));
        $this->assertStringContainsString('return 403;', $security->serverConfig($website));
    }

    public function test_the_monitoring_tab_shows_stats_and_blocks_ips(): void
    {
        $this->signIn();
        $website = Website::factory()->create(['nginx_config' => "server {\n    listen 80;\n    server_name app.test;\n}\n"]);
        SiteRequest::factory()->create(['website_id' => $website->id, 'route' => '/checkout', 'uri' => '/checkout', 'at' => now()->subMinutes(5)]);
        SiteRequest::factory()->threat()->create(['website_id' => $website->id, 'ip' => '203.0.113.5', 'at' => now()->subMinutes(3)]);
        RequestMinute::query()->create([
            'website_id' => $website->id, 'minute' => now()->subMinutes(5)->startOfMinute(), 'requests' => 2, 'client_errors' => 1,
            'server_errors' => 0, 'duration_sum' => 300, 'duration_max' => 200, 'histogram' => LatencyHistogram::add(LatencyHistogram::add(LatencyHistogram::empty(), 100), 200),
        ]);
        SiteIssue::factory()->create(['website_id' => $website->id]);

        $component = Livewire::withoutLazyLoading()->test(Monitoring::class, ['website' => $website])
            ->assertSee('Request tracking is not on')
            ->assertSee('/checkout')
            ->assertSee('QueryException');

        $component->call('setView', 'security')
            ->assertSee('203.0.113.5')
            ->call('blockIp', '127.0.0.1')
            ->call('blockIp', '203.0.113.5')
            ->assertDispatched('blocked-ips-changed');

        $this->assertSame('203.0.113.5', $website->fresh()->blocked_ips);
        $this->assertStringContainsString('laravelify-security/'.$website->slug.'.conf', (string) $website->fresh()->nginx_config);

        $component->call('setView', 'requests')->call('setFilter', 'threats')
            ->assertSee('UNION SELECT')
            ->assertViewHas('requests', fn ($requests): bool => $requests->total() === 1 && $requests->first()->ip === '203.0.113.5');

        $issue = SiteIssue::query()->sole();
        $component->call('markIssue', $issue->id, 'resolved');
        $this->assertSame('resolved', $issue->fresh()->status);

        $component->set('slow_ms', '50')->call('saveSettings')->assertHasErrors('slow_ms');
        $component->set('slow_ms', '1500')->set('retention_days', '14')->call('saveSettings')->assertHasNoErrors();
        $this->assertSame([1500, 14], [$website->fresh()->monitor_slow_ms, $website->fresh()->monitor_retention_days]);
    }

    public function test_the_overview_page_lists_every_website(): void
    {
        $this->signIn();
        $website = Website::factory()->create(['name' => 'Chat Meadow']);
        UptimeCheck::query()->create(['website_id' => $website->id, 'ok' => false, 'status' => 502, 'duration_ms' => 40, 'error' => 'HTTP 502', 'checked_at' => now()]);

        Livewire::test(MonitoringIndex::class)
            ->assertSee('Chat Meadow')
            ->assertSee('Down')
            ->assertSee('0 / 1');
    }

    public function test_uptime_checks_are_recorded_and_old_data_is_pruned(): void
    {
        $website = Website::factory()->create(['domain' => 'app.example.com', 'http_port' => null, 'cloudflare_tunnel_id' => null, 'monitor_retention_days' => 1]);
        $website->deployments()->create(['status' => 'success', 'trigger' => 'manual', 'log' => '']);
        Http::fake(['app.example.com*' => Http::response('ok', 200)]);

        app(ServerMonitor::class)->checkWebsites();

        $check = UptimeCheck::query()->sole();
        $this->assertTrue($check->ok);
        $this->assertSame(200, $check->status);

        SiteRequest::factory()->create(['website_id' => $website->id, 'at' => now()->subDays(3)]);
        SiteRequest::factory()->create(['website_id' => $website->id, 'at' => now()]);
        UptimeCheck::query()->create(['website_id' => $website->id, 'ok' => true, 'checked_at' => now()->subDays(40)]);

        $this->artisan('laravelify:prune-monitoring')->assertSuccessful();

        $this->assertSame(1, SiteRequest::query()->count());
        $this->assertSame(1, UptimeCheck::query()->count());
    }

    public function test_the_uptime_summary_survives_a_cache_that_serializes_like_the_real_one(): void
    {
        // The real cache serializes, and Laravel refuses to unserialize classes from it:
        // a model in the cached summary came back as an incomplete object on the monitoring tab.
        config(['cache.default' => 'array', 'cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');
        $website = Website::factory()->create();
        UptimeCheck::query()->create(['website_id' => $website->id, 'ok' => true, 'status' => 200, 'checked_at' => now()]);

        $metrics = app(SiteMetrics::class);
        $metrics->uptime($website, '24h');
        $summary = $metrics->uptime($website, '24h');

        $this->assertInstanceOf(UptimeCheck::class, $summary['latest']);
        $this->assertTrue($summary['latest']->ok);
        $this->assertSame(1, $summary['checks']);
    }

    public function test_heartbeats_alert_once_when_missed_and_once_when_they_report_again(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Setting::putValue('telegram_enabled', '1');
        Setting::putValue('telegram_bot_token', self::TOKEN);
        Setting::putValue('telegram_chat_id', '-100123');
        Setting::putValue('telegram_events', json_encode(['heartbeat_missed']));

        $heartbeat = Heartbeat::factory()->create(['name' => 'nightly backup', 'interval_minutes' => 5, 'last_ping_at' => now()->subMinutes(30)]);
        Heartbeat::factory()->create(['website_id' => $heartbeat->website_id, 'name' => 'healthy', 'last_ping_at' => now()]);

        $monitor = app(HeartbeatMonitor::class);
        $this->assertCount(1, $monitor->check());
        $this->assertSame([], $monitor->check());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'missed its heartbeat') && str_contains($request['text'], 'nightly backup'));

        $this->get(route('hooks.heartbeat', $heartbeat->token))->assertOk()->assertSee('OK');
        $this->assertNull($heartbeat->fresh()->late_since);
        $this->assertSame('ok', $heartbeat->fresh()->state());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'reported again'));

        $this->get(route('hooks.heartbeat', $heartbeat->token))->assertOk();
        Http::assertSentCount(2);

        $this->get('/hooks/heartbeat/'.str_repeat('a', 40))->assertNotFound();
    }

    public function test_heartbeats_can_be_added_and_removed_from_the_monitoring_tab(): void
    {
        $this->signIn();
        $website = Website::factory()->create();

        $component = Livewire::withoutLazyLoading()->test(Monitoring::class, ['website' => $website])
            ->call('setView', 'heartbeats')
            ->set('heartbeat_name', '')
            ->call('addHeartbeat')
            ->assertHasErrors('heartbeat_name')
            ->set('heartbeat_name', 'schedule:run')
            ->set('heartbeat_interval', '7')
            ->call('addHeartbeat')
            ->assertHasErrors('heartbeat_interval')
            ->set('heartbeat_interval', '5')
            ->call('addHeartbeat')
            ->assertHasNoErrors()
            ->assertSee('schedule:run')
            ->assertSee('Waiting');

        $heartbeat = $website->heartbeats()->sole();
        $this->assertSame(40, strlen($heartbeat->token));
        $component->assertSee('/hooks/heartbeat/'.$heartbeat->token)->call('deleteHeartbeat', $heartbeat->id);
        $this->assertSame(0, $website->heartbeats()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nginxConfigs(): array
    {
        return [
            'plain' => ["server {\n    listen 80;\n    server_name app.test;\n    root /var/www/app;\n}\n", 'server_name app.test;'],
            'trailing comment' => ["server {\n    listen 80;\n    server_name app.test www.app.test; # main\n}\n", '# main'],
            'windows line endings' => ["server {\r\n    listen 80;\r\n    server_name app.test;\r\n}\r\n", 'server_name app.test;'],
            'no server_name' => ["server {\n    listen 8081;\n    root /var/www/app;\n}\n", 'server {'],
        ];
    }

    #[DataProvider('nginxConfigs')]
    public function test_the_security_include_finds_a_place_in_any_nginx_config(string $config, string $after): void
    {
        $website = Website::factory()->create(['nginx_config' => $config]);
        $security = app(SiteSecurity::class);

        $this->assertTrue($security->ensureInclude($website));
        $this->assertTrue($security->hasInclude($website));
        $this->assertFalse($security->ensureInclude($website));
        $this->assertMatchesRegularExpression('/'.preg_quote($after, '/').'\r?\n\s*include \/etc\/nginx\/laravelify-security\//', $website->nginx_config);
    }

    public function test_proc_files_are_parsed_into_server_stats(): void
    {
        $this->assertSame(['total' => 1500, 'idle' => 900], ServerStats::parseCpu("cpu  100 0 200 800 100 50 50 200 0 0\ncpu0 1 1 1 1 1 1 1 1\n"));
        $this->assertNull(ServerStats::parseCpu('nothing'));

        $memory = ServerStats::parseMemory("MemTotal:       8000000 kB\nMemFree:         500000 kB\nMemAvailable:   2000000 kB\nSwapTotal:      1000000 kB\nSwapFree:        750000 kB\n");
        $this->assertSame(75.0, $memory['percent']);
        $this->assertSame(25.0, $memory['swap_percent']);

        $this->assertSame([0.52, 0.4, 0.3], ServerStats::parseLoad("0.52 0.40 0.30 1/200 12345\n"));
        $this->assertSame(93784, ServerStats::parseUptime("93784.55 180000.12\n"));
        $this->assertSame(2, ServerStats::parseCpuCount("processor : 0\nmodel : x\nprocessor : 1\n"));

        $network = ServerStats::parseNetwork("Inter-|   Receive\n face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets\n    lo: 999 1 0 0 0 0 0 0 999 1 0 0 0 0 0 0\n  eth0: 5000 10 0 0 0 0 0 0 2000 8 0 0 0 0 0 0\n docker0: 7777 1 0 0 0 0 0 0 7777 1 0 0 0 0 0 0\n");
        $this->assertSame(['in' => 5000, 'out' => 2000], $network);

        $this->assertSame(['loaded' => true, 'state' => 'running'], ServerStats::parseUnit("LoadState=loaded\nActiveState=active\n"));
        $this->assertFalse(ServerStats::parseUnit("LoadState=not-found\nActiveState=inactive\n")['loaded']);

        $processes = ServerStats::parseProcesses("  PID USER      %CPU %MEM COMMAND\n 1234 www-data  12.5  3.1 php-fpm8.4\n    1 root       0.0  0.1 systemd\n");
        $this->assertSame([1234, 'www-data', 12.5, 3.1, 'php-fpm8.4'], array_values($processes[0]));
        $this->assertCount(2, $processes);
    }

    public function test_server_history_averages_samples_into_chart_buckets(): void
    {
        ServerSample::factory()->create(['at' => now()->subMinutes(10), 'cpu' => 10, 'memory' => 50]);
        ServerSample::factory()->create(['at' => now()->subMinutes(9), 'cpu' => 30, 'memory' => 60]);
        ServerSample::factory()->create(['at' => now()->subMinutes(2), 'cpu' => null, 'memory' => 70]);

        $history = app(ServerStats::class)->history('1h');

        $this->assertCount(60, $history['buckets']);
        $this->assertSame(3, $history['summary']['samples']);
        $this->assertSame(20.0, $history['summary']['cpu_avg']);
        $this->assertSame(30.0, $history['summary']['cpu_max']);
        $this->assertSame(60.0, $history['summary']['memory_avg']);
        $this->assertSame(70.0, collect($history['buckets'])->pluck('memory')->filter()->last());
    }

    public function test_the_server_monitoring_page_shows_samples_and_the_sidebar_links_to_it(): void
    {
        $this->signIn();
        ServerSample::factory()->count(3)->sequence(['at' => now()->subMinutes(3)], ['at' => now()->subMinutes(2)], ['at' => now()->subMinute()])->create(['cpu' => 12.5]);

        Livewire::test(MonitoringServer::class)
            ->assertOk()
            ->call('setRange', '7d')
            ->assertSet('range', '7d')
            ->call('setRange', 'bogus')
            ->assertSet('range', '24h');

        $this->get(route('monitoring.server'))->assertOk()->assertSee('Server monitoring');
    }

    public function test_sampling_stores_a_row_when_proc_exists_and_prune_removes_old_samples(): void
    {
        ServerSample::factory()->create(['at' => now()->subDays(40)]);
        ServerSample::factory()->create(['at' => now()]);

        $this->artisan('laravelify:prune-monitoring')->assertSuccessful();
        $this->assertSame(1, ServerSample::query()->count());

        $this->artisan('laravelify:sample-server')->assertSuccessful();
        $this->assertSame(is_readable('/proc/meminfo') ? 2 : 1, ServerSample::query()->count());
    }

    public function test_the_live_feed_returns_only_requests_added_since_the_last_offset(): void
    {
        $this->signIn();
        $website = Website::factory()->create();
        $log = SiteSecurity::metricsLog($website);

        $this->getJson(route('monitoring.website.live', $website))->assertOk()->assertJson(['tracking' => false, 'requests' => []]);

        File::put($log, $this->line('/first', 200, 0.1).'
'.$this->line('/app.css', 200, 0.001).'
');

        $first = $this->getJson(route('monitoring.website.live', $website))->assertOk()->assertJson(['tracking' => true]);
        $this->assertSame(['/first'], array_column($first->json('requests'), 'u'));
        $this->assertSame(filesize($log), $first->json('offset'));

        // A half-written line waits for the next poll.
        File::append($log, $this->line("/search?q=1' UNION SELECT 1--", 500, 0.25, '198.51.100.9').'
'.'{"t":"1","s":"200"');

        $next = $this->getJson(route('monitoring.website.live', $website).'?offset='.$first->json('offset'))->assertOk();
        $this->assertCount(1, $next->json('requests'));
        $this->assertSame(500, $next->json('requests.0.s'));
        $this->assertSame(250, $next->json('requests.0.d'));
        $this->assertGreaterThan(0, $next->json('requests.0.r'));
        $this->assertStringContainsString('SQL injection', $next->json('requests.0.th'));
        $this->assertSame(filesize($log) - strlen('{"t":"1","s":"200"'), $next->json('offset'));

        // An offset past the end (the log was rotated) starts over.
        File::put($log, $this->line('/after-rotation', 200, 0.05).'
');
        $rotated = $this->getJson(route('monitoring.website.live', $website).'?offset=999999')->assertOk();
        $this->assertSame(['/after-rotation'], array_column($rotated->json('requests'), 'u'));
    }

    public function test_the_live_server_feed_answers_with_or_without_proc(): void
    {
        $this->signIn();

        $response = $this->getJson(route('monitoring.server.live'))->assertOk();

        if (is_readable('/proc/meminfo')) {
            $response->assertJson(['available' => true])->assertJsonStructure(['cpu', 'memory', 'load', 'net_in', 'net_out']);
        } else {
            $response->assertExactJson(['available' => false]);
        }

        auth()->logout();
        $this->get(route('monitoring.server.live'))->assertRedirect(route('login'));
    }

    private function line(string $uri, int $status, float $seconds, string $ip = '203.0.113.1'): string
    {
        return json_encode([
            't' => now()->timestamp.'.123',
            'ip' => $ip,
            'm' => 'GET',
            'u' => $uri,
            's' => (string) $status,
            'b' => '512',
            'd' => (string) $seconds,
            'ua' => 'Mozilla/5.0',
            'r' => '-',
            'c' => 'DE',
        ], JSON_UNESCAPED_SLASHES);
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }
}
