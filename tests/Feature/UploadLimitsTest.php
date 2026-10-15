<?php

namespace Tests\Feature;

use App\Models\ServerService;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\HostWriter;
use App\Services\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class UploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    /** phpMyAdmin's file as it is on a server today: the access prompt and the sign-in script included. */
    private const PHPMYADMIN = <<<'NGINX'
        server {
            listen 80;
            server_name phpmyadmin.example.com;

            # laravelify-access:start (Security > Access in the panel)
            auth_request /__laravelify_access;

            location = /__laravelify_access {
                internal;
                fastcgi_pass unix:/run/php/php8.4-fpm.sock;
                fastcgi_param REQUEST_URI /_access/phpmyadmin;
            }
            # laravelify-access:end

            root /usr/share/phpmyadmin;
            client_max_body_size 64M;

            location = /laravelify-signon.php {
                include fastcgi_params;
                fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            }

            location ~ \.php$ {
                include snippets/fastcgi-php.conf;
                fastcgi_pass unix:/run/php/php8.4-fpm.sock;
                fastcgi_read_timeout 120;
            }
        }
        NGINX;

    public function test_raises_the_limits_only_where_php_runs_and_changes_nothing_twice(): void
    {
        $config = UploadLimits::nginx(self::PHPMYADMIN);

        $this->assertStringContainsString('client_max_body_size 256M;', $config);
        $this->assertStringNotContainsString('64M', $config);
        $this->assertStringContainsString("fastcgi_read_timeout 600;\n        fastcgi_param PHP_VALUE \"upload_max_filesize=256M\npost_max_size=256M\nmemory_limit=512M\nmax_execution_time=600\";", $config);
        $this->assertSame(1, substr_count($config, 'PHP_VALUE'), 'Only the main PHP block gets the settings.');
        $this->assertSame($config, UploadLimits::nginx($config));
    }

    public function test_a_php_block_without_a_timeout_and_a_file_without_a_body_size_get_both(): void
    {
        $config = UploadLimits::nginx("server {\n    server_name panel.example.com;\n\n    location ~ \\.php$ {\n        fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n    }\n}\n");

        $this->assertStringContainsString("server_name panel.example.com;\n    client_max_body_size 256M;", $config);
        $this->assertStringContainsString("fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n        fastcgi_read_timeout 600;\n        fastcgi_param PHP_VALUE", $config);
    }

    public function test_the_update_raises_phpmyadmin_and_the_panel_and_puts_a_rejected_panel_file_back(): void
    {
        $service = ServerService::query()->updateOrCreate(['kind' => 'phpmyadmin'], ['name' => 'phpMyAdmin', 'key' => 'phpmyadmin', 'domain' => 'phpmyadmin.example.com', 'config' => self::PHPMYADMIN]);
        $panel = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-panel-'.uniqid().'.conf';
        file_put_contents($panel, "server {\n    server_name panel.example.com;\n    client_max_body_size 64M;\n\n    location ~ \\.php$ {\n        fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n    }\n}\n");

        $this->mock(HostWriter::class, fn (MockInterface $mock) => $mock->shouldReceive('writeService')->once()->andReturn('ok'));
        $commands = new \ArrayObject;
        $this->mock(CommandRunner::class, function (MockInterface $mock) use ($commands) {
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use ($commands) {
                $commands[] = implode(' ', $command);

                return new CommandResult(true, false, $command, 'ok', 0);
            });
        });

        try {
            $this->artisan('laravelify:upload-limits', ['--panel-file' => $panel])->assertSuccessful();

            $this->assertStringContainsString('client_max_body_size 256M;', $service->fresh()->config);
            $this->assertStringContainsString('PHP_VALUE', (string) file_get_contents($panel));
            $this->assertSame(['nginx -t', 'systemctl reload nginx'], $commands->getArrayCopy());

            // Run again: nothing left to change.
            $this->artisan('laravelify:upload-limits', ['--panel-file' => $panel])
                ->expectsOutput('Panel: upload limits already set.')
                ->assertSuccessful();
        } finally {
            @unlink($panel);
        }
    }

    public function test_a_panel_file_nginx_rejects_is_put_back(): void
    {
        $panel = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-panel-'.uniqid().'.conf';
        $original = "server {\n    server_name panel.example.com;\n\n    location ~ \\.php$ {\n        fastcgi_pass unix:/run/php/php8.4-fpm.sock;\n    }\n}\n";
        file_put_contents($panel, $original);
        $this->mock(CommandRunner::class, fn (MockInterface $mock) => $mock->shouldReceive('run')->andReturn(new CommandResult(false, false, ['nginx', '-t'], 'nginx: [emerg] test failed', 1)));

        try {
            $this->artisan('laravelify:upload-limits', ['--panel-file' => $panel])->assertFailed();
            $this->assertSame($original, file_get_contents($panel));
        } finally {
            @unlink($panel);
        }
    }
}
