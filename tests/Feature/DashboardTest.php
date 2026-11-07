<?php

namespace Tests\Feature;

use App\Models\CloudflareTunnel;
use App\Models\Group;
use App\Models\ServerSample;
use App\Models\User;
use App\Models\Website;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }

    public function test_a_new_panel_shows_the_get_started_steps_with_the_first_one_next(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Get started')
            ->assertSee('0 of 4 steps done')
            ->assertSee(route('groups.create'))
            ->assertSee('No deployments yet');
    }

    public function test_the_next_step_after_a_group_links_to_adding_a_website_to_it(): void
    {
        $group = Group::factory()->create();

        $this->get(route('dashboard'))
            ->assertSee('1 of 4 steps done')
            ->assertSee(route('websites.create', $group));
    }

    public function test_get_started_is_gone_once_every_step_is_done(): void
    {
        $website = Website::factory()->create();
        $website->deployments()->create(['status' => 'success', 'trigger' => 'manual', 'log' => '']);
        CloudflareTunnel::factory()->create();

        $this->get(route('dashboard'))->assertOk()->assertDontSee('Get started');
    }

    public function test_deploys_of_the_last_week_show_their_success_rate_and_failures(): void
    {
        $website = Website::factory()->create();
        foreach (['success', 'success', 'success', 'failed', 'running'] as $status) {
            $website->deployments()->create(['status' => $status, 'trigger' => 'push', 'log' => '']);
        }
        $old = $website->deployments()->create(['status' => 'failed', 'trigger' => 'push', 'log' => '']);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->get(route('dashboard'))
            ->assertSeeInOrder(['Deploys · 7 days', '5', '75% succeeded', '1 failed']);
    }

    public function test_server_usage_shows_only_a_recent_sample(): void
    {
        ServerSample::factory()->create(['at' => now()->subHour(), 'cpu' => 12, 'memory' => 34, 'disk' => 56]);

        $this->get(route('dashboard'))->assertSee('No recent samples');

        ServerSample::factory()->create(['at' => now()->subMinute(), 'cpu' => 12, 'memory' => 34, 'disk' => 91]);

        $this->get(route('dashboard'))
            ->assertDontSee('No recent samples')
            ->assertSeeInOrder(['CPU', '12%', 'Memory', '34%', 'Disk', '91%']);
    }
}
