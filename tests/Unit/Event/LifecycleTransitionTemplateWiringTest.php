<?php

namespace Civi\Mascode\Test\Unit\Event;

use Civi\Mascode\Test\TestCase;

/**
 * The CI-visible half of the lifecycle transition guard.
 *
 * THE INCIDENT THIS EXISTS FOR. Message template 75 was renamed in the
 * production UI on 2026-09-17 from "MAS Project Close - Client Template" to
 * "MAS Project Signoff - Client Template". ProjectLifecycleStatusSubscriber
 * keys the client transition on that exact string, and getTemplateSubjects()
 * looks templates up with `WHERE msg_title IN (array_keys(TRANSITIONS))` — so
 * the lookup returned nothing, matchTransition() returned NULL, and sending the
 * client signoff email stopped advancing the case. mas_lifecycle_close_chase
 * arms off that status, so it stopped arming too. No exception, no log line:
 * the email sends and looks perfect.
 *
 * WHAT THIS FILE CAN AND CANNOT DO — read before adding to it.
 *
 * It compares SOURCE TO SOURCE: the TRANSITIONS keys against the msg_titles
 * declared in Civi/Mascode/Managed/MessageTemplate_*.mgd.php. That catches the
 * half of the failure that lives in this repository — somebody changing one of
 * the two and not the other, which is a realistic edit because they are 200
 * lines and two directories apart.
 *
 * It CANNOT see the incident itself. The rename happened in the production
 * database, where no test that reads only this repository can reach it. CI has
 * no CiviCRM at all (docs/TESTING.md), so an Integration-suite test would have
 * self-skipped and reported green. That half is guarded by
 * tests/Live/LifecycleTransitionTemplatesTest.php, which is a `cv scr` script
 * precisely so it can be pointed at production. Neither file replaces the
 * other, and the live one is the one that would have caught this.
 *
 * @coversNothing
 */
class LifecycleTransitionTemplateWiringTest extends TestCase
{
    /**
     * The TRANSITIONS keys, parsed out of the subscriber as TEXT.
     *
     * NOT reflection. ProjectLifecycleStatusSubscriber extends
     * \Civi\Core\Service\AutoSubscriber, so loading the class needs CiviCRM on
     * the autoloader — which CI does not have. A reflection version of this
     * helper errored on every CI run, and "errors in CI" degrades into "this
     * suite is expected to be red", which is how a guard stops guarding.
     * Reading the source as text is the same approach the sibling *WiringTest
     * files in this directory take, for the same reason.
     *
     * @return string[]
     */
    private function transitionKeys(): array
    {
        $path = __DIR__ . '/../../../Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php';
        $source = (string) file_get_contents($path);
        $this->assertNotSame('', $source, "could not read $path");

        $start = strpos($source, 'private const TRANSITIONS = [');
        $this->assertNotFalse($start, 'const TRANSITIONS not found — has it been renamed?');

        // The const's own terminator, at its indent level. Anchoring on "\n    ];"
        // rather than the next "];" stops the slice running past the constant
        // into the rest of the class, where a later array literal's keys would
        // be read as transitions.
        $end = strpos($source, "\n    ];", $start);
        $this->assertNotFalse($end, 'const TRANSITIONS has no terminator at class-member indent');

        $block = substr($source, $start, $end - $start);

        // Only top-level keys of the const: two indent levels (8 spaces), each
        // opening its own array. The nested 'from'/'to' keys sit deeper and do
        // not open an array literal on the same line, so they cannot match.
        preg_match_all("/\n        '([^']+)' => \[/", $block, $matches);
        $keys = $matches[1] ?? [];

        $this->assertNotEmpty(
            $keys,
            'no transition keys parsed from TRANSITIONS; every assertion below would pass vacuously. '
            . 'Has the array been reformatted or its indentation changed?'
        );

        return $keys;
    }

    /** @return array<string,string> declared msg_title => declaring file basename */
    private function declaredTemplateTitles(): array
    {
        $files = glob(__DIR__ . '/../../../Civi/Mascode/Managed/MessageTemplate_*.mgd.php');
        $this->assertNotEmpty($files, 'no MessageTemplate managed declarations found — has the path moved?');

        $titles = [];
        foreach ($files as $file) {
            $declarations = include $file;
            foreach ($declarations as $declaration) {
                $title = $declaration['params']['values']['msg_title'] ?? NULL;
                if ($title !== NULL) {
                    $titles[$title] = basename($file);
                }
            }
        }

        $this->assertNotEmpty($titles, 'no msg_title values could be read from the managed declarations');

        return $titles;
    }

    public function testEveryTransitionKeyIsADeclaredTemplateTitle(): void
    {
        $declared = $this->declaredTemplateTitles();

        foreach ($this->transitionKeys() as $key) {
            $this->assertArrayHasKey(
                $key,
                $declared,
                "ProjectLifecycleStatusSubscriber::TRANSITIONS keys \"$key\", but no managed "
                . "MessageTemplate declaration carries that msg_title.\n"
                . 'The two are one fact stored twice. If you renamed a template, rename the '
                . 'TRANSITIONS key with it AND add an upgrade step so existing environments '
                . 'converge — the declaration alone will not fix them, because these templates '
                . "are update => 'unmodified' and a hand-edited one is never rewritten by a deploy."
            );
        }
    }

