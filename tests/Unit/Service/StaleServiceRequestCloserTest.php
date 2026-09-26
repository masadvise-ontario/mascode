<?php

namespace Civi\Mascode\Test\Unit\Service;

use Civi\Mascode\Service\StaleServiceRequestCloser as Closer;
use Civi\Mascode\Test\TestCase;

/**
 * The stale-SR sweep's selection rules.
 *
 * The write is the destructive part here, so every test that matters is a
 * "must NOT close" test: a case wrongly closed drops a live client out of
 * every open-case view and nobody goes looking for it.
 *
 * @coversNothing
 */
class StaleServiceRequestCloserTest extends TestCase
{
    private const AS_OF = '2026-09-24';

    private function sr(int $id, ?string $startDate): array
    {
        return ['id' => $id, 'subject' => "SR {$id}", 'start_date' => $startDate];
    }

    public function testExactly64DaysIsNotClosedButOneMoreIs(): void
    {
        // "more than 64 days" — 2026-07-22 is 64 days before AS_OF.
        $plan = Closer::classify(
            [$this->sr(1, '2026-07-22'), $this->sr(2, '2026-07-21')],
            [1 => '2026-09-01 10:00:00', 2 => '2026-09-01 10:00:00'],
            self::AS_OF
        );
        $this->assertSame([2], array_column($plan['to_close'], 'case_id'));
        $this->assertSame(1, $plan['not_yet_stale']);
        $this->assertSame(65, $plan['to_close'][0]['age_days']);
    }

    /**
     * The late entrant: opened long ago, but only just chased. Without the
     * 22-day floor the daily Job closed it the morning after one reminder.
     */
    public function testRecentReminderHoldsTheCloseOnALateEntrant(): void
    {
        // 200 days open, first chase yesterday.
        $plan = Closer::classify([$this->sr(30, '2026-03-08')], [30 => '2026-09-23 09:00:00'], self::AS_OF);
        $this->assertSame([], $plan['to_close']);
        $this->assertSame([30], array_column($plan['reminder_too_recent'], 'case_id'));
    }

    /** 22 = 64 - 42: exactly 21 days since the reminder holds, 22 closes. */
    public function testReminderGraceBoundaryIs22Days(): void
    {
        $plan = Closer::classify(
            [$this->sr(31, '2026-01-01'), $this->sr(32, '2026-01-01')],
            [31 => '2026-09-03 10:00:00', 32 => '2026-09-02 10:00:00'],   // 21 and 22 days before AS_OF
            self::AS_OF
        );
        $this->assertSame([32], array_column($plan['to_close'], 'case_id'));
        $this->assertSame([31], array_column($plan['reminder_too_recent'], 'case_id'));
    }

    /** The normal flow is unchanged: entered on opening, chased at 21 and 42, closes on day 65. */
    public function testNormalFlowStillClosesOnDay65(): void
    {
        // Opened 65 days before AS_OF (2026-07-21); second chase at day 42 = 2026-09-01, 23 days ago.
        $plan = Closer::classify([$this->sr(33, '2026-07-21')], [33 => '2026-09-01 10:00:00'], self::AS_OF);
        $this->assertSame([33], array_column($plan['to_close'], 'case_id'));
    }

    /** "false" typed into a Job parameter must not mean true. */
    public function testFlagIsParsedStrictly(): void
    {
        foreach ([true, 1, '1'] as $v) {
            $this->assertTrue(Closer::normaliseFlag($v, 'x'), json_encode($v));
        }
        foreach ([null, false, 0, '0', ''] as $v) {
            $this->assertFalse(Closer::normaliseFlag($v, 'x'), json_encode($v));
        }
        foreach (['false', 'true', 'yes', 2] as $v) {
            try {
                Closer::normaliseFlag($v, 'x');
                $this->fail('accepted ' . json_encode($v));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('expected true/1', $e->getMessage());
            }
        }
    }

    public function testStringFalseAllEligibleIsRefusedOnALiveRun(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // The message, not only the type: a "false" wrongly read as false would
        // throw the "needs case_ids" refusal, same type, and pass vacuously.
        $this->expectExceptionMessage('expected true/1');
        Closer::run(['dry_run' => false, 'all_eligible' => 'false']);
    }

    public function testStaleCaseWithNoReminderIsReportedNotClosed(): void
    {
        $plan = Closer::classify([$this->sr(3, '2025-01-01')], [], self::AS_OF);
        $this->assertSame([], $plan['to_close']);
        $this->assertSame([3], array_column($plan['stale_without_reminder'], 'case_id'));
    }

