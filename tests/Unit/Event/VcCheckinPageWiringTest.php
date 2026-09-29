<?php

namespace Civi\Mascode\Test\Unit\Event;

use Civi\Mascode\Test\TestCase;

/**
 * The CI-visible half of the per-VC check-in page (P1-8).
 *
 * The page is a PUBLIC `*always allow*` form whose rows carry a caller-editable
 * Hidden `case_id`. It is safe only while the properties below hold, and each
 * is something a FormBuilder edit or a refactor can undo with the behavioural
 * tests still green. Plan: docs/plans/completion-signoff-p1-8-per-vc-checkin-page.md.
 *
 * @coversNothing
 */
class VcCheckinPageWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const FORM_HTML = self::ROOT . '/ang/afformMASVcCheckin.aff.html';
    private const FORM_JSON = self::ROOT . '/ang/afformMASVcCheckin.aff.json';
    private const PAGE = self::ROOT . '/Civi/Mascode/Event/VcCheckinPageSubscriber.php';
    private const SUBMIT = self::ROOT . '/Civi/Mascode/Event/VcDigestSubmitSubscriber.php';
    private const PROBE = self::ROOT . '/tests/Security/afform-prefill-anon-probe.sh';

    private function entityTag(string $html, string $name): string
    {
        $this->assertSame(
            1,
            preg_match_all('/<af-entity\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m),
            "Exactly one {$name} entity."
        );
        return $m[0][0];
    }

    /**
     * Inputs that trip it: `update: true` on Activity1; `case_id` put in its
     * `data` (data overrides submitted, pinning every row to one case);
     * `required: true` on is_complete (refuses any page with a project left
     * blank); Activity2 writable, or carrying data; case_id not Hidden.
     */
    public function testFormShapeIsSafe(): void
    {
        $this->assertFileExists(self::FORM_HTML);
        $html = file_get_contents(self::FORM_HTML);

        $a1 = $this->entityTag($html, 'Activity1');
        $this->assertStringContainsString('actions="{create: true, update: false}"', $a1);
        $this->assertStringNotContainsString('case_id', $a1, 'case_id must not be in Activity1 data.');
        $this->assertStringNotContainsString('autofill', $a1);

        $a2 = $this->entityTag($html, 'Activity2');
        $this->assertStringContainsString('actions="{create: false, update: false}"', $a2);
        $this->assertStringNotContainsString('data=', $a2, 'Activity2 must carry no data, or its rows stop being empty.');

        $this->assertSame(2, preg_match_all('/<af-entity\b/', $html), 'No other entity on the form.');

        $this->assertMatchesRegularExpression(
            '/<af-field name="case_id" defn="\{input_type: \'Hidden\'/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="Monthly_Project_Checkin\.is_complete" defn="\{[^}]*required: true/',
            $html,
            'is_complete must not be required.'
        );
        $this->assertStringNotContainsString('afform_default', $html);
        $this->assertStringNotContainsString('af-if', $html, 'No hand-written af-if (memory: feedback_afform_af_if_serialization).');

        preg_match('/<fieldset\b[^>]*af-fieldset="Activity2"[^>]*>(.*?)<\/fieldset>/s', $html, $pane);
        $this->assertNotEmpty($pane);
        preg_match_all('/<af-field\b[^>]*>/', $pane[1], $fields);
        foreach ($fields[0] as $field) {
            $this->assertStringContainsString("input_type: 'DisplayOnly'", $field, 'Activity2 is read-only: ' . $field);
        }
    }

    public function testFormMetadata(): void
    {
        $json = json_decode((string) file_get_contents(self::FORM_JSON), true);
        $this->assertSame(['*always allow*'], $json['permission']);
        $this->assertFalse($json['autosave_draft'], 'A restored draft would duplicate the appended rows.');
        $this->assertContains('msg_token_single', $json['placement'], 'Token-placeable, so P1-9 can link to it.');
    }

    /**
     * Priorities: drop (50) and backstop (30) above normalisation (10) and
     * core's save (0); validate subscribed.
     */
    public function testSubscriptions(): void
    {
        $code = $this->codeOnly((string) file_get_contents(self::PAGE));
        $this->assertStringContainsString("'civi.afform.validate' => ['onValidate', 0]", $code);
        $this->assertStringContainsString("['onDrop', 50]", $code);
        $this->assertStringContainsString("['onBackstop', 30]", $code);
        $this->assertStringContainsString("'civi.api.respond' => ['onRespond', -100]", $code);
    }

    /**
     * Fail closed. Inputs that trip it: a try/catch wrapped round the drop
     * (an exception would then let never-validated rows reach the save), or a
     * backstop that logs instead of throwing.
     */
    public function testDropAndBackstopFailClosed(): void
    {
        $code = (string) file_get_contents(self::PAGE);

        $drop = $this->methodBody($code, 'onDrop');
        $this->assertStringNotContainsString('catch', $drop);
        $this->assertStringContainsString('CheckinPageRows::keepAnswered($event->getRecords())', $drop);
        $this->assertStringContainsString("getEntityName() !== 'Activity1'", $drop);

        $back = $this->methodBody($code, 'onBackstop');
        $this->assertStringNotContainsString('catch', $back);
        $this->assertStringContainsString('CheckinPageRows::backstopViolations(', $back);
        $this->assertStringContainsString('throw new', $back);
        $this->assertStringContainsString("getEntityName() !== 'Activity1'", $back);
    }

    /**
     * Validate judges the Activity1 rows with the shared rule and the SAME D10
     * predicate as the per-project guard, and refuses by adding an error.
     */
    public function testValidateUsesTheSharedRulesAndPredicate(): void
    {
        $body = $this->methodBody((string) file_get_contents(self::PAGE), 'onValidate');
        $this->assertStringContainsString("getSubmittedValues()['Activity1']", $body);
        $this->assertStringContainsString('CheckinPageRows::refusals(', $body);
        $this->assertStringContainsString('CheckinCaseEntitlementSubscriber::isEntitledToCase(', $body);
        $this->assertStringContainsString('$event->addError(', $body);
    }

    /**
     * The respond listener is scoped: this form, fillMode form, a session
     * contact. Inputs that trip it: dropping any one of the three.
     */
    public function testRespondIsScoped(): void
    {
        $code = (string) file_get_contents(self::PAGE);
        $scope = $this->methodBody($code, 'isThisPrefill');
        $this->assertStringContainsString("getActionName() === 'prefill'", $scope);
        $this->assertStringContainsString('getName() === self::FORM_NAME', $scope);
        $this->assertStringContainsString("getFillMode() === 'form'", $scope);

        $respond = $this->methodBody($code, 'onRespond');
        $this->assertStringContainsString('if (!$vcId) {', $respond);
        $this->assertStringContainsString('CheckinPageRows::rowsFor(', $respond);
    }

    /**
     * P1-5's per-record logic runs for this form, for EVERY saved record.
     */
    public function testSubmitSubscriberServesThePage(): void
    {
        $code = (string) file_get_contents(self::SUBMIT);
        $this->assertStringContainsString(
            '[self::FORM_NAME, self::PAGE_FORM_NAME]',
            $this->methodBody($code, 'isThisForm')
        );
        $after = $this->methodBody($code, 'onAfterSave');
        $this->assertStringContainsString('array_keys($event->getRecords())', $after);
        $this->assertStringNotContainsString('getEntityId(0)', $after);
    }

    /**
     * "Already answered" and the stamp use ONE round rule (review M1).
     * Input that trips it: the page reverting to the calendar month
     * (`VcDigestRunner::round(...)`), or recordAnswers computing its own.
     */
    public function testAnsweredRoundMatchesTheStamp(): void
    {
        $page = $this->methodBody((string) file_get_contents(self::PAGE), 'answeredThisRound');
        $this->assertStringContainsString('VcDigestSubmitSubscriber::roundFor($caseId)', $page);
        $this->assertStringNotContainsString('VcDigestRunner::round(', $page);

        $stamp = $this->methodBody((string) file_get_contents(self::SUBMIT), 'recordAnswers');
        $this->assertStringContainsString('self::roundFor($caseId)', $stamp);
    }

    /**
     * The phantom-row fix (P1-9) keys on class names the form and the JS must
     * share. Inputs that trip it: a pane class renamed in FormBuilder, or a
     * directive keyed on the wrong field.
     */
    public function testPhantomRowDirectivesMatchThePanes(): void
    {
        $html = (string) file_get_contents(self::FORM_HTML);
        $this->assertMatchesRegularExpression('/af-fieldset="Activity1" class="[^"]*\bmas-vc-checkin-open\b/', $html);
        $this->assertMatchesRegularExpression('/af-fieldset="Activity2" class="[^"]*\bmas-vc-checkin-answered\b/', $html);

        $js = (string) file_get_contents(self::ROOT . '/ang/mascodeForms.js');
        $this->assertStringContainsString(".directive('masVcCheckinOpen', hideRowsWithout('case_id', 'mas-has-open'))", $js);
        $this->assertStringContainsString(".directive('masVcCheckinAnswered', hideRowsWithout('case_id', 'mas-has-answered'))", $js);
        $this->assertStringContainsString('mas-vc-checkin-empty', $html, 'The empty-state line exists.');
        $this->assertStringContainsString("restrict: 'C'", $js);
    }

    public function testTheAnonymousProbeCoversThePage(): void
    {
        $this->assertMatchesRegularExpression('/^\s*afformMASVcCheckin\s*$/m', (string) file_get_contents(self::PROBE));
    }

    // Helpers, as in DigestSubmitWiringTest: comments stripped first.

    private function methodBody(string $code, string $method): string
    {
        $code = $this->codeOnly($code);
        $found = preg_match_all(
            '/\n\s*(?:public|protected|private)(?:\s+static)?\s+function\s+'
            . preg_quote($method, '/') . '\s*\(/',
            $code,
            $m,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $found, "Method {$method}() is missing, or declared more than once.");
        $start = $m[0][0][1];
        $after = $start + strlen($m[0][0][0]);
        $end = strlen($code);
        foreach ([
            '/\n\s*(?:public|protected|private)(?:\s+static)?\s+function\s/',
            '/\n\}/',
        ] as $pattern) {
            if (preg_match($pattern, substr($code, $after), $n, PREG_OFFSET_CAPTURE)) {
                $end = min($end, $after + $n[0][1]);
            }
        }
        return substr($code, $start, $end - $start);
    }

    private function codeOnly(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }
}
