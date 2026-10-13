<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never run real system commands (mysql, nginx, sudo host-apply.sh) from a test,
        // even on a Linux server. Tests that need the command runner turn it on themselves.
        config(['laravelify.host_commands' => false]);

        $this->withoutVite();
    }
}
