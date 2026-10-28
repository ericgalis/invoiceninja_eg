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

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Rolls raw Clockify time entries up into one item per client, project, day
 * and description, rounded to quarter hours.
 *
 * Rounding happens in two stages, per client/project and per week:
 *  1. the week's rounded total is spread across the days worked, then
 *  2. each day's total is spread across that day's distinct descriptions.
 *
 * Each entry is an array with: start (DateTimeInterface), end
 * (DateTimeInterface), description, client_name, project_name,
 * clockify_task_name.
 */
class TimeEntryRollup
{
    public const NO_CLIENT = '(no client)';

    public const NO_PROJECT = '(no project)';

    /**
     * @param int $weekStartIso first day of the week, ISO-8601 (1 = Monday ... 7 = Sunday)
     */
    public function __construct(
        private DateTimeZone $timezone,
        private int $weekStartIso = 1,
    ) {}

    /**
     * @param  array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>> one job per task to create, sorted by client, project, date, start
     */
    public function process(array $entries): array
    {
        $jobs = [];

        foreach ($this->group($entries) as $group) {
            $days = $group['days'];
            ksort($days);

            $dates = array_keys($days);
            $rawDayTotals = array_map(fn (array $day): int => array_sum(array_column($day, 'seconds')), $days);
            $dayQuarters = QuarterRounding::weekToDays(array_values($rawDayTotals));

            foreach ($dates as $i => $date) {
                $items = $this->mergeByDescription($days[$date]);
                $itemQuarters = QuarterRounding::dayToItems(array_column($items, 'seconds'), $dayQuarters[$i]);

                foreach ($items as $j => $item) {
                    $hours = $itemQuarters[$j] / 4;
                    $end = $item['start']->add(new DateInterval('PT' . (int) ($itemQuarters[$j] * QuarterRounding::SECONDS_PER_QUARTER) . 'S'));

                    $jobs[] = [
                        'client_name' => $group['client'],
                        'project_name' => $group['project'],
                        'date' => $date,
                        'description' => $item['description'],
                        'raw_seconds' => $item['seconds'],
                        'rounded_quarters' => $itemQuarters[$j],
                        'rounded_hours' => $hours,
                        'source_count' => $item['source_count'],
                        'start' => $item['start'],
                        'end' => $end,
                        'day_target_quarters' => $dayQuarters[$i],
                        'week_start' => $group['week_start'],
                    ];
                }
            }
        }

        usort($jobs, fn (array $a, array $b): int => [$a['client_name'], $a['project_name'], $a['date'], $a['start']]
            <=> [$b['client_name'], $b['project_name'], $b['date'], $b['start']]);

        return $jobs;
    }

    /**
     * Local calendar date (Y-m-d) of the week a date falls in, as that
     * week's first day.
     */
    public function weekStart(string $date): string
    {
        $d = new DateTimeImmutable($date, $this->timezone);
        $back = ((int) $d->format('N') - $this->weekStartIso + 7) % 7;

        return $d->modify("-{$back} days")->format('Y-m-d');
    }

    /**
     * client/project/week => days => entries, each entry annotated with its
     * local start and whole-second duration, sorted by start within a day.
     *
     * @param  array<int, array<string, mixed>> $entries
     * @return array<string, array{client: string, project: string, week_start: string, days: array<string, array<int, array<string, mixed>>>}>
     */
    private function group(array $entries): array
    {
        $groups = [];

        foreach ($entries as $e) {
            $start = DateTimeImmutable::createFromInterface($e['start'])->setTimezone($this->timezone);
            $seconds = $e['end']->getTimestamp() - $e['start']->getTimestamp();

            if ($seconds <= 0) {
                continue;
            }

            $client = $e['client_name'] ?: self::NO_CLIENT;
            $project = $e['project_name'] ?: self::NO_PROJECT;
            $date = $start->format('Y-m-d');
            $week = $this->weekStart($date);
            $key = $client . "\x1f" . $project . "\x1f" . $week;

            $groups[$key] ??= ['client' => $client, 'project' => $project, 'week_start' => $week, 'days' => []];
            $groups[$key]['days'][$date][] = [
                'start' => $start,
                'seconds' => $seconds,
                'label' => trim((string) ($e['description'] ?? '')) ?: trim((string) ($e['clockify_task_name'] ?? '')) ?: '(no description)',
            ];
        }

        foreach ($groups as &$group) {
            foreach ($group['days'] as &$dayEntries) {
                usort($dayEntries, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            }
        }

        return $groups;
    }

    /**
     * Collapse a day's entries sharing a description (case-insensitive) into
     * one item, in order of first appearance, summing durations.
     *
     * @param  array<int, array<string, mixed>> $dayEntries
     * @return array<int, array<string, mixed>>
     */
    private function mergeByDescription(array $dayEntries): array
    {
        $items = [];

        foreach ($dayEntries as $e) {
            $key = mb_strtolower($e['label']);

            if (! isset($items[$key])) {
                $items[$key] = ['description' => $e['label'], 'seconds' => 0, 'start' => $e['start'], 'source_count' => 0];
            }

            $items[$key]['seconds'] += $e['seconds'];
            $items[$key]['source_count']++;
        }

        return array_values($items);
    }
}
