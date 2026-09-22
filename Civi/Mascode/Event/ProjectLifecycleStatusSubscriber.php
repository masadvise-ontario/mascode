<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/ProjectLifecycleStatusSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Core\Event\PostEvent;
use Civi\Mascode\Service\LifecycleMailer;

/**
 * Lifecycle status advancement for Project cases (sibling of
 * RcsRequestStatusSubscriber; formerly ProjectCloseStatusSubscriber).
 * Sending a lifecycle email IS the status change — no separate step:
 *
 *  - Client PD authorization request ("mas_lifecycle_pd_authorize__client")
 *      → "Awaiting Client Project Definition" (arms mas_lifecycle_client_pd_chase)
 *  - VC completion request ("MAS Project Completion - VC Template") sent
 *      → "Awaiting VC Project Close Form" (arms mas_lifecycle_vc_close_chase)
 *  - Client signoff request ("MAS Project Signoff - Client Template") sent
 *      → "Awaiting Client Project Close Form" (arms mas_lifecycle_close_chase)
 *
 * Watches BOTH activity types an outbound email can land as: "Email" (sent
 * manually from the case) and "Sent Automated Email" (a click-sent
 * LifecycleMailer draft — e.g. the auto-proposed client close email after the
 * VC close form arrives).
 *
 * Forward-only: each transition's from-list excludes the to-status and every
 * later status, so re-sending an email never regresses the lifecycle.
 */
class ProjectLifecycleStatusSubscriber extends AutoSubscriber
{
    /**
     * Template msg_title => allowed from-statuses and the to-status.
     *
     * ⚠ EVERY KEY IS A LIVE `civicrm_msg_template.msg_title` STRING.
     * getTemplateSubjects() queries `WHERE msg_title IN (array_keys(self::TRANSITIONS))`,
     * so a key with no matching template yields no subject, matchTransition()
     * returns NULL, and the case silently never advances — no error, no log
     * line, and the email still sends and still looks correct.
     *
     * That is not hypothetical: renaming template 75 in the production UI to
     * "MAS Project Signoff - Client Template" on 2026-09-17 broke the client
     * transition exactly this way, and with it the arming of
     * mas_lifecycle_close_chase. Renaming a lifecycle template in the UI is
     * therefore a CODE change, not a content change.
     *
     * Guarded in two places, because neither alone can see the whole fault:
     * tests/Live/LifecycleTransitionTemplatesTest.php checks these keys against
     * the real database (the half that broke), and
     * tests/Unit/Event/LifecycleTransitionTemplateWiringTest.php checks them
     * against the managed declarations in CI, which has no CiviCRM. Both also
     * enforce D18 — no template subject prefix may be a substring of another,
     * since matchTransition() takes the FIRST match and overlap would silently
     * misroute one transition to the other's status.
     */
    private const TRANSITIONS = [
        'mas_lifecycle_pd_authorize__client' => [
            'from' => ['Awaiting VC Project Definition'],
            'to' => 'Awaiting Client Project Definition',
        ],
        'MAS Project Completion - VC Template' => [
            'from' => ['Active', 'On Hold', 'Awaiting VC Project Definition', 'Awaiting Client Project Definition'],
            'to' => 'Awaiting VC Project Close Form',
        ],
        'MAS Project Signoff - Client Template' => [
            'from' => ['Active', 'On Hold', 'Awaiting VC Project Definition', 'Awaiting Client Project Definition', 'Awaiting VC Project Close Form'],
            'to' => 'Awaiting Client Project Close Form',
        ],
    ];