    public function testNoDeclaredSubjectPrefixIsASubstringOfAnother(): void
    {
        // D18, asserted against the DECLARED subjects. matchTransition() tests
        // str_contains($activitySubject, $prefix) and returns the FIRST hit in
        // TRANSITIONS declaration order, so if one prefix contains another,
        // both emails route to whichever is declared first. That is worse than
        // the no-op the rename caused, because the case DOES move — to the
        // wrong status — and so it looks like the automation worked.
        //
        // The live subjects are what actually matter and they can be edited in
        // the UI; tests/Live/ asserts those. This checks what ships.
        $transitionKeys = $this->transitionKeys();
        $files = glob(__DIR__ . '/../../../Civi/Mascode/Managed/MessageTemplate_*.mgd.php');

        $prefixes = [];
        foreach ($files as $file) {
            foreach (include $file as $declaration) {
                $values = $declaration['params']['values'] ?? [];
                $title = $values['msg_title'] ?? NULL;
                if ($title === NULL || !in_array($title, $transitionKeys, TRUE)) {
                    continue;
                }
                $subject = (string) ($values['msg_subject'] ?? '');
                // Mirrors matchTransition(): the static head, up to the first token.
                $tokenPos = strpos($subject, '{');
                $prefix = $tokenPos === FALSE ? $subject : rtrim(substr($subject, 0, $tokenPos));

                $this->assertNotSame(
                    '',
                    $prefix,
                    "Template \"$title\" declares no static subject text before its first token. "
                    . 'matchTransition() skips an empty prefix, so that transition can never fire.'
                );
                $prefixes[$title] = $prefix;
            }
        }

        $this->assertSame(
            count($transitionKeys),
            count($prefixes),
            'not every TRANSITIONS key yielded a declared subject; '
            . 'testEveryTransitionKeyIsADeclaredTemplateTitle explains which.'
        );

        foreach ($prefixes as $titleA => $prefixA) {
            foreach ($prefixes as $titleB => $prefixB) {
                if ($titleA === $titleB) {
                    continue;
                }
                $this->assertStringNotContainsString(
                    $prefixB,
                    $prefixA,
                    "Subject prefix collision (D18): \"$titleB\" (\"$prefixB\") is a substring of "
                    . "\"$titleA\" (\"$prefixA\"). One of these two transitions is unreachable and "
                    . 'the other fires for both emails. Change a subject so neither contains the other.'
                );
            }
        }
    }

    public function testCiviRulesActionTemplatesAreDeclaredTitles(): void
    {
        // THE THIRD COPY. The same template title is written down in three
        // unrelated places: TRANSITIONS, the managed declaration, and the
        // `template` key of the action params LifecycleRuleProvisioner writes
        // into civirule_rule_action. The first two are covered above; this one
        // was missed when the template was renamed, and it is the copy with the
        // worst failure mode.
        //
        // LifecycleMailer::loadTemplate() resolves that value with
        // `WHERE msg_title = ...` and THROWS \InvalidArgumentException when
        // nothing matches. So a stale title here does not merely fail to advance
        // the case — mas_lifecycle_vc_close_send throws and the client close
        // email is never sent. On production the serialised row kept the retired
        // title through every deploy, because it is data, not source;
        // upgrade_5014 repoints it.
        $path = __DIR__ . '/../../../Civi/Mascode/Service/LifecycleRuleProvisioner.php';
        $source = (string) file_get_contents($path);
        $this->assertNotSame('', $source, "could not read $path");

        // Only the literals assigned to a 'template' key in an array, which is
        // what reaches action_params.
        preg_match_all("/'template'\s*=>\s*'([^']+)'/", $source, $matches);
        $used = array_unique($matches[1] ?? []);

        $this->assertNotEmpty(
            $used,
            'no \'template\' literals found in LifecycleRuleProvisioner — has the provisioning '
            . 'code moved? If so this guard is now asserting nothing and must be repointed.'
        );

        $declared = $this->declaredTemplateTitles();
        foreach ($used as $title) {
            $this->assertArrayHasKey(
                $title,
                $declared,
                "LifecycleRuleProvisioner provisions a CiviRules action with template \"$title\", "
                . "but no managed MessageTemplate declaration carries that msg_title.\n"
                . 'LifecycleMailer::loadTemplate() throws when the title does not resolve, so the '
                . 'email is never sent. Renaming a template means changing this literal AND adding '
                . 'an upgrade step to repoint the already-serialised civirule_rule_action rows, '
                . 'which no deploy touches.'
            );
        }
    }

    public function testTheClientTransitionUsesTheSignoffTitle(): void
    {
        // The specific regression, pinned. Reverting the key to the old
        // "Close" title re-breaks production, where the template has already
        // been renamed by hand and cannot be renamed back without breaking the
        // staff who read it.
        $this->assertContains(
            'MAS Project Signoff - Client Template',
            $this->transitionKeys(),
            'the client transition must key the Signoff title that production actually carries'
        );
    }
}
