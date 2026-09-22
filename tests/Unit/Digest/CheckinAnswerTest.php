<?php

namespace Civi\Mascode\Test\Unit\Digest;

use Civi\Mascode\Digest\CheckinAnswer;
use Civi\Mascode\Test\TestCase;

/**
 * The two rules that decide whether a volunteer gets an email and whether the
 * office gets a work item.
 *
 * These were originally inside `VcDigestSubmitSubscriber`, which extends
 * `AutoSubscriber` and cannot load in CI — so they could only be asserted over
 * source text, and one of the two "tests" for them did nothing but
 * `markTestSkipped`. Moving them to a CiviCRM-free class makes them testable
 * by behaviour, which for a rule about `'0'` versus `'false'` is the only kind
 * of test worth having.
 *
 * @coversNothing
 */
class CheckinAnswerTest extends TestCase
{
    /**
     * @dataProvider truthValues
     */
    public function testStrictTruth($value, bool $expected, string $why): void
    {
        $this->assertSame($expected, CheckinAnswer::isTrue($value), $why);
    }

    public function truthValues(): array
    {
        return [
            'real true' => [true, true, 'The Boolean field stores a real boolean.'],
            'integer 1' => [1, true, 'API4 returns 1 for a true Boolean custom field.'],
            'string "1"' => ['1', true, 'CiviCRM round-trips booleans as "1"/"0" strings.'],
            'real false' => [false, false, ''],
            'integer 0' => [0, false, ''],
            'string "0"' => ['0', false, 'The trap: a loose cast gets this one RIGHT and the next one wrong.'],
            'string "false"' => ['false', false, 'A (bool) cast makes this TRUE — a volunteer emailed a form for work they did not finish.'],
            'string "true"' => ['true', false, 'Not a value this field produces; anything ambiguous is not-complete.'],
            'null' => [null, false, 'Unanswered is not complete.'],
            'empty string' => ['', false, ''],
            'integer 2' => [2, false, 'Only 1 is true.'],
        ];
    }

    /**
     * `vc_will_ask` is NULL whenever the work is not complete.
     *
     * NULL means "the question was never put to them" — the form hides it —
     * which is a different fact from an answered No. D8 queues the office's
     * donation follow-up off that distinction, so collapsing the two would
     * manufacture a work item about a client nobody was ever asked about.
     *
     * The browser cannot be trusted to do this: core does not strip
     * conditionally-hidden fields on submit, so the only thing clearing the
     * value today is JavaScript — and a crafted or replayed submit has none.
     *
     * @dataProvider willAskCases
     */
    public function testWillAskIsNullUnlessTheWorkIsComplete($isComplete, $submitted, $expected, string $why): void
    {
        $this->assertSame($expected, CheckinAnswer::normaliseWillAsk($isComplete, $submitted), $why);
    }

    public function willAskCases(): array
    {
        return [
            'not complete, but a crafted submit says they will ask' =>
                [false, true, null, 'THE case this exists for: the form never showed the question.'],
            'not complete, crafted "1"' => ['0', '1', null, ''],
            'not complete, answered no' => [false, false, null, 'Still NULL: the question was not put to them.'],
            'not complete, absent' => [false, null, null, ''],
            'complete and will ask' => [true, true, true, ''],
            'complete, will ask, as strings' => ['1', '1', true, ''],
            'complete and declines' => [true, false, false, 'A real No, which D8 reads with the signoff outcome.'],
            'complete but unanswered' => [true, null, null, 'Absent is not a No.'],
        ];
    }
}
