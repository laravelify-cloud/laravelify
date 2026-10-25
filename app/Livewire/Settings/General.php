<?php

namespace App\Livewire\Settings;

use App\Livewire\PanelComponent;
use App\Models\Setting;
use App\Services\DisplayTime;
use App\Support\ServerAddress;
use Illuminate\Validation\Rule;

/**
 * Panel-wide settings: the timezone and format of every date and time (left on Automatic, each
 * person sees their browser's timezone and their country's format), and the server's address for
 * links to websites on a port.
 */
class General extends PanelComponent
{
    /** An IANA timezone, or "" to follow the browser. */
    public string $timezone = '';

    public string $date_format = DisplayTime::AUTO;

    public string $time_format = DisplayTime::AUTO;

    public bool $relative = false;

    /** The address port links use (http://address:port), "" to find it automatically. */
    public string $server_address = '';

    public function mount(DisplayTime $time): void
    {
        $settings = $time->settings();
        $this->timezone = $settings['timezone_auto'] ? '' : $settings['timezone'];
        $this->date_format = $settings['date'];
        $this->time_format = $settings['time'];
        $this->relative = $settings['relative'];
        $this->server_address = (string) Setting::getValue(ServerAddress::SETTING);
    }

    public function saveServerAddress(): void
    {
        $this->server_address = trim(preg_replace('#^https?://#i', '', $this->server_address) ?? '', " /\t\n");
        $this->validate([
            'server_address' => ['nullable', 'string', 'max:190', function (string $attribute, string $value, \Closure $fail): void {
                $host = preg_replace('/:\d+$/', '', $value);

                if ($value !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', (string) $host) !== 1) {
                    $fail('Enter an IP address like 192.168.1.20, or a host name like server.lan.');
                }
            }],
        ]);
        $this->server_address = (string) preg_replace('/:\d+$/', '', $this->server_address);

        Setting::putValue(ServerAddress::SETTING, $this->server_address);
        $this->toast($this->server_address === '' ? 'The server address is found automatically again.' : 'Port links now use '.$this->server_address.'.');
    }

    public function save(DisplayTime $time): void
    {
        $this->validate([
            'timezone' => ['nullable', 'string', Rule::in(['', ...\DateTimeZone::listIdentifiers()])],
            'date_format' => ['required', Rule::in([DisplayTime::AUTO, ...array_keys(DisplayTime::DATE_FORMATS)])],
            'time_format' => ['required', Rule::in([DisplayTime::AUTO, ...array_keys(DisplayTime::TIME_FORMATS)])],
        ], [
            'timezone.in' => 'Pick a timezone from the list.',
        ]);

        Setting::putValue('display_timezone', (string) $this->timezone);
        Setting::putValue('display_date_format', $this->date_format);
        Setting::putValue('display_time_format', $this->time_format);
        Setting::putValue('display_relative', $this->relative ? '1' : '0');
        $time->forget();

        // A full reload so every page, chart, and live feed picks up the new format.
        $this->toastAfterRedirect('Date and time settings saved.');
        $this->redirectRoute('settings.general', navigate: false);
    }

    public function render(DisplayTime $time)
    {
        $preview = $time->using($time->resolve((string) $this->timezone, $this->date_format, $this->time_format, $this->relative));
        $settings = $preview->settings();
        $now = now();

        return view('livewire.settings.general', [
            'timezones' => DisplayTime::timezones(),
            'detectedAddress' => ServerAddress::detected(),
            'localIps' => ServerAddress::localIps(),
            'browserZone' => $settings['browser_timezone'],
            'browserLocale' => str_replace('_', '-', $settings['locale']),
            'autoExample' => $preview->autoExample(),
            'shownZone' => $settings['timezone'],
            'preview' => [
                'datetime' => $preview->datetime($now),
                'date' => $preview->date($now),
                'time' => $preview->time($now),
                'earlier' => $preview->ago($now->copy()->subMinutes(5)),
                'offset' => $now->copy()->setTimezone($settings['timezone'])->format('P'),
            ],
        ])->layout('layouts.app')->title('General settings');
    }
}
