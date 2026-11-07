<?php

namespace Tests\Feature;

use App\Models\ServerService;
use App\Services\HostWriter;
use App\Services\ServerCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PhpMyAdminPathTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ServerService
    {
        return ServerService::query()->updateOrCreate(['key' => 'phpmyadmin'], [
            'kind' => 'phpmyadmin', 'name' => 'phpMyAdmin',
            'domain' => 'phpmyadmin.example.com', 'config' => ServerCatalog::phpMyAdminNginx(), 'settings' => ['php_version' => '8.4'],
        ]);
    }

    public function test_phpmyadmin_on_its_own_domain_moves_to_a_path_of_the_panel(): void
    {
        config(['app.url' => 'https://panel.example.com']);
        $service = $this->service();
        $this->mock(HostWriter::class, fn (MockInterface $mock) => $mock->shouldReceive('writeService')->once()->andReturn(''));

        $this->artisan('laravelify:phpmyadmin-path')->expectsOutput('phpMyAdmin is now at https://panel.example.com/phpmyadmin')->assertSuccessful();

        $service->refresh();
        $this->assertSame('panel.example.com/phpmyadmin', $service->domain);
        $this->assertSame('/phpmyadmin', $service->settings['path']);
        $this->assertTrue(ServerCatalog::isPathConfig($service->config));

        $this->artisan('laravelify:phpmyadmin-path')->expectsOutput('phpMyAdmin is already at /phpmyadmin.')->assertSuccessful();
    }

    public function test_a_rejected_config_keeps_the_old_domain(): void
    {
        config(['app.url' => 'https://panel.example.com']);
        $service = $this->service();
        $this->mock(HostWriter::class, fn (MockInterface $mock) => $mock->shouldReceive('writeService')->andThrow(new RuntimeException('nginx said no')));

        $this->artisan('laravelify:phpmyadmin-path')->assertFailed();

        $this->assertSame('phpmyadmin.example.com', $service->fresh()->domain);
    }
}
