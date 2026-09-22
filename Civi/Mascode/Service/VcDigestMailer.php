<?php

declare(strict_types=1);

// file: Civi/Mascode/Service/VcDigestMailer.php

namespace Civi\Mascode\Service;

use Civi\Mascode\Event\ProjectLifecycleStatusSubscriber;
use Civi\Mascode\Event\VcDigestTokenSubscriber;

/**
 * Builds and sends one VC's monthly digest.
 *
 * Spec: BrianPKM 3-Resources/mascode-vc-monthly-donation-digest-spec.md,
 * component `VcDigestMailer`. Ticket: P1-4.
 *
 * A SIBLING OF LifecycleMailer, NOT A CHANGE TO IT. That service is
 * case-scoped by contract — `execute()` requires a `case_id` and writes its
 * activity on that case — and seven live CiviRules depend on it. A digest has
 * one recipient and N cases, so it needs its own runtime. The spec says the
 * same under *Missing pieces*.
 *
 * What it does per VC:
 *   1. mints one check-in link per project (`Tokens::createUrl`, TTL =
 *      `checksum_timeout`, D11);
 *   2. renders the managed digest template with `{digest.*}` supplied through
 *      the TokenProcessor row context;
 *   3. sends one email;
 *   4. writes one *Sent Automated Email* activity PER PROJECT, so the digest
 *      shows up in each case's timeline (spec §Outputs).
 */
final class VcDigestMailer
{
    public const TEMPLATE_TITLE = 'mas_vc_monthly_digest__vc';

    public const CHECKIN_FORM = 'afformMASProjectCheckin';

    /**
     * Lifecycle transition prefixes that must never appear in an activity
     * subject this class writes.
     *
     * ⚠ THIS IS A LOADED GUN AND THE GUARD BELOW IS THE SAFETY CATCH.
     * ProjectLifecycleStatusSubscriber fires on `Sent Automated Email`
     * activities — which is exactly what step 4 above creates — and its
     * matchTransition() does `str_contains($activitySubject, $prefix)` against
     * the STATIC part of each transition template's subject. Those prefixes are
     * currently the bare strings "Project Completion" and "MAS Project
     * Signoff".
     *
     * So a digest subject containing either string would advance EVERY listed
     * project to an awaiting-form status the moment the digest went out — 132
     * projects on the 2026-09-21 clone — silently, with no error, behind a
     * perfectly normal-looking email. The chases would arm, the Ops dashboard
     * would fill, and the only clue would be a status change nobody made.
     *
     * The list is read from the LIVE templates rather than hard-coded, because
     * hard-coding it would mean a subject renamed in the CiviCRM UI silently
     * stops being guarded — which is the precise failure that broke the client
     * transition on production in September (see
     * ProjectLifecycleStatusSubscriber's docblock).
     */
    private static ?array $transitionPrefixes = null;