    /**
     * A NULL start date is "nobody recorded it", not "very old". Treating it
     * as old would close it; treating it as new would hide it. Neither.
     */
    public function testNoStartDateIsReportedNotClosed(): void
    {
        $plan = Closer::classify(
            [$this->sr(4, null), $this->sr(5, '')],
            [4 => '2026-01-01 00:00:00', 5 => '2026-01-01 00:00:00'],
            self::AS_OF
        );
        $this->assertSame([], $plan['to_close']);
        $this->assertSame([4, 5], array_column($plan['no_start_date'], 'case_id'));
        $this->assertSame(0, $plan['not_yet_stale']);
    }

    public function testFutureStartDateIsNotStale(): void
    {
        $plan = Closer::classify([$this->sr(6, '2026-12-01')], [6 => '2026-09-01'], self::AS_OF);
        $this->assertSame([], $plan['to_close']);
        $this->assertSame(1, $plan['not_yet_stale']);
    }

    public function testDateTimeStartDateIsReadAsDate(): void
    {
        $this->assertSame(65, Closer::ageInDays('2026-07-21 23:59:59', self::AS_OF));
    }

    public function testBucketsAreOldestFirst(): void
    {
        $plan = Closer::classify(
            [$this->sr(10, '2026-05-01'), $this->sr(11, '2025-05-01'), $this->sr(12, '2026-01-01')],
            [10 => '2026-09-01 10:00:00', 11 => '2026-09-01 10:00:00', 12 => '2026-09-01 10:00:00'],
            self::AS_OF
        );
        $this->assertSame([11, 12, 10], array_column($plan['to_close'], 'case_id'));
    }

    /**
     * The approved list narrows what is closed; an approved id that no longer
     * qualifies is REPORTED, never closed and never silently dropped.
     */
    public function testRestrictToApprovedIdsReportsTheIneligible(): void
    {
        $plan = Closer::classify(
            [$this->sr(20, '2026-01-01'), $this->sr(21, '2026-01-01'), $this->sr(22, '2026-09-01')],
            [20 => '2026-03-01 10:00:00', 21 => '2026-03-01 10:00:00', 22 => '2026-09-10 10:00:00'],
            self::AS_OF
        );
        // 22 is too young; 99 is not in the status at all.
        [$toClose, $notEligible] = Closer::restrictToCaseIds($plan['to_close'], [21, 22, 99]);
        $this->assertSame([21], array_column($toClose, 'case_id'));
        $this->assertSame([22, 99], $notEligible);
    }

    public function testNoApprovedIdsMeansNoRestriction(): void
    {
        $rows = [['case_id' => 1], ['case_id' => 2]];
        $this->assertSame([$rows, []], Closer::restrictToCaseIds($rows, []));
    }

    /** A live run must be told exactly which cases; it refuses before any query. */
    public function testLiveRunWithoutCaseIdsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs case_ids');
        Closer::run(['dry_run' => false]);
    }

    /** The unattended mode and an approved list together are ambiguous: refuse. */
    public function testAllEligibleWithCaseIdsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OR all_eligible');
        Closer::run(['dry_run' => false, 'case_ids' => [1], 'all_eligible' => true]);
    }

    /** A falsy all_eligible must not unlock a live run. */
    public function testFalsyAllEligibleStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs case_ids');
        Closer::run(['dry_run' => false, 'all_eligible' => 0]);
    }

    public function testAllEligibleWithFutureAsOfIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('future as_of');
        Closer::run(['dry_run' => false, 'all_eligible' => true, 'as_of' => '2999-01-01']);
    }

    public function testLiveRunWithFutureAsOfIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('future as_of');
        Closer::run(['dry_run' => false, 'case_ids' => [1], 'as_of' => '2999-01-01']);
    }

    public function testCaseIdsAcceptArrayAndCsv(): void
    {
        $this->assertSame([3, 1], Closer::normaliseCaseIds('3, 1,3'));
        $this->assertSame([7], Closer::normaliseCaseIds([7, '7']));
        $this->assertSame([], Closer::normaliseCaseIds(null));
        $this->assertSame([], Closer::normaliseCaseIds(''));
    }

    /**
     * An unreadable list must be refused, never shrunk to empty — empty means
     * "close every eligible case".
     */
    public function testUnreadableCaseIdsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Closer::normaliseCaseIds('12,abc');
    }

    public function testZeroCaseIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Closer::normaliseCaseIds([0]);
    }

    public function testMalformedAsOfIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Closer::normaliseAsOf('2026-02-30');
    }
}
