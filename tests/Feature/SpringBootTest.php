<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\ConfigRenderer;
use App\Services\SiteHealth;
use App\Services\SpringHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SpringBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_spring_site_gets_its_port_in_the_env(): void
    {
        $website = Website::factory()->create(['type' => 'spring', 'port' => 8085]);

        $env = app(ConfigRenderer::class)->env($website);

        $this->assertStringContainsString('SERVER_PORT=8085', $env);
        $this->assertStringContainsString('SERVER_ADDRESS=127.0.0.1', $env);
    }

    public function test_the_health_check_reads_the_actuator(): void
    {
        $website = Website::factory()->create(['type' => 'spring', 'port' => 8085]);
        Http::fake(['127.0.0.1:8085/actuator/health' => Http::sequence()
            ->push(['status' => 'UP'])
            ->push(['status' => 'DOWN', 'components' => ['db' => ['status' => 'DOWN'], 'ping' => ['status' => 'UP']]], 503)
            ->push('Not found', 404),
        ]);
        $health = app(SpringHealth::class);

        $this->assertSame('up', $health->check($website)['state']);
        $this->assertSame(['state' => 'down', 'detail' => '/actuator/health says DOWN (down: db).'], $health->check($website));
        $this->assertSame('missing', $health->check($website)['state']);
    }

    public function test_a_deploy_waits_for_the_app_to_answer(): void
    {
        Sleep::fake();
        $website = Website::factory()->create(['type' => 'spring', 'port' => 8085]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls < 3) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['status' => 'UP']);
        });

        $this->assertSame('up', app(SpringHealth::class)->waitUntilReady($website)['state']);
        Sleep::assertSleptTimes(2);

        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $this->assertSame('none', app(SpringHealth::class)->waitUntilReady($website, 10)['state']);
    }

    public function test_the_health_page_lists_the_spring_check(): void
    {
        Http::fake(['*' => Http::response(['status' => 'UP'])]);
        $website = Website::factory()->create(['type' => 'spring', 'port' => 8085]);

        $check = collect(app(SiteHealth::class)->check($website))->firstWhere('key', 'spring');

        $this->assertSame(['Spring Boot health', 'ok'], [$check['title'], $check['status']]);
    }
}
