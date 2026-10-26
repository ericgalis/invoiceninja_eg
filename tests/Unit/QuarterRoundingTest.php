<?php

namespace Tests\Unit;

use App\Services\Clockify\QuarterRounding;
use PHPUnit\Framework\TestCase;

class QuarterRoundingTest extends TestCase
{
    private function mins(int ...$m): array
    {
        return array_map(fn (int $x): int => $x * 60, $m);
    }

    public function testEmptyListReturnsEmpty(): void
    {
        $this->assertSame([], QuarterRounding::controlledRound([]));
    }

    public function testExactQuartersAreUnchanged(): void
    {
        $this->assertSame([2, 4], QuarterRounding::controlledRound($this->mins(30, 60)));
    }

    public function testResultsSumToRoundedTotal(): void
    {
        // 50 + 50 + 50 min = 150 min = 10 quarters; floors give 3+3+3 = 9, one bump.
        $out = QuarterRounding::controlledRound($this->mins(50, 50, 50));

        $this->assertSame(10, array_sum($out));
    }

    public function testExactTiesGoToEarlierEntry(): void
    {
        // 50 min and 110 min leave the same exact 1/3 remainder pattern;
        // 3 x 50 min has three identical remainders, so the first entry wins.
        $this->assertSame([4, 3, 3], QuarterRounding::controlledRound($this->mins(50, 50, 50)));
    }

    public function testLargestRemainderGetsTheBump(): void
    {
        // 20 min (1.33q, rem 300s) and 25 min (1.67q, rem 600s); sum 45 min = 3q.
        $this->assertSame([1, 2], QuarterRounding::controlledRound($this->mins(20, 25)));
    }

    public function testTargetIsRoundedHalfUp(): void
    {
        // 7.5 minutes is exactly half a quarter, so the target rounds up to 1 quarter.
        $this->assertSame([1], QuarterRounding::controlledRound([450]));
        $this->assertSame([0], QuarterRounding::controlledRound([449]));
    }

    public function testDayToItemsHitsDayTarget(): void
    {
        $out = QuarterRounding::dayToItems($this->mins(20, 20, 20), 4);

        $this->assertSame(4, array_sum($out));
        $this->assertSame([2, 1, 1], $out);
    }

    public function testInconsistentTargetIsClamped(): void
    {
        // Target of 100 quarters cannot be reached by bumping each entry at most once.
        $out = QuarterRounding::dayToItems($this->mins(20, 20), 100);

        $this->assertSame([2, 2], $out);
    }

    public function testWeekToDaysMatchesPythonReference(): void
    {
        // Mon 1h50m, Tue 2h10m, Wed 50m = 290 min = 19.33 quarters, rounds to 19.
        $out = QuarterRounding::weekToDays($this->mins(110, 130, 50));

        $this->assertSame(19, array_sum($out));
        $this->assertSame([7, 9, 3], $out);
    }
}
