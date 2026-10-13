<?php

namespace App\Support;

use Cron\CronExpression;
use InvalidArgumentException;

/**
 * Turns the simple schedule choices (every 15 minutes, every hour, daily at 02:00 ...)
 * into a cron expression, and describes one back in words.
 */
class BackupCron
{
    public const FREQUENCIES = [
        'minutes' => 'Every few minutes',
        'hourly' => 'Every hour',
        'hours' => 'Every few hours',
        'daily' => 'Every day',
        'weekly' => 'Every week',
        'custom' => 'Custom cron',
    ];

    public const WEEKDAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * The cron expression for a choice. Minutes and hours repeat inside the hour or day, so
     * "every 45 minutes" is every 45 minutes starting at the top of each hour.
     */
    public static function build(string $frequency, int $interval, string $runTime, int $weekday, ?string $custom = null): string
    {
        [$hour, $minute] = self::time($runTime);
        $interval = max(1, $interval);

        $cron = match ($frequency) {
            'minutes' => $interval === 1 ? '* * * * *' : '*/'.min($interval, 59).' * * * *',
            'hourly' => $minute.' * * * *',
            'hours' => $minute.' '.($interval === 1 ? '*' : '*/'.min($interval, 23)).' * * *',
            'daily' => $minute.' '.$hour.' * * *',
            'weekly' => $minute.' '.$hour.' * * '.max(0, min(6, $weekday)),
            'custom' => trim((string) $custom),
            default => throw new InvalidArgumentException('Choose how often to run.'),
        };

        if (! self::valid($cron)) {
            throw new InvalidArgumentException('That is not a valid cron expression.');
        }

        return $cron;
    }

    public static function valid(string $cron): bool
    {
        return preg_match('/^\S+( \S+){4}$/', trim($cron)) === 1 && CronExpression::isValidExpression(trim($cron));
    }

    public static function isDue(string $cron, ?string $timezone = null): bool
    {
        return (new CronExpression($cron))->isDue('now', $timezone);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function time(string $runTime): array
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($runTime), $match)) {
            throw new InvalidArgumentException('Use a time like 02:30.');
        }

        return [(int) $match[1], (int) $match[2]];
    }

    public static function describe(string $frequency, int $interval, string $runTime, int $weekday, ?string $cron = null): string
    {
        $at = str_pad((string) (self::time($runTime)[0] ?? 0), 2, '0', STR_PAD_LEFT).':'.substr($runTime, -2);

        return match ($frequency) {
            'minutes' => $interval <= 1 ? 'Every minute' : 'Every '.$interval.' minutes',
            'hourly' => 'Every hour at :'.substr($runTime, -2),
            'hours' => $interval <= 1 ? 'Every hour at :'.substr($runTime, -2) : 'Every '.$interval.' hours at :'.substr($runTime, -2),
            'daily' => 'Every day at '.$at,
            'weekly' => 'Every '.(self::WEEKDAYS[$weekday] ?? 'week').' at '.$at,
            default => 'Cron '.$cron,
        };
    }
}
