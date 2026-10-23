<?php

namespace Tests\Feature;

use App\Livewire\PanelWorkerAlert;
use App\Models\User;
use App\Services\HostWriter;
use App\Services\PanelWorker;
use App\Services\Telegram;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class PanelWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // As on a server: the worker runs under Supervisor and beats.
        config(['laravelify.host_commands' => true]);
    }

    public function test_the_worker_counts_as_running_only_while_it_beats(): void
    {
        $worker = app(PanelWorker::class);
        $this->assertFalse($worker->running());

        PanelWorker::beat();
        $this->assertTrue($worker->running());

        Cache::forever('panel-worker:beat', time() - PanelWorker::STALE_SECONDS - 5);
        $this->assertFalse($worker->running());
    }

    public function test_the_watchdog_starts_a_stopped_worker_and_reports_it_once_then_its_return(): void
    {
        $this->mock(HostWriter::class, fn (MockInterface $host) => $host->shouldReceive('startPanelWorker')->twice()->andReturn('laravelify-queue: started'));
        $this->mock(Telegram::class, function (MockInterface $telegram) {
            $telegram->shouldReceive('notify')->once()->withArgs(fn ($event, $html) => $event === 'process_crashed' && str_contains($html, 'Panel worker stopped'));
            $telegram->shouldReceive('notify')->once()->withArgs(fn ($event, $html) => $event === 'process_recovered' && str_contains($html, 'running again'));
        });

        // Down for two minutes in a row: started both times, reported once.
        $this->artisan('laravelify:watch-worker')->assertSuccessful();
        $this->artisan('laravelify:watch-worker')->assertSuccessful();

        Cache::forever('panel-worker:beat', time());
        $this->artisan('laravelify:watch-worker')->assertSuccessful();
        $this->artisan('laravelify:watch-worker')->assertSuccessful();
    }

    public function test_the_panel_shows_a_banner_with_a_start_button_while_the_worker_is_down(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $this->mock(HostWriter::class, fn (MockInterface $host) => $host->shouldReceive('startPanelWorker')->once()->andReturn('started'));

        Livewire::test(PanelWorkerAlert::class)
            ->assertSee('background worker is not running')
            ->call('start')
            ->assertSee('Starting…');

        Cache::forever('panel-worker:beat', time());

        Livewire::test(PanelWorkerAlert::class)->assertDontSee('background worker is not running');
    }
}
