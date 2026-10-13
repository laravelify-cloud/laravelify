<?php

namespace App\Services;

/**
 * Response times counted into fixed buckets. Minutes merge by adding counts,
 * so p95 over any range comes from the stored per-minute histograms.
 */
class LatencyHistogram
{
    /**
     * Upper bound of each bucket in milliseconds. One more bucket holds anything slower.
     *
     * @var list<int>
     */
    public const EDGES = [25, 50, 100, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000, 10000];

    /**
     * @return list<int>
     */
    public static function empty(): array
    {
        return array_fill(0, count(self::EDGES) + 1, 0);
    }

    /**
     * @param  list<int>  $histogram
     * @return list<int>
     */
    public static function add(array $histogram, int $milliseconds): array
    {
        $histogram = self::normalize($histogram);
        $index = count(self::EDGES);

        foreach (self::EDGES as $position => $edge) {
            if ($milliseconds <= $edge) {
                $index = $position;

                break;
            }
        }

        $histogram[$index]++;

        return $histogram;
    }

    /**
     * @param  list<int>|null  $first
     * @param  list<int>|null  $second
     * @return list<int>
     */
    public static function merge(?array $first, ?array $second): array
    {
        $first = self::normalize($first);
        $second = self::normalize($second);

        foreach ($second as $index => $count) {
            $first[$index] += $count;
        }

        return $first;
    }

    /**
     * The percentile in milliseconds, interpolated inside its bucket. Null when empty.
     *
     * @param  list<int>|null  $histogram
     */
    public static function percentile(?array $histogram, float $percentile, int $max = 0): ?int
    {
        $histogram = self::normalize($histogram);
        $total = array_sum($histogram);

        if ($total === 0) {
            return null;
        }

        $target = max(1, (int) ceil($total * $percentile));
        $seen = 0;

        foreach ($histogram as $index => $count) {
            if ($count === 0 || $seen + $count < $target) {
                $seen += $count;

                continue;
            }

            $lower = $index === 0 ? 0 : self::EDGES[$index - 1];
            $upper = self::EDGES[$index] ?? max($max, $lower);
            $value = (int) round($lower + ($upper - $lower) * (($target - $seen) / $count));

            return $max > 0 ? min($value, $max) : $value;
        }

        return $max ?: null;
    }

    /**
     * @param  array<int|string, mixed>|null  $histogram
     * @return list<int>
     */
    private static function normalize(?array $histogram): array
    {
        $normalized = self::empty();

        foreach (array_values($histogram ?? []) as $index => $count) {
            if (array_key_exists($index, $normalized)) {
                $normalized[$index] = (int) $count;
            }
        }

        return $normalized;
    }
}
