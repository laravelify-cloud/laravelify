<?php

namespace Tests\Feature;

use App\Livewire\Settings\General;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\DisplayTime;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DisplayTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dates_use_utc_and_iso_format_until_settings_are_saved(): void
    {
        $time = app(DisplayTime::class);
        $value = Carbon::parse('2026-10-12 09:05:07', 'UTC');

        $this->assertSame('2026-10-12 09:05', $time->datetime($value));
        $this->assertSame('—', $time->datetime(null));
        $this->assertSame('2026-10-12 09:05', $time->ago($value));
    }

    public function test_dates_follow_the_saved_timezone_and_formats(): void
    {
        Setting::putValue('display_timezone', 'Asia/Baku');
        Setting::putValue('display_date_format', 'd.m.Y');
        Setting::putValue('display_time_format', 'g:i:s A');

        $time = app(DisplayTime::class);
        $value = Carbon::parse('2026-10-12 09:05:07', 'UTC');

        // Baku is UTC+4.
        $this->assertSame('12.10.2026 1:05:07 PM', $time->datetime($value));
        $this->assertSame('12.10.2026', $time->date($value));
        $this->assertSame('1:05:07 PM', $time->time($value));
        $this->assertSame('12.10 1:05 PM', $time->label($value));
        $this->assertSame('12.10.2026 1:05:07 PM', $time->datetime($value->getTimestamp()));
        $this->assertSame('12.10.2026 1:05:07 PM', $time->datetime('2026-10-12 09:05:07'));
        $this->assertSame(['timeZone' => 'Asia/Baku', 'hour12' => true, 'seconds' => true], $time->forBrowser());
        $this->assertSame('UTC', $value->getTimezone()->getName(), 'the original value is not changed');
    }

    public function test_relative_times_are_optional_and_unknown_values_fall_back(): void
    {
        Setting::putValue('display_timezone', 'Mars/Olympus');
        Setting::putValue('display_date_format', 'nonsense');
        Setting::putValue('display_relative', '1');

        $time = app(DisplayTime::class);

        $this->assertSame(['timezone' => 'UTC', 'date' => 'Y-m-d', 'time' => 'H:i', 'relative' => true], $time->settings());
        $this->assertSame('5 minutes ago', $time->ago(now()->subMinutes(5)));
    }

    public function test_the_settings_page_saves_and_rejects_invalid_values(): void
    {
        $this->signIn();

        $this->get(route('settings.general'))->assertOk()->assertSee('Asia/Baku')->assertSee('Date format');

        Livewire::test(General::class)
            ->set('timezone', 'Not/AZone')
            ->call('save')
            ->assertHasErrors('timezone')
            ->set('timezone', 'Europe/Istanbul')
            ->set('date_format', 'j M Y')
            ->set('time_format', 'H:i:s')
            ->set('relative', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.general'));

        $this->assertSame('Europe/Istanbul', Setting::getValue('display_timezone'));
        $this->assertSame('j M Y', Setting::getValue('display_date_format'));
        $this->assertSame('H:i:s', Setting::getValue('display_time_format'));
        $this->assertSame('1', Setting::getValue('display_relative'));
    }

    public function test_pages_show_dates_in_the_chosen_format(): void
    {
        $this->signIn();
        Setting::putValue('display_timezone', 'Asia/Baku');
        Setting::putValue('display_date_format', 'd/m/Y');
        $website = Website::factory()->create();
        $website->deployments()->create(['status' => 'success', 'trigger' => 'manual', 'log' => ''])
            ->forceFill(['created_at' => Carbon::parse('2026-10-12 20:30:00', 'UTC')])->save();

        // 20:30 UTC is 00:30 the next day in Baku.
        $this->get(route('dashboard'))->assertOk()->assertSee('13/10/2026 00:30');
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]));
    }
}
