<?php

namespace App\Services;

use App\Models\Setting;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every date and time the panel shows goes through here, in the timezone and
 * format chosen in Settings → General. Stored times stay in the app timezone (UTC);
 * only the display changes.
 */
class DisplayTime
{
    /**
     * Date format => example.
     *
     * @var array<string, string>
     */
    public const DATE_FORMATS = [
        'Y-m-d' => '2026-10-12',
        'd.m.Y' => '12.10.2026',
        'd/m/Y' => '12/10/2026',
        'm/d/Y' => '10/12/2026',
        'j M Y' => '12 Oct 2026',
        'M j, Y' => 'Oct 12, 2026',
        'D, j M Y' => 'Mon, 12 Oct 2026',
        'l, F j, Y' => 'Monday, October 12, 2026',
    ];

    /**
     * The same dates without the year, for chart labels.
     *
     * @var array<string, string>
     */
    private const SHORT_DATES = [
        'Y-m-d' => 'm-d',
        'd.m.Y' => 'd.m',
        'd/m/Y' => 'd/m',
        'm/d/Y' => 'm/d',
        'j M Y' => 'j M',
        'M j, Y' => 'M j',
        'D, j M Y' => 'D j M',
        'l, F j, Y' => 'M j',
    ];

    /**
     * Time format => label.
     *
     * @var array<string, string>
     */
    public const TIME_FORMATS = [
        'H:i' => '24-hour (14:05)',
        'H:i:s' => '24-hour with seconds (14:05:09)',
        'g:i A' => '12-hour (2:05 PM)',
        'g:i:s A' => '12-hour with seconds (2:05:09 PM)',
    ];

    /**
     * @var array{timezone: string, date: string, time: string, relative: bool}|null
     */
    private ?array $settings = null;

    /**
     * @return array{timezone: string, date: string, time: string, relative: bool}
     */
    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $values = [];

        try {
            $values = Setting::query()
                ->whereIn('key', ['display_timezone', 'display_date_format', 'display_time_format', 'display_relative'])
                ->get()
                ->mapWithKeys(fn (Setting $setting): array => [$setting->key => $setting->value])
                ->all();
        } catch (Throwable) {
            // No database yet (first install): fall back to the defaults.
        }

        $timezone = (string) ($values['display_timezone'] ?? '');
        $date = (string) ($values['display_date_format'] ?? '');
        $time = (string) ($values['display_time_format'] ?? '');

        return $this->settings = [
            'timezone' => self::validTimezone($timezone) ? $timezone : (string) config('app.timezone', 'UTC'),
            'date' => array_key_exists($date, self::DATE_FORMATS) ? $date : 'Y-m-d',
            'time' => array_key_exists($time, self::TIME_FORMATS) ? $time : 'H:i',
            'relative' => ($values['display_relative'] ?? '0') === '1',
        ];
    }

    public function forget(): void
    {
        $this->settings = null;
    }

    public static function validTimezone(string $timezone): bool
    {
        return $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * A copy of the value in the display timezone, or null.
     */
    public function at(DateTimeInterface|string|int|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $time = match (true) {
                is_int($value) => Carbon::createFromTimestamp($value),
                $value instanceof DateTimeInterface => Carbon::instance($value),
                default => Carbon::parse($value, config('app.timezone')),
            };
        } catch (Throwable) {
            return null;
        }

        return $time->copy()->setTimezone($this->settings()['timezone']);
    }

    public function datetime(DateTimeInterface|string|int|null $value): string
    {
        $settings = $this->settings();

        return $this->at($value)?->format($settings['date'].' '.$settings['time']) ?? '—';
    }

    public function date(DateTimeInterface|string|int|null $value): string
    {
        return $this->at($value)?->format($this->settings()['date']) ?? '—';
    }

    public function time(DateTimeInterface|string|int|null $value): string
    {
        return $this->at($value)?->format($this->settings()['time']) ?? '—';
    }

    /**
     * Hours and minutes in the chosen style, without seconds (chart axes).
     */
    public function clock(DateTimeInterface|string|int|null $value): string
    {
        return $this->at($value)?->format(str_replace(':s', '', $this->settings()['time'])) ?? '—';
    }

    /**
     * Short date and clock for chart labels, like "10-12 14:30" or "Oct 12, 2:30 PM".
     */
    public function label(DateTimeInterface|string|int|null $value, bool $withDate = true): string
    {
        $time = $this->at($value);

        if ($time === null) {
            return '—';
        }

        $clock = str_replace(':s', '', $this->settings()['time']);

        return $time->format($withDate ? self::SHORT_DATES[$this->settings()['date']].' '.$clock : $clock);
    }

    /**
     * "5 minutes ago" when relative times are on, otherwise the full date and time.
     */
    public function ago(DateTimeInterface|string|int|null $value, bool $short = false): string
    {
        $time = $this->at($value);

        if ($time === null) {
            return '—';
        }

        return $this->settings()['relative'] ? $time->diffForHumans(short: $short) : $this->datetime($time);
    }

    /**
     * What the browser needs to format live times the same way.
     *
     * @return array{timeZone: string, hour12: bool, seconds: bool}
     */
    public function forBrowser(): array
    {
        $settings = $this->settings();

        return [
            'timeZone' => $settings['timezone'],
            'hour12' => str_contains($settings['time'], 'A'),
            'seconds' => str_contains($settings['time'], ':s'),
        ];
    }

    /**
     * All timezones grouped by region, with their current UTC offset.
     *
     * @return array<string, array<string, string>>
     */
    public static function timezones(): array
    {
        $groups = [];
        $now = new \DateTimeImmutable('now');

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $offset = (new DateTimeZone($identifier))->getOffset($now);
            $sign = $offset < 0 ? '-' : '+';
            $label = sprintf('(UTC%s%02d:%02d) %s', $sign, intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60), str_replace('_', ' ', $identifier));
            $region = str_contains($identifier, '/') ? strstr($identifier, '/', true) : 'Other';
            $groups[$region][$identifier] = $label;
        }

        ksort($groups);

        return $groups;
    }
}
