<?php

namespace Tests\Feature;

use App\Livewire\Websites\Form as WebsiteForm;
use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\Group;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\ConfigRenderer;
use App\Services\Deployer;
use App\Services\TwoFactor;
use App\Support\BuildOutput;
use App\Support\Examples;
use App\Support\SiteTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteTypesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-types-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_every_type_has_an_example_project(): void
    {
        foreach (SiteTypes::keys() as $type) {
            $this->assertTrue(Examples::has($type), $type.' needs a folder in resources/examples');
        }

        $this->assertFalse(Examples::has('../app'));
    }

    public function test_a_site_can_start_from_an_example_without_a_repository(): void
    {
        $this->signIn();
        config(['laravelify.sites_root' => $this->root]);
        $group = Group::factory()->create();

        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->call('chooseType', 'express')
            ->set('name', 'Try express')
            ->set('use_example', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $website = Website::query()->where('type', 'express')->sole();

        $this->assertSame('main', $website->branch);
        $this->assertSame(storage_path('app/examples/'.$website->slug), $website->repository);
        $this->assertDirectoryExists($website->repository.'/.git');
        $this->assertFileExists($website->repository.'/server.js');

        File::deleteDirectory($website->repository);
    }

    public function test_the_catalog_groups_backend_frontend_and_full_stack(): void
    {
        $this->assertSame(['backend', 'frontend', 'fullstack'], array_keys(SiteTypes::GROUPS));
        $this->assertSame(['PHP', 'TypeScript / JavaScript'], array_keys(SiteTypes::inGroup('backend')));
        $this->assertSame(['laravel', 'php'], array_keys(SiteTypes::inGroup('backend')['PHP']));
        $this->assertSame(['express', 'nestjs', 'node'], array_keys(SiteTypes::inGroup('backend')['TypeScript / JavaScript']));
        $this->assertSame(['react', 'vue', 'angular', 'svelte', 'astro', 'static'], array_keys(SiteTypes::inGroup('frontend')['']));
        $this->assertSame(['nextjs', 'nuxt', 'sveltekit'], array_keys(SiteTypes::inGroup('fullstack')['']));
        $this->assertSame(['livewire', 'inertia'], array_keys(SiteTypes::stacks()));

        foreach (SiteTypes::all() as $key => $definition) {
            $this->assertContains($definition['runtime'], ['php', 'node', 'static'], $key);
            $this->assertArrayHasKey($definition['group'], SiteTypes::GROUPS, $key);

            foreach (['label', 'language', 'strength', 'best', 'icon'] as $field) {
                $this->assertNotEmpty($definition[$field], $key.' needs '.$field);
            }
        }

        foreach (SiteTypes::stacks() as $stack) {
            $this->assertTrue(SiteTypes::exists($stack['type']));
        }

        // Unknown types never break a page.
        $this->assertSame('Laravel', SiteTypes::label('something-old'));
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function creatableTypes(): array
    {
        return [
            'laravel' => ['laravel', 'php', ['php_version' => '8.3']],
            'plain php' => ['php', 'php', ['php_version' => '8.3', 'public_dir' => 'web']],
            'express' => ['express', 'node', ['node_version' => '22']],
            'nestjs' => ['nestjs', 'node', ['node_version' => '22']],
            'node' => ['node', 'node', ['node_version' => '24', 'start_command' => 'node server.js']],
            'react' => ['react', 'static', ['node_version' => '22']],
            'vue' => ['vue', 'static', ['node_version' => '22']],
            'angular' => ['angular', 'static', ['node_version' => '22', 'public_dir' => 'dist/shop/browser']],
            'svelte' => ['svelte', 'static', ['node_version' => '22']],
            'astro' => ['astro', 'static', ['node_version' => '22']],
            'static html' => ['static', 'static', ['public_dir' => 'docs']],
            'nextjs' => ['nextjs', 'node', ['node_version' => '22']],
            'nuxt' => ['nuxt', 'node', ['node_version' => '22']],
            'sveltekit' => ['sveltekit', 'node', ['node_version' => '22']],
        ];
    }

    /**
     * @param  array<string, string>  $fields
     */
    #[DataProvider('creatableTypes')]
    public function test_every_type_can_be_created_with_the_files_its_runtime_needs(string $type, string $runtime, array $fields): void
    {
        $this->signIn();
        config(['laravelify.sites_root' => $this->root]);
        $group = Group::factory()->create();

        $component = Livewire::test(WebsiteForm::class, ['group' => $group])
            ->call('chooseType', $type)
            ->set('name', 'My '.$type)
            ->set('domain', $type.'.example.org')
            ->set('branch', 'main');

        foreach ($fields as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('save')->assertHasNoErrors()->assertRedirect();

        $website = Website::query()->where('type', $type)->sole();

        $this->assertSame($runtime, $website->runtime());
        $this->assertSame($runtime === 'php' ? '8.3' : null, $website->php_version);
        $this->assertSame($website->usesNode() ? ($fields['node_version'] ?? '22') : null, $website->node_version);
        $this->assertSame($runtime === 'node' ? 3000 : null, $website->port);
        $this->assertNotEmpty($website->nginx_config);
        $this->assertSame($website->hasProcessFile(), $website->process_config !== '');

        match ($runtime) {
            'php' => $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.3-fpm.sock', $website->nginx_config),
            'node' => $this->assertStringContainsString('proxy_pass http://127.0.0.1:3000', $website->nginx_config),
            default => $this->assertStringContainsString('root '.($type === 'static' ? $website->deploy_path.'/docs' : $website->deploy_path.'/public_html'), $website->nginx_config),
        };
    }

    public function test_a_ready_made_stack_creates_a_laravel_site_and_a_bad_type_is_refused(): void
    {
        $this->signIn();
        config(['laravelify.sites_root' => $this->root]);
        $group = Group::factory()->create();

        $component = Livewire::test(WebsiteForm::class, ['group' => $group])
            ->call('chooseType', 'laravel', 'livewire')
            ->assertSet('type', 'laravel')
            ->assertSet('stack', 'livewire')
            ->call('chooseType', 'laravel', 'does-not-exist')
            ->assertSet('stack', '')
            ->call('chooseType', 'cobol')
            ->assertSet('type', 'laravel')
            ->set('name', 'Bad')
            ->set('type', 'cobol')
            ->call('save')
            ->assertHasErrors('type');

        $this->assertSame(0, Website::query()->count());
        $component->assertSee('Backend')->assertSee('Frontend')->assertSee('Full-stack');
    }

    public function test_build_settings_are_validated_and_saved_per_runtime(): void
    {
        $this->signIn();
        config(['laravelify.sites_root' => $this->root]);
        $group = Group::factory()->create();

        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->call('chooseType', 'react')
            ->set('name', 'Dashboard')
            ->set('domain', 'dash.example.org')
            ->set('build_command', "npm run build\nrm -rf /")
            ->call('save')
            ->assertHasErrors('build_command')
            ->set('build_command', 'pnpm build')
            ->set('public_dir', '../etc')
            ->call('save')
            ->assertHasErrors('public_dir')
            ->set('public_dir', 'out')
            ->call('save')
            ->assertHasNoErrors();

        $website = Website::query()->where('type', 'react')->sole();
        $this->assertSame('pnpm build', $website->build_command);
        $this->assertSame('out', $website->public_dir);

        // A start command only means something for a Node server.
        Livewire::test(WebsiteForm::class, ['group' => $group])
            ->call('chooseType', 'vue')
            ->set('name', 'Vue app')
            ->set('start_command', 'node nothing.js')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Website::query()->where('type', 'vue')->sole()->start_command);
    }

    public function test_nginx_and_process_files_follow_the_type(): void
    {
        $renderer = app(ConfigRenderer::class);
        $site = fn (string $type, array $attributes = []): Website => Website::factory()->make(array_merge([
            'type' => $type,
            'slug' => 'demo',
            'domain' => 'demo.example.org',
            'deploy_path' => '/var/www/demo',
            'node_version' => '22',
            'port' => 3100,
        ], $attributes));

        // Single-page apps fall back to index.html; Astro and plain HTML answer 404 for unknown paths.
        $react = $renderer->nginx($site('react'));
        $this->assertStringContainsString('root /var/www/demo/public_html;', $react);
        $this->assertStringContainsString('try_files $uri $uri/ /index.html;', $react);
        $this->assertStringContainsString('location = /index.html', $react);
        $this->assertStringContainsString('try_files $uri $uri.html $uri/ =404;', $renderer->nginx($site('astro')));
        $this->assertStringContainsString('root /var/www/demo;', $renderer->nginx($site('static', ['public_dir' => null])));
        $this->assertStringContainsString('root /var/www/demo/site;', $renderer->nginx($site('static', ['public_dir' => 'site'])));
        $this->assertSame('', $renderer->process($site('react')));
        $this->assertSame('', $renderer->process($site('php')));

        // Plain PHP: the web root is configurable.
        $php = $renderer->nginx($site('php', ['php_version' => '8.2', 'public_dir' => 'web']));
        $this->assertStringContainsString('root /var/www/demo/web;', $php);
        $this->assertStringContainsString('php8.2-fpm.sock', $php);
        $this->assertStringContainsString('root /var/www/demo;', $renderer->nginx($site('php', ['public_dir' => '.'])));
        $this->assertStringContainsString('root /var/www/demo/public;', $renderer->nginx($site('laravel')));

        // Node servers: PM2 runs the start command with the site's Node version.
        $nest = $renderer->process($site('nestjs'));
        $this->assertStringContainsString('cwd: "/var/www/demo"', $nest);
        $this->assertStringContainsString('"exec node dist/main.js"', $nest);
        $this->assertStringContainsString('"22"', $nest);
        $this->assertStringContainsString('PORT: "3100"', $nest);
        $this->assertStringContainsString('"exec npm start"', $renderer->process($site('express')));
        $this->assertStringContainsString('"exec node server.js --port 3100"', $renderer->process($site('node', ['start_command' => 'node server.js --port 3100'])));
        $this->assertStringContainsString('NITRO_PORT: "3100"', $renderer->process($site('nuxt')));
        $this->assertStringContainsString('node .output/server/index.mjs', $renderer->process($site('nuxt')));
        $this->assertStringContainsString('ORIGIN: "https://demo.example.org"', $renderer->process($site('sveltekit', ['cloudflare_tunnel_id' => 1])));
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:3100', $renderer->nginx($site('nuxt')));

        // Next.js keeps its standalone file.
        $next = $renderer->process($site('nextjs'));
        $this->assertStringContainsString('cwd: "/var/www/demo/.next/standalone"', $next);
        $this->assertStringContainsString('script: "server.js"', $next);
    }

    public function test_build_output_is_found_in_the_usual_places(): void
    {
        $make = function (string $name, array $files): string {
            $dir = $this->root.'/'.$name;

            foreach ($files as $file) {
                File::ensureDirectoryExists(dirname($dir.'/'.$file));
                File::put($dir.'/'.$file, 'x');
            }

            return $dir;
        };

        $vite = $make('vite', ['dist/index.html', 'dist/assets/app.js', 'src/main.ts']);
        $this->assertSame('dist', BuildOutput::locate($vite, null, ['dist', 'build']));

        $cra = $make('cra', ['build/index.html']);
        $this->assertSame('build', BuildOutput::locate($cra, null, ['dist', 'build']));

        $angular = $make('angular', ['dist/shop/browser/index.html', 'dist/shop/server/main.mjs']);
        $this->assertSame('dist/shop/browser', BuildOutput::locate($angular, null, ['dist/*/browser', 'dist/*', 'dist']));

        $angularOld = $make('angular-old', ['dist/shop/index.html']);
        $this->assertSame('dist/shop', BuildOutput::locate($angularOld, null, ['dist/*/browser', 'dist/*', 'dist']));

        // A folder chosen on the site wins, and must exist.
        $custom = $make('custom', ['out/index.html', 'dist/index.html']);
        $this->assertSame('out', BuildOutput::locate($custom, 'out', ['dist']));
        $this->assertNull(BuildOutput::locate($custom, 'missing', ['dist']));

        // No index.html: a folder with files still counts; an empty or missing one does not.
        $other = $make('other', ['dist/about.html']);
        $this->assertSame('dist', BuildOutput::locate($other, null, ['dist']));
        File::ensureDirectoryExists($this->root.'/empty/dist');
        $this->assertNull(BuildOutput::locate($this->root.'/empty', null, ['dist']));
        $this->assertNull(BuildOutput::locate($this->root.'/nothing', null, ['dist']));

        $this->assertTrue(BuildOutput::safe('dist/app'));
        $this->assertTrue(BuildOutput::safe('.'));
        $this->assertFalse(BuildOutput::safe('../etc'));
        $this->assertFalse(BuildOutput::safe('/etc'));
        $this->assertFalse(BuildOutput::safe('my dist'));
    }

    public function test_a_front_end_deploy_installs_builds_and_serves_the_output_folder(): void
    {
        $probe = $this->root.'/symlink-probe';

        if (! @symlink($this->root, $probe)) {
            $this->markTestSkipped('This machine cannot create symlinks.');
        }

        @unlink($probe);

        $site = $this->checkout('react', ['package-lock.json' => '{}', 'package.json' => '{}', 'dist/index.html' => '<html>']);
        $runner = $this->fakeRunner();

        $website = $this->website('react', $site);
        $deployment = $website->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);

        app(Deployer::class)->run($deployment);

        $this->assertSame('success', $deployment->fresh()->status, (string) $deployment->fresh()->log);
        $commands = collect($runner->commands);
        $this->assertTrue($commands->contains(fn (string $line): bool => str_ends_with($line, '22 npm --no-progress --no-fund --loglevel=warn ci')));
        $this->assertTrue($commands->contains(fn (string $line): bool => str_ends_with($line, '22 npm --no-progress --no-fund --loglevel=warn run build')));
        $this->assertTrue(is_link($site.'/public_html'));
        $this->assertSame('dist', readlink($site.'/public_html'));
        $this->assertStringContainsString('Serving dist', (string) $deployment->fresh()->log);
        $this->assertFileDoesNotExist($site.'/.laravelify-built');
    }

    public function test_a_front_end_deploy_uses_custom_commands_and_explains_a_missing_output_folder(): void
    {
        $site = $this->checkout('vue', ['package.json' => '{}']);
        $runner = $this->fakeRunner();

        $website = $this->website('vue', $site, ['install_command' => 'yarn install --immutable', 'build_command' => 'yarn build --mode production']);
        $deployment = $website->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);

        app(Deployer::class)->run($deployment);

        $commands = collect($runner->commands);
        $this->assertTrue($commands->contains(fn (string $line): bool => str_ends_with($line, '22 bash -c yarn install --immutable')));
        $this->assertTrue($commands->contains(fn (string $line): bool => str_ends_with($line, '22 bash -c yarn build --mode production')));
        $this->assertSame('failed', $deployment->fresh()->status);
        $this->assertStringContainsString('no output folder with files was found', (string) $deployment->fresh()->log);
    }

    public function test_a_node_server_deploy_builds_marks_the_site_built_and_needs_a_package_json(): void
    {
        $site = $this->checkout('nestjs', ['package.json' => '{}', 'pnpm-lock.yaml' => "lockfileVersion: '9.0'\n"]);
        $runner = $this->fakeRunner();

        $website = $this->website('nestjs', $site);
        $deployment = $website->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);

        app(Deployer::class)->run($deployment);

        $this->assertSame('success', $deployment->fresh()->status, (string) $deployment->fresh()->log);
        $commands = collect($runner->commands);
        $this->assertTrue($commands->contains(fn (string $line): bool => str_ends_with($line, '22 corepack pnpm install --frozen-lockfile')));
        $this->assertTrue($commands->contains(fn (string $line): bool => str_ends_with($line, '22 corepack pnpm run build')));
        $this->assertFileExists($site.'/.laravelify-built');
        $this->assertStringContainsString('Start command: node dist/main.js', (string) $deployment->fresh()->log);
        $this->assertFileDoesNotExist($site.'/public_html');

        // An Express app has no build step by default.
        $plain = $this->checkout('express', ['package.json' => '{}']);
        $runner->commands = [];
        $express = $this->website('express', $plain);
        $second = $express->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);
        app(Deployer::class)->run($second);

        $this->assertSame('success', $second->fresh()->status, (string) $second->fresh()->log);
        $this->assertFalse(collect($runner->commands)->contains(fn (string $line): bool => str_contains($line, 'run build')));
        $this->assertStringContainsString('no build step', (string) $second->fresh()->log);

        // A repository without package.json is explained, not left to a cryptic npm error.
        $empty = $this->checkout('node', []);
        $third = $this->website('node', $empty)->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);
        app(Deployer::class)->run($third);

        $this->assertSame('failed', $third->fresh()->status);
        $this->assertStringContainsString('no package.json', (string) $third->fresh()->log);
    }

    public function test_plain_php_and_static_html_deploys_need_no_node(): void
    {
        $php = $this->checkout('php', ['composer.json' => '{}', 'public/index.php' => '<?php']);
        $runner = $this->fakeRunner();
        $deployment = $this->website('php', $php, ['php_version' => '8.3'])->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);

        app(Deployer::class)->run($deployment);

        $this->assertSame('success', $deployment->fresh()->status, (string) $deployment->fresh()->log);
        $commands = collect($runner->commands);
        $this->assertTrue($commands->contains(fn (string $line): bool => str_contains($line, 'composer install --no-dev')));
        $this->assertFalse($commands->contains(fn (string $line): bool => str_contains($line, 'artisan')));
        $this->assertFalse($commands->contains(fn (string $line): bool => str_contains($line, 'npm')));

        $html = $this->checkout('static', ['index.html' => '<html>']);
        $runner->commands = [];
        $second = $this->website('static', $html)->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);
        app(Deployer::class)->run($second);

        $this->assertSame('success', $second->fresh()->status, (string) $second->fresh()->log);
        $this->assertFalse($commands->contains(fn (string $line): bool => str_contains($line, 'npm')));
        $this->assertFalse(collect($runner->commands)->contains(fn (string $line): bool => str_contains($line, 'composer') || str_contains($line, 'npm')));
        $this->assertStringContainsString('Static HTML', (string) $second->fresh()->log);
    }

    public function test_runtime_settings_save_per_type_and_the_processes_tab_only_exists_where_there_is_a_process(): void
    {
        $this->signIn();
        config(['laravelify.host_commands' => false]);

        $nest = Website::factory()->nextjs()->create(['type' => 'nestjs', 'node_version' => '22', 'port' => 3200, 'deploy_path' => $this->root.'/nest']);
        Livewire::test(WebsiteShow::class, ['website' => $nest])
            ->assertSee('Processes')
            ->set('start_command', "node a.js\nrm -rf /")
            ->call('saveRuntime')
            ->assertHasErrors('start_command')
            ->set('start_command', 'node dist/server.js')
            ->set('build_command', 'npm run build:prod')
            ->set('port', 3201)
            ->call('saveRuntime')
            ->assertHasNoErrors();

        $nest->refresh();
        $this->assertSame('node dist/server.js', $nest->start_command);
        $this->assertSame('npm run build:prod', $nest->build_command);
        $this->assertSame(3201, $nest->port);

        // Next.js keeps its own start (the standalone server), so a start command is never saved for it.
        $next = Website::factory()->nextjs()->create(['deploy_path' => $this->root.'/next']);
        Livewire::test(WebsiteShow::class, ['website' => $next])->set('start_command', 'node x.js')->call('saveRuntime');
        $this->assertNull($next->fresh()->start_command);

        $react = Website::factory()->create(['type' => 'react', 'php_version' => null, 'node_version' => '22', 'process_config' => '', 'deploy_path' => $this->root.'/react']);
        Livewire::test(WebsiteShow::class, ['website' => $react])
            ->assertDontSee('PM2 port')
            ->set('public_dir', '../etc')
            ->call('saveRuntime')
            ->assertHasErrors('public_dir')
            ->set('public_dir', 'build')
            ->set('node_version', '24')
            ->call('saveRuntime')
            ->assertHasNoErrors();

        $react->refresh();
        $this->assertSame('build', $react->public_dir);
        $this->assertSame('24', $react->node_version);
        $this->assertFalse($react->hasProcessFile());

        $php = Website::factory()->create(['type' => 'php', 'php_version' => '8.2', 'process_config' => '', 'deploy_path' => $this->root.'/php']);
        Livewire::test(WebsiteShow::class, ['website' => $php])
            ->set('php_version', '8.4')
            ->set('public_dir', 'web')
            ->call('saveRuntime')
            ->assertHasNoErrors();

        $this->assertSame(['8.4', 'web'], [$php->fresh()->php_version, $php->fresh()->public_dir]);

        $this->get(route('websites.show', $react))->assertOk()->assertSee('React')->assertDontSee('Supervisor');
        $this->get(route('groups.show', $react->group))->assertOk()->assertSee('React')->assertSee('Static (built with Node 24)');
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    /**
     * A site folder as a deploy would find it after the checkout: a .git folder and these files.
     *
     * @param  array<string, string>  $files
     */
    private function checkout(string $name, array $files): string
    {
        $site = $this->root.'/'.$name.'-'.uniqid();
        File::ensureDirectoryExists($site.'/.git');

        foreach ($files as $file => $contents) {
            File::ensureDirectoryExists(dirname($site.'/'.$file));
            File::put($site.'/'.$file, $contents);
        }

        return $site;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function website(string $type, string $site, array $attributes = []): Website
    {
        return Website::factory()->create(array_merge([
            'type' => $type,
            'deploy_path' => $site,
            'node_version' => SiteTypes::get($type)['runtime'] === 'php' || $type === 'static' ? null : '22',
            'php_version' => SiteTypes::get($type)['runtime'] === 'php' ? '8.3' : null,
            'port' => SiteTypes::get($type)['runtime'] === 'node' ? 3000 : null,
            'process_config' => '',
            'env_content' => "APP_ENV=production\n",
        ], $attributes));
    }

    private function fakeRunner(): CommandRunner
    {
        $runner = new class extends CommandRunner
        {
            /** @var list<string> */
            public array $commands = [];

            public function supportsHostCommands(): bool
            {
                return true;
            }

            public function run(array $command, ?string $cwd = null): CommandResult
            {
                $this->commands[] = implode(' ', $command);

                return new CommandResult(true, false, $command, '', 0);
            }
        };
        $this->app->instance(CommandRunner::class, $runner);

        return $runner;
    }
}
