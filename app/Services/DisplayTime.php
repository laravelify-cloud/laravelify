<?php

namespace App\Services;

use App\Models\Setting;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every date and time the panel shows goes through here. Stored times stay in UTC; only
 * the display changes.
 *
 * Out of the box everything follows the browser: its timezone and, for the format, its
 * language and country (14.10.2026 for Azerbaijani, 10/14/2026 4:44 AM for American English).
 * The browser sends both in two cookies. Settings → General can fix a timezone or a format
 * for everyone instead.
 */
class DisplayTime
{
    /** The cookies the layout script writes: the browser's timezone and language. */
    public const TIMEZONE_COOKIE = 'laravelify-tz';

    public const LOCALE_COOKIE = 'laravelify-locale';

    /** A format that follows the browser's language and country. */
    public const AUTO = 'auto';

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
     * @var array{timezone: string, timezone_auto: bool, browser_timezone: ?string, date: string, time: string, relative: bool, locale: string}|null
     */
    private ?array $settings = null;

    /**
     * @return array{timezone: string, timezone_auto: bool, browser_timezone: ?string, date: string, time: string, relative: bool, locale: string}
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

        return $this->settings = $this->resolve(
            (string) ($values['display_timezone'] ?? ''),
            (string) ($values['display_date_format'] ?? self::AUTO),
            (string) ($values['display_time_format'] ?? self::AUTO),
            ($values['display_relative'] ?? '0') === '1',
        );
    }

    /**
     * Settings as they would be with these choices, for the preview on the settings page.
     *
     * @return array{timezone: string, timezone_auto: bool, browser_timezone: ?string, date: string, time: string, relative: bool, locale: string}
     */
    public function resolve(string $timezone, string $date, string $time, bool $relative): array
    {
        $browser = $this->browserTimezone();
        $auto = ! self::validTimezone($timezone);

        return [
            'timezone' => $auto ? ($browser ?? (string) config('app.timezone', 'UTC')) : $timezone,
            'timezone_auto' => $auto,
            'browser_timezone' => $browser,
            'date' => array_key_exists($date, self::DATE_FORMATS) ? $date : self::AUTO,
            'time' => array_key_exists($time, self::TIME_FORMATS) ? $time : self::AUTO,
            'relative' => $relative,
            'locale' => $this->browserLocale(),
        ];
    }

    /**
     * Use these settings for the rest of the request (the settings page preview).
     *
     * @param  array{timezone: string, timezone_auto: bool, browser_timezone: ?string, date: string, time: string, relative: bool, locale: string}  $settings
     */
    public function using(array $settings): static
    {
        $copy = clone $this;
        $copy->settings = $settings;

        return $copy;
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
     * Whether anything follows the browser, so the page reloads once when the browser's
     * timezone or language changes.
     */
    public function followsBrowser(): bool
    {
        $settings = $this->settings();

        return $settings['timezone_auto'] || $settings['date'] === self::AUTO || $settings['time'] === self::AUTO;
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

        return $time->copy()->setTimezone($this->settings()['timezone'])->locale($this->settings()['locale']);
    }

    public function datetime(DateTimeInterface|string|int|null $value): string
    {
        $time = $this->at($value);

        return $time ? $this->formatDate($time).' '.$this->formatTime($time) : '—';
    }

    public function date(DateTimeInterface|string|int|null $value): string
    {
        $time = $this->at($value);

        return $time ? $this->formatDate($time) : '—';
    }

    public function time(DateTimeInterface|string|int|null $value): string
    {
        $time = $this->at($value);

        return $time ? $this->formatTime($time) : '—';
    }

    /**
     * Hours and minutes in the chosen style, without seconds (chart axes).
     */
    public function clock(DateTimeInterface|string|int|null $value): string
    {
        $time = $this->at($value);

        return $time ? $this->formatTime($time, false) : '—';
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

        $clock = $this->formatTime($time, false);

        if (! $withDate) {
            return $clock;
        }

        $date = $this->settings()['date'];
        $short = $date === self::AUTO ? $time->isoFormat('D MMM') : $time->format(self::SHORT_DATES[$date]);

        return $short.' '.$clock;
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
     * @return array{timeZone: string, hour12: bool, seconds: bool, locale: string}
     */
    public function forBrowser(): array
    {
        $settings = $this->settings();
        $format = $settings['time'];

        return [
            'timeZone' => $settings['timezone'],
            'hour12' => $format === self::AUTO ? $this->localeUses12Hours() : str_contains($format, 'A'),
            'seconds' => str_contains($format, ':s'),
            'locale' => str_replace('_', '-', $settings['locale']),
        ];
    }

    /**
     * An example of the automatic date and time for the browser's language.
     */
    public function autoExample(): string
    {
        $time = now()->setTimezone($this->settings()['timezone'])->locale($this->settings()['locale']);

        return $time->isoFormat('L').' '.$time->isoFormat('LT');
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
            $groups[str_contains($identifier, '/') ? strstr($identifier, '/', true) : 'Other'][$identifier] = self::zoneLabel($identifier, $now);
        }

        ksort($groups);

        return $groups;
    }

    public static function zoneLabel(string $identifier, ?\DateTimeImmutable $now = null): string
    {
        $offset = (new DateTimeZone($identifier))->getOffset($now ?? new \DateTimeImmutable('now'));
        $sign = $offset < 0 ? '-' : '+';

        return sprintf('(UTC%s%02d:%02d) %s', $sign, intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60), str_replace('_', ' ', $identifier));
    }

    private function formatDate(Carbon $time): string
    {
        $format = $this->settings()['date'];

        return $format === self::AUTO ? $time->isoFormat('L') : $time->format($format);
    }

    private function formatTime(Carbon $time, bool $seconds = true): string
    {
        $format = $this->settings()['time'];

        if ($format === self::AUTO) {
            return $time->isoFormat('LT');
        }

        return $time->format($seconds ? $format : str_replace(':s', '', $format));
    }

    private function localeUses12Hours(): bool
    {
        return ! str_contains(Carbon::create(2026, 1, 1, 13, 0)->locale($this->settings()['locale'])->isoFormat('LT'), '13');
    }

    private function browserTimezone(): ?string
    {
        $value = (string) $this->cookie(self::TIMEZONE_COOKIE);

        return self::validTimezone($value) ? $value : null;
    }

    /**
     * The browser's language, like "az_AZ" or "en_US"; English when unknown.
     */
    private function browserLocale(): string
    {
        $value = str_replace('-', '_', (string) $this->cookie(self::LOCALE_COOKIE));

        return preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $value) ? $value : 'en';
    }

    private function cookie(string $name): ?string
    {
        try {
            $value = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->cookie($name);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
