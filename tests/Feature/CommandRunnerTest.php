<?php

namespace Tests\Feature;

use App\Services\CommandRunner;
use Tests\TestCase;

class CommandRunnerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DB_DATABASE');
        unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE'], $_ENV['APP_KEY'], $_SERVER['APP_KEY']);

        parent::tearDown();
    }

    public function test_site_commands_do_not_inherit_the_panel_environment(): void
    {
        putenv('DB_DATABASE=laravelify');
        $_ENV['DB_DATABASE'] = 'laravelify';
        $_SERVER['DB_DATABASE'] = 'laravelify';
        $_ENV['APP_KEY'] = 'base64:panel-key';
        config(['laravelify.host_commands' => true]);

        $runner = new CommandRunner;
        $env = $runner->environment();

        $this->assertFalse($env['DB_DATABASE']);
        $this->assertFalse($env['APP_KEY']);

        $result = $runner->run([PHP_BINARY, '-r', 'echo json_encode([getenv("DB_DATABASE"), getenv("APP_KEY"), getenv("PATH") !== false, getenv("COMPOSER_HOME") !== false]);']);

        $this->assertTrue($result->ok, $result->output);
        $this->assertSame([false, false, true, true], json_decode($result->output, true));
    }
}
