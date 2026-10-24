<?php

namespace Tests\Feature;

use App\Jobs\RunDeployment;
use App\Livewire\Websites\Show;
use App\Models\Deployment;
use App\Models\PanelTask;
use App\Models\User;
use App\Models\Website;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class BackgroundWorkTest extends TestCase
{
    use RefreshDatabase;

    private function deployment(Website $website): Deployment
    {
        return $website->deployments()->create(['status' => 'queued', 'trigger' => 'manual', 'log' => '']);
    }

    public function test_deploys_go_to_their_own_queue(): void
    {
        $deployment = $this->deployment(Website::factory()->create());

        $this->assertSame(RunDeployment::QUEUE, (new RunDeployment($deployment->id))->queue);
    }

    public function test_two_deploys_of_one_website_never_run_at_once_but_other_websites_do(): void
    {
        $website = Website::factory()->create();
        $first = (new RunDeployment($this->deployment($website)->id))->middleware()[0];
        $second = (new RunDeployment($this->deployment($website)->id))->middleware()[0];
        $other = (new RunDeployment($this->deployment(Website::factory()->create())->id))->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $first);
        $this->assertSame($first->key, $second->key);
        $this->assertNotSame($first->key, $other->key);
    }

    public function test_a_long_deploy_is_never_handed_to_a_second_worker(): void
    {
        $this->assertGreaterThan((new RunDeployment(1))->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan(14400, config('queue.connections.database.retry_after'));
    }

    public function test_saving_domains_applies_them_in_the_background(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $website = Website::factory()->create(['domain' => 'shop.example.com']);

        Livewire::test(Show::class, ['website' => $website])
            ->set('aliases_text', 'www.shop.example.com')
            ->call('saveGeneral')
            ->assertHasNoErrors()
            ->assertSet('followed', [PanelTask::query()->sole()->id]);

        $task = PanelTask::query()->sole();
        $this->assertSame('websites.domains', $task->kind);
        $this->assertSame('shop.example.com', $task->payload['domain']);
        $this->assertSame(['www.shop.example.com'], $task->payload['aliases']);
    }

    public function test_a_mistake_in_redirects_shows_at_once_and_starts_nothing(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $website = Website::factory()->create(['domain' => 'shop.example.com']);

        Livewire::test(Show::class, ['website' => $website])
            ->set('redirect_rows', [['from' => 'not a domain!', 'to' => '', 'code' => 301, 'keep_path' => true, 'match' => 'exact', 'via' => 'tunnel']])
            ->call('saveRedirects')
            ->assertHasErrors('redirects.0');

        $this->assertSame(0, PanelTask::query()->count());
    }
}
