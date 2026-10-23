<?php

namespace Tests\Feature;

use App\Livewire\Websites\Form;
use App\Models\GithubApp;
use App\Models\Group;
use App\Models\User;
use App\Services\GithubAppClient;
use App\Services\TwoFactor;
use App\Support\ProjectDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['laravelify.php_versions' => ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5'], 'laravelify.node_versions' => ['20', '22', '24']]);
    }

    public function test_a_laravel_app_gets_its_stack_and_the_newest_php_it_allows(): void
    {
        $found = ProjectDetector::detect([
            'composer' => json_encode(['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0', 'livewire/livewire' => '^3.0']]),
            'package' => json_encode(['devDependencies' => ['vite' => '^6.0']]),
        ]);

        $this->assertSame(['laravel', 'livewire', '8.5', '24'], [$found['type'], $found['stack'], $found['php_version'], $found['node_version']]);
    }

    public function test_node_frameworks_are_told_apart_by_their_packages(): void
    {
        $cases = [
            [['dependencies' => ['next' => '15', 'react' => '19']], 'nextjs'],
            [['dependencies' => ['nuxt' => '3']], 'nuxt'],
            [['devDependencies' => ['@sveltejs/kit' => '2', 'svelte' => '5']], 'sveltekit'],
            [['dependencies' => ['@nestjs/core' => '10', 'express' => '4']], 'nestjs'],
            [['dependencies' => ['express' => '4']], 'express'],
            [['dependencies' => ['react' => '19'], 'devDependencies' => ['vite' => '6']], 'react'],
            [['dependencies' => ['vue' => '3']], 'vue'],
            [['scripts' => ['start' => 'node bot.js']], 'node'],
        ];

        foreach ($cases as [$package, $type]) {
            $this->assertSame($type, ProjectDetector::detect(['package' => json_encode($package)])['type'], json_encode($package));
        }

        $this->assertSame('static', ProjectDetector::detect(['index' => true])['type']);
        $this->assertSame('php', ProjectDetector::detect(['composer' => json_encode(['require' => ['php' => '~8.1.0']])])['type']);
        $this->assertNull(ProjectDetector::detect([])['type']);
    }

    public function test_versions_follow_the_projects_constraints(): void
    {
        $this->assertSame('8.1', ProjectDetector::phpVersion('~8.1.0'));
        $this->assertSame('8.3', ProjectDetector::phpVersion('8.3.*'));
        $this->assertSame('8.3', ProjectDetector::phpVersion('>=8.1 <8.4'));
        $this->assertSame('8.5', ProjectDetector::phpVersion('^7.4|^8.0'));
        $this->assertNull(ProjectDetector::phpVersion('^7.4'));

        $this->assertSame('20', ProjectDetector::nodeVersion('v20.11.1'));
        $this->assertSame('22', ProjectDetector::nodeVersion('21'));
        $this->assertSame('22', ProjectDetector::nodeVersion('^22 || ^24'));
        $this->assertSame('20', ProjectDetector::nodeVersion('>=18'));
        $this->assertSame('24', ProjectDetector::nodeVersion('lts/*'));
    }

    public function test_a_draft_comes_back_with_only_the_forms_own_fields(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));

        Livewire::test(Form::class, ['group' => Group::factory()->create()])
            ->call('restoreDraft', [
                'name' => 'Shop', 'type' => 'nextjs', 'domain' => 'shop.example.com', 'include_www' => 1, 'node_version' => '22',
                'repositories' => [['name' => 'acme/shop', 'clone_url' => 'https://github.com/acme/shop.git', 'branch' => 'main'], 'junk'],
                'group' => 99, 'type_hack' => 'x',
            ])
            ->assertSet('name', 'Shop')
            ->assertSet('type', 'nextjs')
            ->assertSet('domain', 'shop.example.com')
            ->assertSet('include_www', true)
            ->assertCount('repositories', 1)
            ->assertDispatched('type-detected', group: 'fullstack')
            ->call('restoreDraft', ['type' => 'nonsense'])
            ->assertSet('type', 'laravel');
    }

    public function test_loading_a_repository_picks_the_framework_and_versions(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $app = GithubApp::query()->create([
            'name' => 'swift-otter', 'slug' => 'swift-otter', 'html_url' => 'https://github.com', 'api_url' => 'https://api.github.com',
            'git_user' => 'git', 'git_port' => 22, 'app_id' => '123', 'installation_id' => '456', 'webhook_secret' => 'secret',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----",
        ]);
        $client = $this->mock(GithubAppClient::class);
        $client->shouldReceive('files')->once()->with(\Mockery::type(GithubApp::class), 'acme/shop', 'main', \Mockery::any())->andReturn([
            'composer.json' => null, 'package.json' => json_encode(['dependencies' => ['next' => '15'], 'engines' => ['node' => '>=22']]),
            '.nvmrc' => null, '.node-version' => null, 'index.html' => null,
        ]);

        Livewire::test(Form::class, ['group' => Group::factory()->create()])
            ->set('github_app_id', (string) $app->id)
            ->set('repositories', [['name' => 'acme/shop', 'clone_url' => 'https://github.com/acme/shop.git', 'branch' => 'main']])
            ->set('selectedRepository', 'https://github.com/acme/shop.git')
            ->call('loadRepository')
            ->assertSet('type', 'nextjs')
            ->assertSet('node_version', '22')
            ->assertSet('name', 'shop')
            ->assertDispatched('type-detected', group: 'fullstack');
    }
}
