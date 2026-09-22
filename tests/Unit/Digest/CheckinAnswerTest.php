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

    /**
     * The rule must be APPLIED, not merely computed.
     *
     * Review demonstrated the ordinary refactor slip — `normaliseWillAsk()`
     * called, the result computed, and never written back — passing every
     * source assertion. Source-text tests cannot see that, so the loop moved
     * into this class, where it is a two-line behavioural test.
     */
    public function testNormalisationIsAppliedToTheRecords(): void
    {
        $result = CheckinAnswer::normaliseRecords([
            ['fields' => [
                'Monthly_Project_Checkin.is_complete' => false,
                'Monthly_Project_Checkin.vc_will_ask' => true,
            ]],
        ]);

        $this->assertTrue($result['changed']);
        $this->assertNull(
            $result['records'][0]['fields']['Monthly_Project_Checkin.vc_will_ask'],
            'The impossible state must not survive into what core writes.'
        );
    }

    public function testRecordsAreUntouchedWhenNothingNeedsChanging(): void
    {
        $records = [
            ['fields' => [
                'Monthly_Project_Checkin.is_complete' => true,
                'Monthly_Project_Checkin.vc_will_ask' => true,
            ]],
            // No vc_will_ask key at all — the honest-browser path, where
            // afIfDestroy deletes rather than blanks.
            ['fields' => ['Monthly_Project_Checkin.is_complete' => false]],
        ];

        $result = CheckinAnswer::normaliseRecords($records);

        $this->assertFalse($result['changed'], 'A false "changed" makes the log line a lie.');
        $this->assertSame($records, $result['records']);
    }

    /**
     * The re-send guard, as a behavioural rule.
     *
     * Review showed it present, correctly sensed, and inert — `{ $noop = true; }`
     * in place of `return;` — with every source assertion green and every
     * project double-sent to a volunteer.
     */
    public function testShouldAdvanceOnlyFromAnAdvanceableStatus(): void
    {
        $from = ['Active', 'On Hold'];

        $this->assertTrue(CheckinAnswer::shouldAdvance('Active', $from));
        $this->assertTrue(CheckinAnswer::shouldAdvance('On Hold', $from));
        $this->assertFalse(
            CheckinAnswer::shouldAdvance('Awaiting VC Project Close Form', $from),
            'A project already awaiting the form must not be sent a second Completion request.'
        );
        $this->assertFalse(CheckinAnswer::shouldAdvance('', $from), 'An unknown status is not advanceable.');
        // '0' is here for completeness, NOT as a strict-flag pin — and the
        // difference is worth stating, because review raised the strict flag
        // and the honest answer is that no test can hold it.
        //
        // Dropping `true` from the in_array is an EQUIVALENT mutation, not a
        // surviving one: shouldAdvance() type-hints `string $status`, and PHP 8
        // compares two strings as strings, so loose and strict agree for every
        // input this method can receive. Verified under PHP 8.2. The flag stays
        // because it states the intent; a test claiming to guard it would be
        // asserting something the language makes unobservable.
        $this->assertFalse(
            CheckinAnswer::shouldAdvance('0', $from),
            'A numeric-looking status is still just a status.'
        );
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
