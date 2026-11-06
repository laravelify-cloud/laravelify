<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Website;
use App\Services\ConfigRenderer;
use App\Services\HostWriter;
use App\Services\Runtimes;
use App\Services\WebsiteCreator;
use App\Support\ProjectDetector;
use App\Support\SiteTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ToolchainSitesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function servers(): array
    {
        return [
            'django' => ['django', 'python', 'gunicorn "$LARAVELIFY_WSGI" --bind "127.0.0.1:$PORT"'],
            'flask' => ['flask', 'python', 'gunicorn app:app'],
            'fastapi' => ['fastapi', 'python', 'uvicorn main:app'],
            'go' => ['go', 'go', './.laravelify-app'],
            'bun' => ['bun', 'bun', 'bun run start'],
            'spring' => ['spring', 'java', 'java -jar'],
            'aspnet' => ['aspnet', 'dotnet', '.laravelify-out/'],
        ];
    }

    #[DataProvider('servers')]
    public function test_servers_run_under_pm2_with_their_toolchain(string $type, string $toolchain, string $start): void
    {
        $site = Website::factory()->make(['type' => $type, 'slug' => 'demo', 'deploy_path' => '/var/www/demo', 'port' => 3100]);
        $default = SiteTypes::TOOLCHAINS[$toolchain]['default'];
        $process = app(ConfigRenderer::class)->process($site);

        $this->assertSame($toolchain, $site->toolchain());
        $this->assertFalse($site->usesNode(), 'No Node.js version to pick for these.');
        $this->assertStringContainsString('with-toolchain.sh", "'.$toolchain.'", "'.$default.'", "bash", "-c", "exec ', $process);
        $this->assertStringContainsString(addcslashes($start, '"'), $process);
        $this->assertStringContainsString('PORT: "3100"', $process);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:3100', app(ConfigRenderer::class)->nginx($site));
    }

    public function test_a_python_site_is_created_with_its_version_and_port(): void
    {
        $result = app(WebsiteCreator::class)->create(Group::factory()->create(), [
            'name' => 'Blog', 'type' => 'django', 'domain' => '', 'repository' => 'https://github.com/acme/blog.git', 'branch' => 'main',
            'runtime_version' => '3.13',
        ]);
        $site = $result['website'];

        $this->assertSame('3.13', $site->runtime_version);
        $this->assertSame('Python 3.13', $site->runtimeLabel());
        $this->assertNotNull($site->port);
        $this->assertStringContainsString('uv pip install -r requirements.txt gunicorn', (string) $site->installCommand());
    }

    public function test_a_wrong_toolchain_version_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(WebsiteCreator::class)->create(Group::factory()->create(), [
            'name' => 'Api', 'type' => 'go', 'domain' => '', 'repository' => 'https://github.com/acme/api.git', 'branch' => 'main',
            'runtime_version' => '1.24; rm -rf /',
        ]);
    }

    public function test_hugo_is_a_static_site_built_with_hugo_only(): void
    {
        $site = Website::factory()->make(['type' => 'hugo', 'runtime_version' => null]);

        $this->assertTrue($site->isStatic());
        $this->assertFalse($site->usesNode());
        $this->assertSame('Hugo 0.145', $site->runtimeLabel());
        $this->assertSame('hugo --minify', $site->buildCommand());
        $this->assertSame(['bash', base_path('scripts/with-toolchain.sh'), 'hugo', '0.145', 'bash', '-c', 'hugo --minify'], $site->toolCommand('bash', '-c', 'hugo --minify'));
    }

    public function test_a_deploy_installs_a_missing_toolchain_first(): void
    {
        $writer = Mockery::mock(HostWriter::class);
        $writer->shouldReceive('toolchainInstall')->once()->with('go', '1.23')->andReturn('ok');
        $this->app->instance(HostWriter::class, $writer);
        $site = Website::factory()->create(['type' => 'go', 'runtime_version' => '1.23', 'php_version' => null]);

        $this->assertSame('Installed Go 1.23 on this server for this website.', app(Runtimes::class)->ensure($site));
    }

    /**
     * @return array<string, array{array<string, mixed>, string, ?string}>
     */
    public static function repositories(): array
    {
        return [
            'django by manage.py' => [['manage' => true, 'requirements' => "Django==5.1\ngunicorn\n", 'python_version' => "3.11\n"], 'django', '3.11'],
            'fastapi' => [['requirements' => "fastapi\nuvicorn[standard]\n"], 'fastapi', null],
            'flask from pyproject' => [['pyproject' => "[project]\nrequires-python = \">=3.12\"\ndependencies = [\"flask>=3\"]\n"], 'flask', '3.13'],
            'go' => [['gomod' => "module example.com/api\n\ngo 1.23.4\n"], 'go', '1.23'],
            'hugo' => [['hugo' => true], 'hugo', null],
            'docusaurus' => [['package' => '{"dependencies":{"@docusaurus/core":"^3"}}'], 'docusaurus', null],
            'bun server' => [['package' => '{"scripts":{"start":"bun run index.ts"},"dependencies":{"hono":"^4"}}', 'bun' => true], 'bun', null],
            'spring with maven' => [['maven' => '<project><properties><java.version>17</java.version></properties></project>'], 'spring', '17'],
            'spring with gradle' => [['gradle' => 'java { toolchain { languageVersion = JavaLanguageVersion.of(21) } }'], 'spring', '21'],
            'aspnet' => [['dotnet' => true], 'aspnet', null],
            'bun with next stays next' => [['package' => '{"dependencies":{"next":"^15"}}', 'bun' => true], 'nextjs', null],
        ];
    }

    /**
     * @param  array<string, mixed>  $files
     */
    #[DataProvider('repositories')]
    public function test_the_framework_and_version_are_read_from_the_repository(array $files, string $type, ?string $version): void
    {
        $found = ProjectDetector::detect($files);

        $this->assertSame($type, $found['type']);
        $this->assertSame($version, $found['runtime_version']);
    }
}
