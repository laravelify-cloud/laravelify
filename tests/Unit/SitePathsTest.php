<?php

namespace Tests\Unit;

use App\Models\Website;
use App\Services\SiteCommand;
use App\Services\SitePaths;
use RuntimeException;
use Tests\TestCase;

class SitePathsTest extends TestCase
{
    private string $root = '';

    private string $outside = '';

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        $this->deleteTree($this->outside);

        parent::tearDown();
    }

    public function test_paths_stay_inside_the_site_folder(): void
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-site-'.uniqid();
        $this->outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-outside-'.uniqid();
        mkdir($this->root.DIRECTORY_SEPARATOR.'nested', 0777, true);
        mkdir($this->outside);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'hello.txt', 'hi');

        $website = new Website(['deploy_path' => $this->root]);
        $paths = new SitePaths;

        $this->assertSame(realpath($this->root), $paths->root($website));
        $this->assertSame(
            realpath($this->root.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'hello.txt'),
            $paths->resolve($website, 'nested/hello.txt'),
        );

        try {
            $paths->resolve($website, '../'.basename($this->outside));
            $this->fail('A path outside the site folder was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside', $e->getMessage());
        }
    }

    public function test_symlink_escape_is_rejected(): void
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-site-'.uniqid();
        $this->outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-outside-'.uniqid();
        mkdir($this->root);
        mkdir($this->outside);
        file_put_contents($this->outside.DIRECTORY_SEPARATOR.'secret.txt', 'no');

        if (! @symlink($this->outside.DIRECTORY_SEPARATOR.'secret.txt', $this->root.DIRECTORY_SEPARATOR.'escape')) {
            $this->markTestSkipped('This machine cannot create symlinks.');
        }

        $website = new Website(['deploy_path' => $this->root]);
        $paths = new SitePaths;
        $link = $paths->locate($website, 'escape');

        $this->assertTrue(is_link($link));
        $this->assertSame(realpath($this->root), realpath(dirname($link)));

        $this->expectException(RuntimeException::class);
        $paths->resolve($website, 'escape');
    }

    public function test_missing_folder_asks_for_a_deploy(): void
    {
        $website = new Website(['deploy_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-missing-'.uniqid()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deploy the site first.');
        (new SitePaths)->root($website);
    }

    public function test_site_command_is_skipped_when_host_commands_are_disabled(): void
    {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-cmd-'.uniqid();
        mkdir($this->root);
        $website = new Website(['deploy_path' => $this->root]);

        $result = app(SiteCommand::class)->run($website, 'echo hi');

        $this->assertFalse($result->ok);
        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('disabled', $result->output);
    }

    private function deleteTree(string $path): void
    {
        if ($path === '' || ! file_exists($path)) {
            if (is_link($path)) {
                unlink($path);
            }

            return;
        }

        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $this->deleteTree($path.DIRECTORY_SEPARATOR.$name);
        }

        rmdir($path);
    }
}
