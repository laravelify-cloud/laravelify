<?php

namespace App\Livewire\Settings;

use App\Livewire\PanelComponent;
use App\Models\Setting;
use App\Services\DisplayTime;
use Illuminate\Validation\Rule;

/**
 * Panel-wide display settings: the timezone and format of every date and time.
 */
class General extends PanelComponent
{
    public string $timezone = 'UTC';

    public string $date_format = 'Y-m-d';

    public string $time_format = 'H:i';

    public bool $relative = false;

    public function mount(DisplayTime $time): void
    {
        $settings = $time->settings();
        $this->timezone = $settings['timezone'];
        $this->date_format = $settings['date'];
        $this->time_format = $settings['time'];
        $this->relative = $settings['relative'];
    }

    public function save(DisplayTime $time): void
    {
        $this->validate([
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'date_format' => ['required', Rule::in(array_keys(DisplayTime::DATE_FORMATS))],
            'time_format' => ['required', Rule::in(array_keys(DisplayTime::TIME_FORMATS))],
        ], [
            'timezone.in' => 'Pick a timezone from the list.',
        ]);

        Setting::putValue('display_timezone', $this->timezone);
        Setting::putValue('display_date_format', $this->date_format);
        Setting::putValue('display_time_format', $this->time_format);
        Setting::putValue('display_relative', $this->relative ? '1' : '0');
        $time->forget();

        // A full reload so every page, chart, and live feed picks up the new format.
        $this->toastAfterRedirect('Date and time settings saved.');
        $this->redirectRoute('settings.general', navigate: false);
    }

    public function render()
    {
        $now = now()->setTimezone(DisplayTime::validTimezone($this->timezone) ? $this->timezone : 'UTC');
        $date = array_key_exists($this->date_format, DisplayTime::DATE_FORMATS) ? $this->date_format : 'Y-m-d';
        $time = array_key_exists($this->time_format, DisplayTime::TIME_FORMATS) ? $this->time_format : 'H:i';

        return view('livewire.settings.general', [
            'timezones' => DisplayTime::timezones(),
            'preview' => [
                'datetime' => $now->format($date.' '.$time),
                'date' => $now->format($date),
                'time' => $now->format($time),
                'earlier' => $this->relative ? $now->copy()->subMinutes(5)->diffForHumans() : $now->copy()->subMinutes(5)->format($date.' '.$time),
                'offset' => $now->format('P'),
            ],
        ])->layout('layouts.app')->title('General settings');
    }
}
