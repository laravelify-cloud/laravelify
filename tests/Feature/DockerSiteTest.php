<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\ConfigRenderer;
use App\Services\Deployer;
use App\Services\HostWriter;
use App\Support\ProjectDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class DockerSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dockerfile_is_the_type_only_when_nothing_else_matches(): void
    {
        $this->assertSame('docker', ProjectDetector::detect(['dockerfile' => true])['type']);
        $this->assertSame('nextjs', ProjectDetector::detect(['dockerfile' => true, 'package' => '{"dependencies":{"next":"^15"}}'])['type']);
        $this->assertSame('spring', ProjectDetector::detect(['dockerfile' => true, 'maven' => '<project/>'])['type']);
    }

    public function test_a_docker_site_has_a_port_and_no_pm2_app(): void
    {
        $website = Website::factory()->create(['type' => 'docker', 'port' => 8090]);
        $renderer = app(ConfigRenderer::class);

        $this->assertSame('', $renderer->process($website));
        $this->assertStringContainsString('PORT=8090', $renderer->env($website));
        $this->assertStringContainsString('127.0.0.1:8090', $renderer->nginx($website));
    }

    public function test_a_deploy_builds_the_image_as_root_and_starts_the_container(): void
    {
        $path = storage_path('framework/testing/docker-site');
        File::ensureDirectoryExists($path);
        File::put($path.'/Dockerfile', "FROM nginx:alpine\nEXPOSE 80\n");
        $website = Website::factory()->create(['type' => 'docker', 'port' => 8090, 'deploy_path' => $path, 'repository' => 'https://github.com/acme/app.git']);

        $runner = Mockery::mock(CommandRunner::class)->shouldIgnoreMissing();
        $runner->shouldReceive('supportsHostCommands')->andReturn(true);
        $runner->shouldReceive('runAsSite')->andReturn(new CommandResult(true, false, [], 'abc123', 0));
        $runner->shouldReceive('run')->andReturn(new CommandResult(true, false, [], '', 0));
        $this->app->instance(CommandRunner::class, $runner);

        $host = Mockery::mock(HostWriter::class)->shouldIgnoreMissing('');
        $host->shouldReceive('dockerInstall')->once()->andReturn('Docker is installed.');
        $host->shouldReceive('siteDockerBuild')->once()->with(Mockery::on(fn (Website $site) => $site->is($website)))->andReturn('Built lfy-site-x:latest.');
        $host->shouldReceive('writeWebsite')->once()->andReturn('Container runs.');
        $this->app->instance(HostWriter::class, $host);

        $deployment = Deployment::query()->create(['website_id' => $website->id, 'status' => 'queued', 'trigger' => 'manual', 'log' => '']);
        app(Deployer::class)->run($deployment);

        $this->assertSame('success', $deployment->fresh()->status, (string) $deployment->fresh()->log);
        File::deleteDirectory($path);
    }
}