    /**
     * The template titles this class advances on.
     *
     * Public because anything that WRITES an activity subject needs to know
     * what will match it, and duplicating the list is how the two drift.
     * VcDigestMailer refuses to send a digest whose subject contains any of
     * these prefixes — without this accessor it had its own hard-coded copy of
     * the titles, which review caught: `TRANSITIONS` is private, and a UI
     * rename of a template `msg_title` is exactly what broke the client
     * transition on production in September. A copy would have gone on
     * guarding a title nobody uses any more.
     *
     * @return string[] `civicrm_msg_template.msg_title` values.
     */
    public static function transitionTemplateTitles(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * The static part of each transition's subject, keyed by template title.
     *
     * The SAME computation matchTransition() performs, exposed so a caller can
     * ask "would this subject move a case?" without reimplementing it. Sharing
     * the method rather than the rule is the point: two implementations of a
     * substring match that must agree is exactly the shape of defect this
     * codebase keeps finding.
     *
     * @return array<string,string>
     */
    public static function transitionSubjectPrefixes(): array
    {
        $prefixes = [];
        foreach (self::getTemplateSubjects() as $title => $subject) {
            $tokenPos = strpos($subject, '{');
            $prefixes[$title] = $tokenPos === false ? $subject : rtrim(substr($subject, 0, $tokenPos));
        }
        return $prefixes;
    }

    /** @var array<string,int>|null Cached activity-type name => value map */
    private static ?array $emailTypeIds = null;

    /** @var array<string,string>|null Cached template msg_title => msg_subject */
    private static ?array $templateSubjects = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'hook_civicrm_post' => 'onPost',
        ];
    }

    /**
     * @param \Civi\Core\Event\PostEvent $event
     */
    public function onPost(PostEvent $event): void
    {
        if ($event->action !== 'create' || $event->entity !== 'Activity') {
            return;
        }
        $activityId = (int) $event->id;
        if (!$activityId) {
            return;
        }

        try {
            $emailTypeIds = $this->getEmailTypeIds();
            $objTypeId = isset($event->object->activity_type_id) ? (int) $event->object->activity_type_id : null;
            if ($objTypeId !== null && !in_array($objTypeId, $emailTypeIds, true)) {
                return;
            }

            $act = \Civi\Api4\Activity::get(false)
                ->addSelect('activity_type_id', 'subject', 'case_id')
                ->addWhere('id', '=', $activityId)
                ->execute()
                ->first();
            if (
                empty($act)
                || !in_array((int) $act['activity_type_id'], $emailTypeIds, true)
                || empty($act['case_id'])
            ) {
                return;
            }

            $transition = $this->matchTransition((string) ($act['subject'] ?? ''));
            if ($transition === null) {
                return;
            }

            $case = \Civi\Api4\CiviCase::get(false)
                ->addSelect('status_id:name', 'case_type_id:name')
                ->addWhere('id', '=', $act['case_id'])
                ->execute()
                ->first();
            if (
                empty($case)
                || $case['case_type_id:name'] !== 'project'
                || !in_array($case['status_id:name'], $transition['from'], true)
            ) {
                return;
            }

            \Civi\Api4\CiviCase::update(false)
                ->addValue('status_id:name', $transition['to'])
                ->addWhere('id', '=', $act['case_id'])
                ->execute();

            \Civi::log()->info('ProjectLifecycleStatusSubscriber.php - Lifecycle email sent, project advanced', [
                'case_id' => $act['case_id'],
                'activity_id' => $activityId,
                'previous_status' => $case['status_id:name'],
                'new_status' => $transition['to'],
            ]);
        } catch (\Throwable $e) {
            \Civi::log()->error('ProjectLifecycleStatusSubscriber.php - Failed: ' . $e->getMessage(), [
                'activity_id' => $activityId,
            ]);
        }
    }

    /**
     * Match the activity subject against the lifecycle-template subjects.
     *
     * Rendered subjects may carry token-substituted values (e.g. a P-code),
     * so the match is "activity subject contains the template subject's
     * static prefix" — the prefix up to the first token.
     *
     * @return array{from: string[], to: string}|null
     */
    private function matchTransition(string $activitySubject): ?array
    {
        // Iterates the SAME prefixes transitionSubjectPrefixes() hands out.
        // An earlier version recomputed them here, so the rule existed twice —
        // and the test written to notice that asserted the literal appeared
        // exactly twice, which meant unifying them (the correct fix) turned the
        // suite red. Review caught both. One implementation, and the test now
        // asserts one.
        foreach (self::transitionSubjectPrefixes() as $title => $prefix) {
            if ($prefix !== '' && str_contains($activitySubject, $prefix)) {
                return self::TRANSITIONS[$title];
            }
        }
        return null;
    }

    /**
     * @return int[] activity_type values for Email + Sent Automated Email
     */
    private function getEmailTypeIds(): array
    {
        if (self::$emailTypeIds === null) {
            $rows = \Civi\Api4\OptionValue::get(false)
                ->addWhere('option_group_id.name', '=', 'activity_type')
                ->addWhere('name', 'IN', ['Email', LifecycleMailer::TYPE_SENT])
                ->addSelect('name', 'value')
                ->execute();
            self::$emailTypeIds = [];
            foreach ($rows as $row) {
                self::$emailTypeIds[$row['name']] = (int) $row['value'];
            }
        }
        return array_values(self::$emailTypeIds);
    }

    /**
     * @return array<string,string> template msg_title => msg_subject
     */
    private static function getTemplateSubjects(): array
    {
        if (self::$templateSubjects === null) {
            $rows = \Civi\Api4\MessageTemplate::get(false)
                ->addWhere('msg_title', 'IN', array_keys(self::TRANSITIONS))
                ->addSelect('msg_title', 'msg_subject')
                ->execute();

            $byTitle = [];
            foreach ($rows as $row) {
                $byTitle[$row['msg_title']] = (string) ($row['msg_subject'] ?? '');
            }

            // Re-key in TRANSITIONS order rather than returning the result set's
            // own order. matchTransition() takes the FIRST prefix that matches,
            // so the iteration order is part of its contract — and an API4 get
            // with no addOrderBy() returns rows in whatever order the database
            // chooses, which is not the declaration order this class documents.
            // D18 (no prefix contains another) means nothing is currently
            // ambiguous, so this fixes a latent mismatch between the code and
            // its own stated rationale rather than a live bug.
            self::$templateSubjects = [];
            foreach (array_keys(self::TRANSITIONS) as $title) {
                if (isset($byTitle[$title])) {
                    self::$templateSubjects[$title] = $byTitle[$title];
                }
            }
        }
        return self::$templateSubjects;
    }
}
