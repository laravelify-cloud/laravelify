<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\ConfigRenderer;
use App\Support\ProjectDetector;
use App\Support\SiteTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AspNetCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_aspnet_site_listens_on_its_port_in_production(): void
    {
        $env = app(ConfigRenderer::class)->env(Website::factory()->create(['type' => 'aspnet', 'port' => 5080]));

        $this->assertStringContainsString('ASPNETCORE_URLS=http://127.0.0.1:5080', $env);
        $this->assertStringContainsString('ASPNETCORE_ENVIRONMENT=Production', $env);
    }

    public function test_a_csproj_is_detected_with_its_dotnet_version(): void
    {
        $found = ProjectDetector::detect([
            'dotnet_project' => 'Shop.Api.csproj',
            'csproj' => '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup><TargetFramework>net9.0</TargetFramework></PropertyGroup></Project>',
        ]);

        $this->assertSame(['aspnet', '9.0', 'Shop.Api.csproj'], [$found['type'], $found['runtime_version'], $found['reason']]);
    }

    public function test_a_solution_is_detected_and_unknown_versions_are_left_out(): void
    {
        $this->assertSame(['aspnet', null], array_values(array_intersect_key(ProjectDetector::detect(['dotnet_project' => 'Shop.sln']), array_flip(['type', 'runtime_version']))));
        $this->assertNull(ProjectDetector::detect(['dotnet_project' => 'A.csproj', 'csproj' => '<TargetFramework>net4.8</TargetFramework>'])['runtime_version']);
    }

    public function test_the_start_command_finds_the_web_app_of_a_solution(): void
    {
        $out = sys_get_temp_dir().'/aspnet-'.uniqid();
        mkdir($out.'/.laravelify-out', 0777, true);
        file_put_contents($out.'/.laravelify-out/Shop.Core.runtimeconfig.json', '{"runtimeOptions":{"framework":{"name":"Microsoft.NETCore.App"}}}');
        file_put_contents($out.'/.laravelify-out/Shop.Api.runtimeconfig.json', '{"runtimeOptions":{"frameworks":[{"name":"Microsoft.AspNetCore.App"}]}}');

        $start = str_replace('dotnet ', 'echo ', SiteTypes::get('aspnet')['start']);
        $result = shell_exec('cd '.escapeshellarg($out).' && bash -c '.escapeshellarg($start).' 2>&1');

        $this->assertSame('.laravelify-out/Shop.Api.dll', trim((string) $result));
    }
}
