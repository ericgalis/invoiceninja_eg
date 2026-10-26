<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Services\Clockify;

/**
 * Controlled quarter-hour rounding (largest remainder method).
 *
 * Rounds a list of durations to the nearest 15 minutes so the rounded values
 * sum EXACTLY to a target total (itself rounded half-up to a quarter hour):
 *
 *  1. Every entry keeps the floor of its quarter-hour count.
 *  2. The shortfall between the target and the sum of floors is handed out one
 *     quarter at a time to the entries with the largest leftover remainder.
 *  3. Ties go to the earlier entry in the original list.
 *
 * Works in whole seconds (remainder = seconds % 900) rather than floats, so
 * real ties are detected as real ties.
 */
class QuarterRounding
{
    public const SECONDS_PER_QUARTER = 900;

    /**
     * @param  int[]    $seconds        raw duration of each entry, in whole seconds
     * @param  int|null $targetSeconds  total to hit; null means the rounded sum of $seconds
     * @return int[]                    quarter-hour counts, same length and order as $seconds
     */
    public static function controlledRound(array $seconds, ?int $targetSeconds = null): array
    {
        $n = count($seconds);

        if ($n === 0) {
            return [];
        }

        $seconds = array_values($seconds);
        $targetQuarters = self::roundHalfUpToQuarters($targetSeconds ?? array_sum($seconds));

        $bases = array_map(fn (int $s): int => intdiv($s, self::SECONDS_PER_QUARTER), $seconds);
        $remainders = array_map(fn (int $s): int => $s % self::SECONDS_PER_QUARTER, $seconds);

        $leftover = max(0, min($n, $targetQuarters - array_sum($bases)));

        // Largest remainder first; equal remainders keep original order.
        $order = array_keys($seconds);
        usort($order, fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach (array_slice($order, 0, $leftover) as $i) {
            $bases[$i]++;
        }

        return $bases;
    }

    /**
     * Spread a client/project's rounded weekly total across the days worked.
     *
     * @param  int[] $dailySeconds one raw total per day, in date order
     * @return int[] quarter-hour counts per day
     */
    public static function weekToDays(array $dailySeconds): array
    {
        return self::controlledRound($dailySeconds);
    }

    /**
     * Spread one day's decided total across that day's items.
     *
     * @param  int[] $itemSeconds
     * @return int[] quarter-hour counts per item
     */
    public static function dayToItems(array $itemSeconds, int $dayQuarters): array
    {
        return self::controlledRound($itemSeconds, $dayQuarters * self::SECONDS_PER_QUARTER);
    }

    private static function roundHalfUpToQuarters(int $seconds): int
    {
        return intdiv($seconds + intdiv(self::SECONDS_PER_QUARTER, 2), self::SECONDS_PER_QUARTER);
    }
}
