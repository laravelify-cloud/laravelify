<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MysqlDefaultsTest extends TestCase
{
    public function test_lets_site_users_create_triggers_with_binary_logging_on(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('statement')->once()->with('SET PERSIST log_bin_trust_function_creators = 1')->andReturnTrue();
        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

        $this->artisan('laravelify:mysql-defaults')
            ->expectsOutput('MySQL: log_bin_trust_function_creators = 1')
            ->assertSuccessful();
    }

    public function test_tells_how_to_do_it_by_hand_when_mysql_refuses(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('statement')->andThrow(new RuntimeException('Access denied; you need SYSTEM_VARIABLES_ADMIN'));
        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

        $this->artisan('laravelify:mysql-defaults')
            ->expectsOutputToContain('SET PERSIST log_bin_trust_function_creators = 1;')
            ->assertFailed();
    }
}
