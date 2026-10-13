<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show;
use App\Models\User;
use App\Models\Website;
use App\Services\ServerProcesses;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PortMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_ss_output_is_parsed_into_ports_processes_and_scope(): void
    {
        $output = implode("\n", [
            'LISTEN 0      511          0.0.0.0:80        0.0.0.0:*    users:(("nginx",pid=812,fd=6),("nginx",pid=811,fd=6))',
            'LISTEN 0      511             [::]:80           [::]:*    users:(("nginx",pid=812,fd=7))',
            'LISTEN 0      511        127.0.0.1:3100      0.0.0.0:*    users:(("node /var/www/b",pid=1300,fd=21))',
            'LISTEN 0      151        127.0.0.1:3306      0.0.0.0:*    users:(("mysqld",pid=700,fd=23))',
            '0      4096   127.0.0.53%lo:53   0.0.0.0:*',
        ]);

        $ports = ServerProcesses::parseListening($output);

        $this->assertSame([53, 80, 3100, 3306], array_keys($ports));
        $this->assertTrue($ports[80]['public']);
        $this->assertSame(['nginx'], $ports[80]['processes']);
        $this->assertFalse($ports[3100]['public']);
        $this->assertSame(['node /var/www/b'], $ports[3100]['processes']);
        $this->assertSame([], $ports[53]['processes']);
    }

    public function test_the_port_map_lists_site_and_service_ports_and_the_next_free_port(): void
    {
        config(['laravelify.node_port_start' => 3000]);
        Website::factory()->create(['name' => 'Storefront', 'type' => 'nextjs', 'port' => 3000, 'domain' => 'shop.example.com']);
        Website::factory()->create(['name' => 'Admin', 'type' => 'nextjs', 'port' => 3001, 'domain' => null, 'http_port' => 8081]);

        $map = app(ServerProcesses::class)->portMap();
        $byPort = collect($map['rows'])->keyBy('port');

        $this->assertSame('Storefront', $byPort[3000]['owner']);
        $this->assertSame('site', $byPort[3000]['kind']);
        $this->assertStringContainsString('shop.example.com', $byPort[3000]['detail']);
        $this->assertSame('HTTP port', $byPort[8081]['detail']);
        $this->assertSame('MySQL', $byPort[3306]['owner']);
        $this->assertSame('service', $byPort[3306]['kind']);
        $this->assertFalse($byPort[3000]['listening']);
        $this->assertSame(3002, $map['next_free']);
    }

    public function test_the_website_page_returns_the_port_map_without_rerendering(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
        $website = Website::factory()->create(['type' => 'nextjs', 'port' => 3100]);

        $component = Livewire::test(Show::class, ['website' => $website])
            ->assertSee('Used ports')
            ->call('portMap');

        $rows = collect($component->effects['returns'][0]['rows']);
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['port'] === 3100 && $row['website'] === $website->slug));
    }
}
