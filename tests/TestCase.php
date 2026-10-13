<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    /** A storage folder of this test only, so files one test writes never reach another. */
    private ?string $testStorage = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Never run real system commands (mysql, nginx, sudo host-apply.sh) from a test,
        // even on a Linux server. Tests that need the command runner turn it on themselves.
        config(['laravelify.host_commands' => false]);

        // Backup contents lists, task downloads, and example repositories are written under
        // storage/app. Compiled views and logs keep their configured folders.
        $this->testStorage = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-storage-'.uniqid();
        File::ensureDirectoryExists($this->testStorage.'/app');
        $this->app->useStoragePath($this->testStorage);

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if ($this->testStorage !== null) {
            File::deleteDirectory($this->testStorage);
        }

        parent::tearDown();
    }
}
