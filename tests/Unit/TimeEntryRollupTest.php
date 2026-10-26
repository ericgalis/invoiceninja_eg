<?php

namespace Tests\Unit;

use App\Services\Clockify\TimeEntryRollup;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class TimeEntryRollupTest extends TestCase
{
    private function entry(string $start, int $minutes, string $desc, string $client = 'Acme Test Co', ?string $project = 'Alpha'): array
    {
        $s = new DateTimeImmutable($start);

        return [
            'start' => $s,
            'end' => $s->modify("+{$minutes} minutes"),
            'description' => $desc,
            'client_name' => $client,
            'project_name' => $project,
            'clockify_task_name' => null,
        ];
    }

    private function rollup(): TimeEntryRollup
    {
        return new TimeEntryRollup(new DateTimeZone('America/New_York'), 1);
    }

    public function testSameDescriptionOnOneDayMergesCaseInsensitively(): void
    {
        $jobs = $this->rollup()->process([
            $this->entry('2026-08-24T09:00:00-04:00', 20, 'Review logs'),
            $this->entry('2026-08-24T11:00:00-04:00', 25, 'review LOGS'),
        ]);

        $this->assertCount(1, $jobs);
        $this->assertSame('Review logs', $jobs[0]['description']);
        $this->assertSame(2700, $jobs[0]['raw_seconds']);
        $this->assertSame(3, $jobs[0]['rounded_quarters']);
        $this->assertSame(2, $jobs[0]['source_count']);
        $this->assertSame('2026-08-24T09:45:00-04:00', $jobs[0]['end']->format('c'));
    }

    public function testDaySplitUsesCompanyTimezone(): void
    {
        // 23:30 UTC on the 24th is 19:30 on the 24th in New York; 03:30 UTC on
        // the 25th is still the 24th at 23:30 local.
        $jobs = $this->rollup()->process([
            $this->entry('2026-08-25T03:30:00+00:00', 30, 'Late work'),
        ]);

        $this->assertSame('2026-08-24', $jobs[0]['date']);
    }

    public function testWeeksRoundIndependently(): void
    {
        // Sunday 8/23 and Monday 8/24 fall in different Monday-start weeks.
        $jobs = $this->rollup()->process([
            $this->entry('2026-08-23T10:00:00-04:00', 10, 'A'),
            $this->entry('2026-08-24T10:00:00-04:00', 10, 'A'),
        ]);

        $this->assertCount(2, $jobs);
        $this->assertNotSame($jobs[0]['week_start'], $jobs[1]['week_start']);
        // 10 min rounds to 1 quarter (rounds half up at 7.5 min) in each separate week.
        $this->assertSame([1, 1], array_column($jobs, 'rounded_quarters'));
    }

    public function testDaysInAWeekSumToRoundedWeekTotal(): void
    {
        $jobs = $this->rollup()->process([
            $this->entry('2026-08-24T09:00:00-04:00', 110, 'A'),
            $this->entry('2026-08-25T09:00:00-04:00', 130, 'A'),
            $this->entry('2026-08-26T09:00:00-04:00', 50, 'A'),
        ]);

        $this->assertSame([7, 9, 3], array_column($jobs, 'rounded_quarters'));
    }

    public function testMissingClientAndProjectGetPlaceholders(): void
    {
        $jobs = $this->rollup()->process([
            $this->entry('2026-08-24T09:00:00-04:00', 30, 'X', '', null),
        ]);

        $this->assertSame(TimeEntryRollup::NO_CLIENT, $jobs[0]['client_name']);
        $this->assertSame(TimeEntryRollup::NO_PROJECT, $jobs[0]['project_name']);
    }
}