    /**
     * Send one VC's digest.
     *
     * @param int $vcContactId
     * @param array $projects Rows from VcDigestRunner: case_id, subject, start_date.
     * @param string $round `YYYY-MM`.
     * @return array{vc_id:int, recipient_email:string, projects:int, activity_ids:int[], subject:string}
     */
    public static function send(int $vcContactId, array $projects, string $round): array
    {
        if (!$vcContactId || !$projects) {
            throw new \InvalidArgumentException('VcDigestMailer::send needs a VC and at least one project');
        }

        $caseIds = array_map(static fn($p) => (int) $p['case_id'], $projects);

        // ⚠ IDEMPOTENCY, AND IT BELONGS HERE RATHER THAN IN P2-1's JOB.
        //
        // The spec puts the month-level guard on the scheduled Job, and the
        // reasoning it gives — "62 volunteers getting a duplicate is not
        // recoverable" — applies to this hand-invoked path first, because this
        // is the path the P1-6 pilot uses. Worse, deliver() catches per-VC
        // errors and reports them, so the natural response to "3 VCs failed"
        // is to re-run the command, which without this would re-send to the 58
        // that succeeded.
        //
        // Keyed on (case, round) via the marker recordOnCase() writes. The test
        // is "has ANY of this VC's projects already been marked for this
        // round", not "all of them": a run that died mid-loop leaves some cases
        // marked and the VC already holding the email, so re-sending is the
        // unrecoverable direction. Under-sending is recoverable — the office
        // sends one by hand and the log names the VC.
        if (self::alreadySentThisRound($vcContactId, $caseIds, $round)) {
            \Civi::log()->info('VcDigestMailer.php - Skipped: this VC already had this round', [
                'vc_id' => $vcContactId,
                'round' => $round,
            ]);
            return [
                'vc_id' => $vcContactId,
                'recipient_email' => '',
                'projects' => 0,
                'activity_ids' => [],
                'subject' => '',
                'skipped' => true,
            ];
        }

        $recipient = self::loadRecipient($vcContactId);
        $template = self::loadTemplate();
        $rows = self::buildProjectRows($vcContactId, $projects);

        [$subject, $html] = self::render($template, $vcContactId, $rows, $round);

        // Before anything is sent or written. See $transitionPrefixes.
        self::assertSubjectCannotTriggerATransition($subject);
        $activitySubject = self::activitySubject($round);
        self::assertSubjectCannotTriggerATransition($activitySubject);

        self::sendMail($recipient, $subject, $html);

        // THE EMAIL HAS NOW LEFT. Everything below is bookkeeping, and a
        // failure in it must not be reported as a failed send — an earlier
        // version let one throw propagate, which made deliver() count the VC as
        // an error and invited a re-run that would have emailed them twice.
        // Each write is caught individually: a missing case-timeline entry is a
        // gap somebody can fill, a duplicate email is not.
        $activityIds = [];
        $activityErrors = [];
        foreach ($rows as $row) {
            try {
                $activityIds[] = self::recordOnCase(
                    (int) $row['case_id'],
                    $vcContactId,
                    $activitySubject,
                    $html,
                    $round
                );
            } catch (\Throwable $e) {
                $activityErrors[] = "case {$row['case_id']}: " . $e->getMessage();
                \Civi::log()->error('VcDigestMailer.php - Digest sent but the case activity was not written', [
                    'vc_id' => $vcContactId,
                    'case_id' => $row['case_id'],
                    'round' => $round,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        \Civi::log()->info('VcDigestMailer.php - Sent VC digest', [
            'vc_id' => $vcContactId,
            'recipient' => $recipient['email'],
            'projects' => count($rows),
            'round' => $round,
        ]);

        return [
            'vc_id' => $vcContactId,
            'recipient_email' => $recipient['email'],
            'projects' => count($rows),
            'activity_ids' => $activityIds,
            'subject' => $subject,
            // Reported separately from a send failure, because they mean
            // opposite things to an operator deciding whether to re-run.
            'activity_errors' => $activityErrors,
        ];
    }

    /**
     * One row per project, each carrying its own minted check-in link.
     *
     * @return array<int,array>
     */
    public static function buildProjectRows(int $vcContactId, array $projects): array
    {
        $afform = self::loadCheckinForm();
        $codes = self::loadMasCodes(array_map(static fn($p) => (int) $p['case_id'], $projects));

        $rows = [];
        foreach ($projects as $project) {
            $caseId = (int) $project['case_id'];
            $rows[] = [
                'case_id' => $caseId,
                'mas_code' => $codes[$caseId] ?? '',
                'subject' => (string) ($project['subject'] ?? ''),
                'start_date' => $project['start_date'] ?? null,
                // One link per (VC, case). The token carries case_id in its
                // signed afformArgs; the form re-derives entitlement anyway,
                // because a link outlives the role it was minted under
                // (CheckinCaseEntitlementSubscriber).
                'checkin_url' => \Civi\Afform\Tokens::createUrl(
                    $afform,
                    $vcContactId,
                    ['case_id' => $caseId]
                ),
            ];
        }
        return $rows;
    }

    /**
     * The per-project activity subject.
     *
     * Deliberately contains neither transition prefix, and is checked against
     * the live ones before use rather than trusted.
     */
    public static function activitySubject(string $round): string
    {
        return "Monthly digest sent to the volunteer consultant ({$round})";
    }

    /**
     * @throws \RuntimeException when the subject would move cases.
     */
    public static function assertSubjectCannotTriggerATransition(string $subject): void
    {
        $hit = self::subjectTriggersTransition($subject, self::transitionPrefixes());
        if ($hit !== null) {
            [$title, $prefix] = $hit;
            throw new \RuntimeException(
                "Refusing to send: the digest subject contains the lifecycle transition prefix "
                . "'{$prefix}' (from template '{$title}'). ProjectLifecycleStatusSubscriber "
                . 'substring-matches activity subjects, so sending this would silently advance every '
                . 'project in the digest. Reword the subject.'
            );
        }
    }

    /**
     * The matching rule, as a pure function so CI can hold it.
     *
     * Separated from the live template lookup deliberately: the CONSEQUENCE of
     * getting this wrong is 132 projects advancing silently, and the lookup is
     * an API4 call CI cannot make. A rule this expensive to get wrong should
     * not be untestable because of where its inputs come from.
     *
     * Mirrors ProjectLifecycleStatusSubscriber::matchTransition(), which uses
     * `str_contains($activitySubject, $prefix)` — substring, not prefix, so a
     * transition string ANYWHERE in the subject fires it.
     *
     * @param array<string,string> $prefixes template title => static subject prefix
     * @return array{0:string,1:string}|null [title, prefix] of the first hit
     */
    public static function subjectTriggersTransition(string $subject, array $prefixes): ?array
    {
        foreach ($prefixes as $title => $prefix) {
            // An empty prefix would match every subject, so it is skipped
            // rather than treated as a hit — a template whose subject is
            // entirely a token has no static part to match on, and
            // matchTransition() skips it for the same reason.
            if ($prefix !== '' && str_contains($subject, $prefix)) {
                return [(string) $title, $prefix];
            }
        }
        return null;
    }

    /**
     * Live transition subject prefixes, keyed by template title.
     *
     * ⚠ DELEGATED TO THE TRANSITION'S OWNER, and an earlier version did not
     * delegate — it hard-coded the same three `msg_title`s that
     * `ProjectLifecycleStatusSubscriber::TRANSITIONS` holds, and recomputed the
     * prefix itself.
     *
     * Review caught why that is worse than it looks. This PR claimed the
     * prefixes were "read from the live templates, not hard-coded", and the
     * SUBJECTS were — but the SET OF TEMPLATES was not, and a `msg_title`
     * rename is precisely what broke the client transition on production in
     * September. A hard-coded list would have gone on faithfully guarding a
     * title nobody uses any more, while the subscriber armed on one this guard
     * had never heard of. Adding a fourth transition would have done the same.
     *
     * The owner now exposes ONE implementation of the prefix computation, used
     * by its own matchTransition() and by this guard, so the two cannot
     * disagree about what would move a case.
     *
     * Not RcsRequestStatusSubscriber's transitions, deliberately: that one
     * fires only on activity type `Email` and only on `service_request` cases,
     * while this mailer writes `Sent Automated Email` on `project` cases. It is
     * out of scope by the data rather than by intent, so if either of those
     * filters ever widens, this list has to.
     *
     * @return array<string,string>
     */
    public static function transitionPrefixes(): array
    {
        if (self::$transitionPrefixes === null) {
            self::$transitionPrefixes = ProjectLifecycleStatusSubscriber::transitionSubjectPrefixes();
        }
        return self::$transitionPrefixes;
    }

    /**
     * Has THIS VC already been mailed this round?
     *
     * Reads the marker recordOnCase() writes. Deliberately ANY of the VC's
     * projects rather than ALL — see the call site.
     *
     * ⚠ THE CONTACT-ID CLAUSE IS NOT OPTIONAL, and its absence was a Critical
     * finding in review. The first version took `$vcContactId` and never used
     * it, so the test was "has anyone been mailed about any of these cases this
     * round". For a project with two coordinators that is catastrophic and
     * silent: `deliver()` walks VCs in ascending contact id (ksort), the lower
     * id is mailed and marks the shared case, and the higher id then matches
     * the OTHER VC's marker and is skipped ENTIRELY — every project they hold,
     * not just the shared one — while being counted under
     * `vcs_skipped_already_sent`, which reads as correct behaviour.
     *
     * Measured on the 2026-09-21 clone: 4 shared projects, and **3 of 62 VCs
     * would have received nothing at all, deterministically, every month**.
     * That is the "a wrong answer silently drops a VC" failure VcDigestRunner
     * is explicitly shaped against, reintroduced in the delivery step — the
     * second time this epic has put it there.
     *
     * @param int[] $caseIds
     */
    public static function alreadySentThisRound(int $vcContactId, array $caseIds, string $round): bool
    {
        if (!$caseIds) {
            return false;
        }

        $get = \Civi\Api4\Activity::get(false)
            ->addSelect('id')
            ->addWhere('case_id', 'IN', $caseIds)
            ->addWhere('activity_type_id:name', '=', LifecycleMailer::TYPE_SENT)
            ->addWhere('details', 'LIKE', '%<!--mas-digest %');

        foreach (self::markerFragmentsFor($vcContactId, $round) as $fragment) {
            $get->addWhere('details', 'LIKE', '%' . $fragment . '%');
        }

        return (bool) $get->setLimit(1)->execute()->count();
    }

    /**
     * The marker fragments that identify one VC's digest for one round.
     *
     * A pure function, and separated for a specific reason: the assertions
     * written to protect the idempotency check were source-text greps, and
     * every one of them PASSED while the contact id was being ignored — the
     * test that existed to guard this certified the bug as correct. A property
     * test over these two fragments would have been red on the first run.
     *
     * Both fragments are matched on `details` because the marker is JSON inside
     * an HTML comment. Note the closing brace on the contact id: without it,
     * `"recipient_contact_id":763` is a LIKE-prefix of `…:7634`, and contact
     * 763 would be treated as already-mailed because 7634 was. `digest_round`
     * needs no such terminator because `json_encode()` quotes it.
     *
     * @return string[]
     */
    public static function markerFragmentsFor(int $vcContactId, string $round): array
    {
        return [
            '"digest_round":' . json_encode($round),
            '"recipient_contact_id":' . $vcContactId . '}',
        ];
    }

    // ------------------------------------------------------------------

    private static function render(array $template, int $contactId, array $rows, string $round): array
    {
        $tp = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
            'controller' => self::class,
            'smarty' => false,
            // contactId only — a digest has no single case, which is the whole
            // reason LifecycleMailer could not be reused.
            'schema' => ['contactId'],
        ]);
        $tp->addMessage('subject', $template['msg_subject'] ?? '', 'text/plain');
        $tp->addMessage('body', $template['msg_html'] ?? '', 'text/html');
        $tp->addRow([
            'contactId' => $contactId,
            VcDigestTokenSubscriber::ROWS_CONTEXT_KEY => $rows,
            VcDigestTokenSubscriber::ROUND_CONTEXT_KEY => $round,
        ]);
        $tp->evaluate();
        $row = $tp->getRow(0);
        return [$row->render('subject'), $row->render('body')];
    }

    private static function loadTemplate(): array
    {
        $row = \Civi\Api4\MessageTemplate::get(false)
            ->addSelect('id', 'msg_title', 'msg_subject', 'msg_html')
            ->addWhere('msg_title', '=', self::TEMPLATE_TITLE)
            ->setLimit(1)
            ->execute()
            ->first();
        if (!$row) {
            throw new \RuntimeException(
                "Message template '" . self::TEMPLATE_TITLE . "' not found. It is a managed entity — "
                . 'run cv upgrade:db on this environment.'
            );
        }
        return $row;
    }

    private static function loadCheckinForm(): array
    {
        $afform = \Civi\Api4\Afform::get(false)
            ->addSelect('name', 'server_route', 'is_public')
            ->addWhere('name', '=', self::CHECKIN_FORM)
            ->setLimit(1)
            ->execute()
            ->first();
        if (!$afform) {
            throw new \RuntimeException(
                'Afform ' . self::CHECKIN_FORM . ' not found; the digest would send links to nothing.'
            );
        }
        return $afform;
    }

    /**
     * MAS project codes, by case id.
     *
     * @param int[] $caseIds
     * @return array<int,string>
     */
    private static function loadMasCodes(array $caseIds): array
    {
        if (!$caseIds) {
            return [];
        }
        $rows = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'Projects.MAS_Project_Case_Code')
            ->addWhere('id', 'IN', $caseIds)
            ->setLimit(0)
            ->execute();
        $codes = [];
        foreach ($rows as $row) {
            $codes[(int) $row['id']] = (string) ($row['Projects.MAS_Project_Case_Code'] ?? '');
        }
        return $codes;
    }

    private static function loadRecipient(int $contactId): array
    {
        $contact = \Civi\Api4\Contact::get(false)
            ->addSelect('id', 'display_name', 'email_primary.email', 'is_deceased', 'do_not_email')
            ->addWhere('id', '=', $contactId)
            ->execute()
            ->first();
        if (!$contact) {
            throw new \InvalidArgumentException("VC contact {$contactId} not found");
        }
        if (empty($contact['email_primary.email'])) {
            throw new \RuntimeException("VC contact {$contactId} has no primary email");
        }
        if (!empty($contact['do_not_email']) || !empty($contact['is_deceased'])) {
            throw new \RuntimeException("VC contact {$contactId} must not be emailed");
        }
        return [
            'id' => (int) $contact['id'],
            'display_name' => (string) $contact['display_name'],
            'email' => (string) $contact['email_primary.email'],
        ];
    }

    private static function sendMail(array $recipient, string $subject, string $html): void
    {
        [$domainName, $domainEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();
        // CRM_Utils_Mail::send() takes $params by reference — needs a variable.
        $mailParams = [
            'from' => "\"{$domainName}\" <{$domainEmail}>",
            'toName' => $recipient['display_name'],
            'toEmail' => $recipient['email'],
            'subject' => $subject,
            'html' => $html,
        ];
        $sent = \CRM_Utils_Mail::send($mailParams);
        if (!$sent) {
            throw new \RuntimeException("Mailer failed to send to {$recipient['email']}");
        }
    }

    /**
     * One *Sent Automated Email* per project, so the digest appears in each
     * case's timeline (spec §Outputs).
     *
     * The `digest_round` is written into the activity details as a machine
     * marker, because P2-1's idempotency guard needs to ask "did this VC get
     * this round" without parsing prose — mirroring how LifecycleMailer stores
     * its `<!--mas-lifecycle-->` metadata.
     */
    private static function recordOnCase(
        int $caseId,
        int $vcContactId,
        string $subject,
        string $html,
        string $round
    ): int {
        // `recipient_contact_id` LAST, deliberately: markerFragmentsFor()
        // matches `"recipient_contact_id":N}` including the closing brace, so
        // that contact 763 is not treated as already-mailed because 7634 was.
        // Reordering these keys breaks that match silently — the VC is simply
        // mailed again. Asserted by
        // tests/Unit/Service/VcDigestSubjectSafetyTest.php.
        $marker = '<!--mas-digest ' . json_encode([
            'template_title' => self::TEMPLATE_TITLE,
            'digest_round' => $round,
            'recipient_contact_id' => $vcContactId,
        ]) . ' -->';

        $sourceId = (int) \Civi::settings()->get('mascode_admin_contact_id') ?: $vcContactId;

        $activity = \Civi\Api4\Activity::create(false)
            ->addValue('activity_type_id:name', LifecycleMailer::TYPE_SENT)
            ->addValue('status_id:name', 'Completed')
            ->addValue('case_id', $caseId)
            ->addValue('source_contact_id', $sourceId)
            ->addValue('target_contact_id', [$vcContactId])
            ->addValue('subject', $subject)
            ->addValue('details', $marker . "\n" . $html)
            ->execute()
            ->first();

        return (int) $activity['id'];
    }
}
