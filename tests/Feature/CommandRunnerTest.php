<?php

namespace Tests\Feature;

use App\Services\CommandRunner;
use Tests\TestCase;

class CommandRunnerTest extends TestCase
{
    /** @var array<string, array{getenv: string|false, env: mixed, server: mixed}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['DB_DATABASE', 'APP_KEY'] as $key) {
            $this->saved[$key] = ['getenv' => getenv($key), 'env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null];
        }
    }

    /**
     * Put the variables back as they were. Removing them would also remove the test database
     * that phpunit.xml sets, and every test after this one would lose its database.
     */
    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $original) {
            $original['getenv'] === false ? putenv($key) : putenv($key.'='.$original['getenv']);

            foreach (['env' => &$_ENV, 'server' => &$_SERVER] as $name => &$bag) {
                $original[$name] === null ? $bag = array_diff_key($bag, [$key => true]) : $bag[$key] = $original[$name];
            }

            unset($bag);
        }

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

    public function test_a_tool_folder_that_cannot_be_created_falls_back_instead_of_failing(): void
    {
        $home = storage_path('app/process-home');
        @mkdir($home, 0777, true);
        @rmdir($home.DIRECTORY_SEPARATOR.'.corepack');
        file_put_contents($home.DIRECTORY_SEPARATOR.'.corepack', 'blocks the folder');

        try {
            $env = (new CommandRunner)->environment();

            $this->assertSame($home, $env['HOME']);
            $this->assertNotSame($home.DIRECTORY_SEPARATOR.'.corepack', $env['COREPACK_HOME']);
            $this->assertDirectoryExists($env['COREPACK_HOME']);
        } finally {
            @unlink($home.DIRECTORY_SEPARATOR.'.corepack');
        }
    }
}
