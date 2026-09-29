<?php

namespace Civi\Mascode\Test\Unit\Digest;

use Civi\Mascode\Digest\CheckinPageRows;
use Civi\Mascode\Test\TestCase;

/**
 * The per-VC check-in page's row rules (P1-8), by behaviour.
 *
 * @coversNothing
 */
class CheckinPageRowsTest extends TestCase
{
    private const C = CheckinPageRows::IS_COMPLETE;

    /**
     * Strict in both directions. The inputs that would trip a loose test:
     * `''` (an untouched radio), `null`, an absent key, and the strings
     * `'false'`/`'true'`, which a `(bool)` cast reads as TRUE.
     */
    public function testIsAnsweredIsStrict(): void
    {
        foreach ([true, 1, '1', false, 0, '0'] as $answered) {
            $this->assertTrue(CheckinPageRows::isAnswered([self::C => $answered]), var_export($answered, true));
        }
        foreach (['', null, 'false', 'true', 'yes', [], 2] as $not) {
            $this->assertFalse(CheckinPageRows::isAnswered([self::C => $not]), var_export($not, true));
        }
        $this->assertFalse(CheckinPageRows::isAnswered([]), 'An absent key is not an answer.');
        $this->assertFalse(
            CheckinPageRows::isAnswered(['Monthly_Project_Checkin.vc_will_ask' => true]),
            'vc_will_ask alone is not an answer — the crafted row the plan names.'
        );
    }

    /**
     * Seeded rows carry the case and the label, and NEVER an id: an id makes
     * core update an existing activity (P1-7, fact 3).
     */
    public function testRowsForNeverCarriesAnId(): void
    {
        $rows = CheckinPageRows::rowsFor([
            ['case_id' => 17092, 'label' => 'Alpha — P1 — Plan'],
            ['case_id' => '18403', 'label' => 'Beta'],
            ['case_id' => [5], 'label' => 'array id'],
            ['case_id' => 0, 'label' => 'zero'],
        ]);

        $this->assertSame([
            ['fields' => ['case_id' => 17092, 'subject' => 'Alpha — P1 — Plan']],
            ['fields' => ['case_id' => 18403, 'subject' => 'Beta']],
        ], $rows, 'Bad ids are skipped; no row has an id key.');
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('id', $row['fields']);
        }
    }

    public function testCaseIdOfRefusesNonIds(): void
    {
        $this->assertSame(12, CheckinPageRows::caseIdOf(12));
        $this->assertSame(12, CheckinPageRows::caseIdOf('12'));
        foreach ([[12], '12abc', '-1', -1, 0, '', null, 1.5, ' 12'] as $bad) {
            $this->assertSame(0, CheckinPageRows::caseIdOf($bad), var_export($bad, true));
        }
    }

    public function testLabelJoinsWhatExists(): void
    {
        $this->assertSame('Org — P1 — Plan', CheckinPageRows::label('Org', 'P1', 'Plan'));
        $this->assertSame('P1 — Plan', CheckinPageRows::label('', 'P1', 'Plan'));
        $this->assertSame('', CheckinPageRows::label(' ', '', ''));
    }

    /**
     * Only ANSWERED rows are judged — the review-round-3 finding.
     *
     * Inputs that trip it: an unanswered row naming a case the VC may not
     * answer for (a stale page) must NOT refuse; an answered row naming one
     * must; an answered row with no case must.
     */
    public function testRefusalsJudgeAnsweredRowsOnly(): void
    {
        $entitled = static fn(int $id): bool => $id === 1;

        $refused = CheckinPageRows::refusals([
            0 => ['fields' => ['case_id' => 1, self::C => true]],
            1 => ['fields' => ['case_id' => 99]],
            2 => ['fields' => ['case_id' => 99, self::C => false]],
            3 => ['fields' => [self::C => '0']],
            4 => ['fields' => ['case_id' => 99, 'Monthly_Project_Checkin.vc_will_ask' => true]],
        ], $entitled);

        $this->assertSame([2 => 'not entitled', 3 => 'no case'], $refused);
    }

    /**
     * Keys are preserved: core pairs saved ids back by index.
     */
    public function testKeepAnsweredPreservesKeys(): void
    {
        $kept = CheckinPageRows::keepAnswered([
            0 => ['fields' => ['case_id' => 1, self::C => true]],
            1 => ['fields' => ['case_id' => 2]],
            2 => ['fields' => ['case_id' => 3, self::C => '0']],
        ]);
        $this->assertSame([0, 2], array_keys($kept));
    }

    /**
     * The backstop flags anything that should not have survived the drop.
     */
    public function testBackstopFlagsUnansweredAndUnentitled(): void
    {
        $entitled = static fn(int $id): bool => $id === 1;
        $this->assertSame([], CheckinPageRows::backstopViolations(
            [0 => ['fields' => ['case_id' => 1, self::C => true]]],
            $entitled
        ));
        $this->assertSame(
            [0 => 'unanswered', 1 => 'not entitled', 2 => 'no case'],
            CheckinPageRows::backstopViolations([
                0 => ['fields' => ['case_id' => 1]],
                1 => ['fields' => ['case_id' => 7, self::C => true]],
                2 => ['fields' => [self::C => false]],
            ], $entitled)
        );
    }
}
