<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\Deployment;
use App\Models\GithubApp;
use App\Models\User;
use App\Models\Website;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class GithubAppDeployTest extends TestCase
{
    use RefreshDatabase;

    private GithubApp $githubApp;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->githubApp = GithubApp::query()->create([
            'name' => 'swift-otter',
            'slug' => 'swift-otter',
            'html_url' => 'https://github.com',
            'api_url' => 'https://api.github.com',
            'git_user' => 'git',
            'git_port' => 22,
            'app_id' => '123',
            'installation_id' => '456',
            'webhook_secret' => 'hook-secret',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIB\n-----END RSA PRIVATE KEY-----",
        ]);
        $this->website = Website::factory()->create([
            'repository' => 'https://github.com/acme/shop.git',
            'branch' => 'main',
            'github_app_id' => $this->githubApp->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(string $event, array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', route('hooks.github-app', $this->githubApp), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
        ], $body);
    }

    public function test_a_push_through_the_app_deploys_without_a_repository_webhook(): void
    {
        $this->deliver('push', ['ref' => 'refs/heads/main', 'after' => str_repeat('a', 40), 'repository' => ['full_name' => 'acme/shop']])
            ->assertOk()
            ->assertJson(['ignored' => false]);

        $this->assertSame(1, Deployment::query()->where('website_id', $this->website->id)->count());
    }

    public function test_other_app_events_do_not_replace_the_deploy_status(): void
    {
        $this->website->recordWebhook('Queued deploy #7 for abc1234.');

        $this->deliver('pull_request', ['action' => 'opened', 'repository' => ['full_name' => 'acme/shop']])
            ->assertOk()
            ->assertJson(['ignored' => true]);

        $this->assertSame('Queued deploy #7 for abc1234.', $this->website->fresh()->last_webhook_result);
        $this->assertSame(0, Deployment::query()->count());
    }

    public function test_the_website_page_says_deploys_are_automatic_through_the_app(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));

        Livewire::test(WebsiteShow::class, ['website' => $this->website->fresh()])
            ->assertSee('Automatic')
            ->assertSee('Set up by the GitHub App swift-otter')
            ->assertSee('Use a repository webhook instead')
            ->assertDontSee('Waiting for GitHub');
    }
}
