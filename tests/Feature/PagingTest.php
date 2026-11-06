<?php

namespace Tests\Feature;

use App\Livewire\Websites\Show;
use App\Models\Deployment;
use App\Models\User;
use App\Models\Website;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
    }

    public function test_deployments_come_a_page_at_a_time(): void
    {
        $website = Website::factory()->create();
        $ids = collect(range(1, Show::DEPLOYMENTS_PER_PAGE + 5))
            ->map(fn () => Deployment::query()->create(['website_id' => $website->id, 'status' => 'success', 'trigger' => 'push', 'log' => ''])->id);
        $newest = $ids->last();
        $oldest = $ids->first();

        Livewire::withoutLazyLoading()->test(Show::class, ['website' => $website])
            ->assertSee('Page 1 of 2 · 25 deployments')
            ->assertSee('Select deployment '.$newest)
            ->assertDontSee('Select deployment '.$oldest.'"', false)
            ->call('nextPage', 'deploys')
            ->assertSee('Page 2 of 2')
            ->assertSee('Select deployment '.$oldest)
            ->assertDontSee('Select deployment '.$newest.'"', false);
    }
}
