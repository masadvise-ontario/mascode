<?php

declare(strict_types=1);

namespace Civi\Mascode\Service;

use Civi\Mascode\Util\SystemContact;

/**
 * Donation notification fan-out (donations ticket DN-3; spec BrianPKM
 * 3-Resources/mas-donation-process.md decision D-E and §4 Q4).
 *
 * One donation produces these emails, each sent once:
 *  - donation_notify__ed and __treasurer: every donation.
 *  - donation_notify__vc: one per linked VC of a CLIENT donation (organization
 *    donor), for the linked Project cases that VC coordinated (R10: one
 *    contribution per cheque, several projects and VCs). Since the Treasurer
 *    demo (2026-10-06, R2) its BODY shows the whole cheque amount; its SUBJECT
 *    never does, because the activity is filed on the case and the VC Portal
 *    lists case activity subjects. Its project placeholders show only that
 *    VC's projects. The body may use only VC_SAFE_PLACEHOLDERS and the subject
 *    only VC_SUBJECT_SAFE_PLACEHOLDERS, checked when it is sent. See
 *    vcRecipients() and vcTemplateViolations().
 *
 * Gates:
 *  - setting `mascode_donation_notify_enabled`, default off, so a deploy sends
 *    nothing until the office turns it on;
 *  - recipients from settings `mascode_donation_notify_ed_contact_id` and
 *    `mascode_donation_notify_treasurer_contact_id`; an unset recipient is
 *    skipped with a log line;
 *  - only contributions CREATED within RECENT_DAYS and RECEIVED within
 *    RECEIVED_WITHIN_DAYS. Editing an old donation, importing history or the
 *    DN-5 backfill never mails about the past, and an old cheque entered late
 *    still reaches the Treasurer.
 *
 * ⚠ Turning the setting on is not "from now on". The next save of any
 * donation created in the last RECENT_DAYS (and received in the last
 * RECEIVED_WITHIN_DAYS) sends the notices it never had. To avoid that backlog, enable it right after the last donation
 * you do NOT want announced, or accept the catch-up.
 *
 * Idempotency: each send is recorded as a "Sent Automated Email" activity whose
 * details carry a marker naming the contribution and template, and a send is
 * skipped while that marker exists. A VC notice is per VC: its marker must
 * also be on an activity whose target is that VC (alreadySent()), which still
 * recognises the notices sent before R10. Only the VC's activity is filed on the
 * Project case, because the VC Portal lists every case activity subject. The
 * ED and Treasurer activities keep only the marker and a pointer, not the
 * email: core shows an activity to anyone who can view one of its contacts,
 * and the email carries the amount and donor.
 *
 * Accepted, not handled: two simultaneous saves of the same contribution can
 * both send (the marker check is not atomic). A notice already sent is not
 * retracted if the donation is later re-typed (e.g. Client → Private).
 *
 * Not LifecycleMailer: that service is case-scoped by contract, and a private
 * donation has no case.
 */
final class DonationNotifier
{
    public const TEMPLATE_ED = 'mas_lifecycle_donation_notify__ed';
    public const TEMPLATE_TREASURER = 'mas_lifecycle_donation_notify__treasurer';
    public const TEMPLATE_VC = 'mas_lifecycle_donation_notify__vc';

    public const SETTING_ENABLED = 'mascode_donation_notify_enabled';
    public const SETTING_ED = 'mascode_donation_notify_ed_contact_id';
    public const SETTING_TREASURER = 'mascode_donation_notify_treasurer_contact_id';

    public const RECENT_DAYS = 90;
    public const RECEIVED_WITHIN_DAYS = 365;

    /**
     * Whether a donation is recent enough to notify about: created within
     * RECENT_DAYS AND received within RECEIVED_WITHIN_DAYS. Shared with
     * DonationLinker::linkHistory(), which must not link a gift whose next
     * save would notify.
     */
    public static function insideWindow(?string $created, ?string $received): bool
    {
        foreach ([[$created, self::RECENT_DAYS], [$received, self::RECEIVED_WITHIN_DAYS]] as [$date, $days]) {
            $ts = strtotime((string) $date);
            if (!$ts || $ts < strtotime("-$days days")) {
                return false;
            }
        }
        return true;
    }

    /**
     * The only %%mas_donation.*%% placeholders the VC notice's BODY may use.
     * An ALLOWLIST: the free-text Source and the reference routinely hold other
     * people's names and other gifts' amounts, and fee/net are the Treasurer's.
     * Any other placeholder, or any core {contribution.*} token, refuses the
     * send. The amount is allowed here since R2 (Treasurer demo 2026-10-06),
     * never in the subject (VC_SUBJECT_SAFE_PLACEHOLDERS).
     */
    public const VC_SAFE_PLACEHOLDERS = ['donor', 'project', 'project_code', 'vc', 'received', 'amount', 'split'];

    /**
     * The VC notice's SUBJECT: no amount. The subject becomes the subject of
     * an activity on the Project case, which every in-scope VC sees listed in
     * the Portal (memory feedback_vc_portal_lists_case_activity_subjects).
     */
    public const VC_SUBJECT_SAFE_PLACEHOLDERS = ['donor', 'project', 'project_code', 'vc', 'received'];

    /** Every placeholder placeholderValues() fills. */
    public const ALL_PLACEHOLDERS = ['donor', 'type', 'amount', 'split', 'fee', 'net', 'received', 'method',
        'status', 'reference', 'source', 'project_code', 'project', 'vc', 'link'];

    /** Types the VC notice treats as client money ("Donation" = legacy, Organization donor). */
    public const CLIENT_TYPES = ['Client Donation', 'Donation'];

    /** A split gift's parts: same donor and cheque number, received this close together. */
    public const SPLIT_WINDOW_DAYS = 31;

    /** Before R10 the VC marker had no `v=`; alreadySent() matches both forms by this prefix. */
    private const MARKER_PREFIX = '<!--mas-donation-notify c=%d t=%s ';

    private static function marker(int $contributionId, string $template, ?int $vcId): string
    {
        return sprintf(self::MARKER_PREFIX, $contributionId, $template) . ($vcId ? "v=$vcId " : '') . '-->';
    }

    /**
     * Send whatever notifications this contribution is due and has not had.
     *
     * @return array<string,string> template => 'sent' | 'already' | reason skipped
     */
    public static function notify(int $contributionId): array
    {
        if (!\Civi::settings()->get(self::SETTING_ENABLED)) {
            return ['*' => 'disabled'];
        }
        $d = self::load($contributionId);
        if (!$d) {
            return ['*' => 'not a notifiable donation'];
        }

        $plan = [
            [self::TEMPLATE_ED, (int) \Civi::settings()->get(self::SETTING_ED), null],
            [self::TEMPLATE_TREASURER, (int) \Civi::settings()->get(self::SETTING_TREASURER), null],
        ];
        $vcs = self::vcRecipients($d);
        foreach ($vcs as $vcId) {
            $plan[] = [self::TEMPLATE_VC, $vcId, $vcId];
        }
        $out = $vcs ? [] : [self::TEMPLATE_VC => 'no recipient'];
        foreach ($plan as [$template, $recipientId, $vcId]) {
            $key = $vcId ? "$template:$vcId" : $template;
            if (!$recipientId) {
                $out[$key] = 'no recipient';
                continue;
            }
            if (self::alreadySent($contributionId, $template, $vcId)) {
                $out[$key] = 'already';
                continue;
            }
            try {
                self::send($d, $template, $recipientId, $vcId);
                $out[$key] = 'sent';
            }
            catch (\Throwable $e) {
                // One failed recipient must not stop the others, nor fail the
                // contribution save that triggered us.
                $prev = $e->getPrevious() ? ' (after: ' . $e->getPrevious()->getMessage() . ')' : '';
                \Civi::log()->error("DonationNotifier: $key for contribution $contributionId failed: " . $e->getMessage() . $prev);
                $out[$key] = 'error: ' . $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * The contribution plus everything the templates show, or NULL when it is
     * not a live, recent donation.
     */
    private static function load(int $contributionId): ?array
    {
        $d = \Civi\Api4\Contribution::get(false)
            ->addSelect(
                'id', 'contact_id', 'contact_id.display_name', 'contact_id.contact_type',
                'total_amount', 'fee_amount', 'net_amount', 'currency', 'receive_date', 'created_date',
                'source', 'check_number', 'trxn_id', 'is_test',
                'financial_type_id:name', 'financial_type_id:label',
                'payment_instrument_id:label', 'payment_instrument_id:name', 'contribution_status_id:name', 'contribution_status_id:label',
                DonationLinker::FIELD_PROJECT, DonationLinker::FIELD_VC
            )
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->first();
        if (!$d || !empty($d['is_test'])) {
            return null;
        }
        if (!in_array($d['financial_type_id:name'], DonationLinker::DONATION_TYPES, true)) {
            return null;
        }
        // A cheque entered Pending counts: it was received. Cancelled/failed do not.
        if (!in_array($d['contribution_status_id:name'], ['Completed', 'Pending', 'In Progress', 'Partially paid'], true)) {
            return null;
        }
        // Both dates matter. created_date alone lets an import of old gifts
        // (DN-9, any CSV import) mail about history: an imported row is created
        // today. receive_date bounds that, but generously, so a cheque that sat
        // in a drawer for months still reaches the Treasurer when it is entered.
        if (!self::insideWindow($d['created_date'] ?? null, $d['receive_date'] ?? null)) {
            return null;
        }
        [$d['split_total'], $d['split_count']] = self::splitOf($d);
        $d['project_ids'] = DonationLinker::ids($d[DonationLinker::FIELD_PROJECT] ?? null);
        $d['vc_ids'] = DonationLinker::ids($d[DonationLinker::FIELD_VC] ?? null);
        // APIv4 cannot join a case's custom fields (the code) through a
        // serialized field, so the projects and VC names are read here.
        $d['projects'] = [];
        if ($d['project_ids']) {
            $cases = \Civi\Api4\CiviCase::get(false)
                ->addSelect('id', 'subject', 'case_type_id:name', 'is_deleted', 'Projects.MAS_Project_Case_Code')
                ->addWhere('id', 'IN', $d['project_ids'])
                ->addWhere('is_deleted', 'IN', [0, 1])
                ->execute()
                ->indexBy('id');
            foreach ($d['project_ids'] as $pid) {
                $c = $cases[$pid] ?? null;
                $d['projects'][$pid] = [
                    'subject' => (string) ($c['subject'] ?? ''),
                    'code' => DonationLinker::codeLabel($c['Projects.MAS_Project_Case_Code'] ?? null, $c['subject'] ?? null, $pid),
                    'live_project' => $c && ($c['case_type_id:name'] ?? '') === 'project' && empty($c['is_deleted']),
                ];
            }
        }
        $d['vc_names'] = $d['vc_ids'] ? \Civi\Api4\Contact::get(false)
            ->addSelect('id', 'display_name')
            ->addWhere('id', 'IN', $d['vc_ids'])
            ->execute()
            ->column('display_name', 'id') : [];
        // vcRecipients() judges these: the picker narrowing is a convenience,
        // so the server decides whether each link is believable. Only live
        // Projects the donor is a client of, and their current-first
        // coordinators (coordinatorsFor()), as for the picker.
        $d['client_projects'] = self::clientProjects($d['projects'], DonationLinker::projectIdsForClient((int) $d['contact_id']));
        $d['coordinators'] = [];
        foreach ($d['client_projects'] as $pid) {
            $d['coordinators'][$pid] = DonationLinker::coordinatorsFor($pid);
        }
        return $d;
    }

    /**
     * R2: a gift split across contributions (one per project) is recognised
     * by its cheque number: same donor, paid by Check with the same cheque
     * number (3+ digits, not all zeros), client types (CLIENT_TYPES), live,
     * not a template, received within
     * SPLIT_WINDOW_DAYS of each other. Only a real cheque number counts: a
     * reference such as "EFT" or "0" shared by two separate gifts would tell
     * one project's VC about the other project's gift (round 6 of PR #76).
     *
     * @return array{0:float,1:int} the whole gift's total and its number of parts (1 = not split)
     */
    private static function splitOf(array $d): array
    {
        $cheque = trim((string) ($d['check_number'] ?? ''));
        $ts = strtotime((string) ($d['receive_date'] ?? ''));
        if (!self::isChequeNumber($cheque, (string) ($d['payment_instrument_id:name'] ?? '')) || !$ts) {
            return [(float) $d['total_amount'], 1];
        }
        $window = self::SPLIT_WINDOW_DAYS * 86400;
        $parts = \Civi\Api4\Contribution::get(false)
            ->addSelect('total_amount')
            ->addWhere('contact_id', '=', (int) $d['contact_id'])
            ->addWhere('check_number', '=', $cheque)
            ->addWhere('payment_instrument_id:name', '=', 'Check')
            ->addWhere('is_test', '=', false)
            ->addWhere('is_template', '=', false)
            // Client types only: the total is shown to a VC, and a private
            // portion of the same cheque is not theirs to see.
            ->addWhere('financial_type_id:name', 'IN', self::CLIENT_TYPES)
            ->addWhere('contribution_status_id:name', 'IN', ['Completed', 'Pending', 'In Progress', 'Partially paid'])
            ->addWhere('receive_date', 'BETWEEN', [date('Y-m-d H:i:s', $ts - $window), date('Y-m-d H:i:s', $ts + $window)])
            ->execute()
            ->getArrayCopy();
        if (count($parts) < 2) {
            return [(float) $d['total_amount'], 1];
        }
        return [array_sum(array_map('floatval', array_column($parts, 'total_amount'))), count($parts)];
    }

    /** A reference that identifies one cheque. Pure, so DonationRulesTest pins it. */
    public static function isChequeNumber(string $number, string $paymentInstrument): bool
    {
        // At least one non-zero digit: a "000" placeholder is not a cheque.
        return $paymentInstrument === 'Check' && preg_match('/^\d{3,}$/', $number) === 1 && ltrim($number, '0') !== '';
    }

    /**
     * Who gets a VC notice: each Linked VC of a CLIENT donation who coordinated
     * at least one of its linked projects (vcProjects()). Pure, so
     * DonationRulesTest pins it.
     *
     * Client only, per the ticket (DN-3). A private donation can carry a
     * project link (an individual mentions the work), and sending it would put
     * a private donor's name in front of the VC and on the case, which every
     * in-scope VC sees in the Portal. A legacy "Donation" counts as client when
     * the donor is an Organization, the same rule the reports use.
     *
     * And only when the link is believable: the project is a live Project, the
     * donor is one of its clients, and the VC is one of its coordinators
     * (current ones when it has any, else past ones; coordinatorsFor()). Since
     * R2 the email carries the amount, so a project or VC picked by mistake
     * must not send it to an unrelated VC (round 7 of PR #76). A gift paid by a
     * parent organization therefore gets no VC notice; the ED and Treasurer
     * notices still go.
     *
     * @param array $d from load(): vc_ids, client_projects, coordinators
     * @return int[]
     */
    public static function vcRecipients(array $d): array
    {
        // Organization donor for BOTH types: an individual's gift mis-typed as
        // Client Donation must not put that person's name in front of the VC.
        $isClient = in_array($d['financial_type_id:name'] ?? '', self::CLIENT_TYPES, true)
            && ($d['contact_id.contact_type'] ?? '') === 'Organization';
        if (!$isClient) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $d['vc_ids'] ?? []),
            static fn($vc) => (bool) self::vcProjects($d, $vc)));
    }

    /**
     * The linked projects a VC notice may be about: live Project cases the
     * donor is a client of, in link order. Pure, so DonationRulesTest pins it.
     *
     * @param array<int,array{live_project:bool}> $projects linked project id => facts, in link order
     * @param int[] $donorProjects live Project cases the donor is a client of
     * @return int[]
     */
    public static function clientProjects(array $projects, array $donorProjects): array
    {
        $donor = array_map('intval', $donorProjects);
        return array_values(array_filter(array_map('intval', array_keys($projects)),
            static fn($pid) => !empty($projects[$pid]['live_project']) && in_array($pid, $donor, true)));
    }

    /**
     * The linked projects a VC's notice is about: live Projects of this donor
     * that the VC coordinated, in link order. Pure.
     *
     * @return int[]
     */
    public static function vcProjects(array $d, int $vcId): array
    {
        $out = [];
        foreach ($d['client_projects'] ?? [] as $pid) {
            if (in_array($vcId, array_map('intval', $d['coordinators'][$pid] ?? []), true)) {
                $out[] = (int) $pid;
            }
        }
        return $out;
    }

    /**
     * The case a notice's activity is filed on: the VC notice only, on the
     * first project that VC coordinated.
     *
     * The ED and Treasurer notices carry the amount, and the VC Portal lists
     * every case activity subject, so they must never be filed on a case.
     * Pure, so DonationRulesTest pins it.
     */
    public static function caseIdFor(string $templateTitle, array $d, ?int $vcId = null): ?int
    {
        if ($templateTitle !== self::TEMPLATE_VC || !$vcId) {
            return null;
        }
        return self::vcProjects($d, $vcId)[0] ?? null;
    }

    /**
     * TRUE when a template's text would show the amount: used to keep
     * amounts out of every SUBJECT. Test-only (DonationDeclarationsTest); at
     * send time the allowlists do this job.
     */
    public static function showsAmount(string $text): bool
    {
        return (bool) preg_match('/%%mas_donation\.(amount|split|fee|net)%%|\{contribution\.[a-z_]*amount/', $text);
    }

    /**
     * What in a template the VC notice may NOT use: any placeholder outside
     * VC_SAFE_PLACEHOLDERS, and any core {contribution.*} token. Checked at
     * send time, because the declaration is `update => 'unmodified'`: once
     * staff edit it in the UI the unit test no longer sees what is sent.
     *
     * @param string[]|null $allowed the placeholders this part may use; NULL = VC_SAFE_PLACEHOLDERS (the body)
     * @return string[] the offending placeholders/tokens; empty means safe
     */
    public static function vcTemplateViolations(string $text, ?array $allowed = null): array
    {
        $allowed ??= self::VC_SAFE_PLACEHOLDERS;
        // Mirror render() rather than pattern-match the raw text: hide every
        // "%%mas_donation." prefix exactly as render() does, then ask which
        // unsafe placeholders it would FILL. A regex over the raw text misses
        // adjacent placeholders ("%%mas_donation.donor%%mas_donation.amount%%"
        // shares a %%), which round 3 of PR #76 showed would leak the amount.
        // Check ONE part at a time (subject, then body): render() fills them
        // separately, and joining them can hide a placeholder at the seam.
        $bad = [];
        if (strpos($text, "\x1E") !== false) {
            $bad[] = 'control character U+001E';
        }
        $hidden = str_replace('%%mas_donation.', "\x1E", $text);
        foreach (array_diff(self::ALL_PLACEHOLDERS, $allowed) as $k) {
            if (strpos($hidden, "\x1E$k%%") !== false) {
                $bad[] = "%%mas_donation.$k%%";
            }
        }
        // Any placeholder start NOT immediately followed by a safe name and %%
        // is refused too. Core renders {contact.*} tokens (with filters such as
        // |default:"…") between this check and the fill, so text after the
        // prefix can become "amount%%" only at render time (round 4 of PR #76).
        // fill() is the real guarantee; this turns the attempt into a refusal
        // with a message instead of a silently unfilled placeholder.
        // Known names followed by %% are settled above (safe ones pass, unsafe
        // ones are already listed), so only the rest is reported here.
        $known = implode('|', array_map(static fn($k) => preg_quote($k, '/'), self::ALL_PLACEHOLDERS));
        if (preg_match_all('/\x1E(?!(?:' . $known . ')%%)[^%\x1E]{0,40}/', $hidden, $m)) {
            foreach ($m[0] as $hit) {
                $bad[] = '%%mas_donation.' . substr($hit, 1) . ' (unsafe, unknown or token-built placeholder)';
            }
        }
        if (preg_match_all('/\{contribution\.[^}]*\}/', $text, $m)) {
            array_push($bad, ...$m[0]);
        }
        return array_values(array_unique($bad));
    }

    private static function alreadySent(int $contributionId, string $template, ?int $vcId = null): bool
    {
        $q = \Civi\Api4\Activity::get(false)
            ->selectRowCount()
            ->addWhere('activity_type_id:name', '=', LifecycleMailer::TYPE_SENT)
            ->addWhere('details', 'LIKE', '%' . sprintf(self::MARKER_PREFIX, $contributionId, $template) . '%');
        if ($vcId) {
            $q->addWhere('target_contact_id', 'CONTAINS', $vcId);
        }
        return (bool) $q->execute()->countMatched();
    }

    private static function send(array $d, string $templateTitle, int $recipientId, ?int $vcId = null): void
    {
        $template = \Civi\Api4\MessageTemplate::get(false)
            ->addSelect('id', 'msg_subject', 'msg_html')
            ->addWhere('msg_title', '=', $templateTitle)
            ->addWhere('is_active', '=', true)
            ->execute()
            ->first();
        if (!$template) {
            throw new \RuntimeException("template '$templateTitle' not found or inactive");
        }
        if ($templateTitle === self::TEMPLATE_VC) {
            $bad = array_merge(
                self::vcTemplateViolations((string) ($template['msg_subject'] ?? ''), self::VC_SUBJECT_SAFE_PLACEHOLDERS),
                self::vcTemplateViolations((string) ($template['msg_html'] ?? ''))
            );
            if ($bad) {
                throw new \RuntimeException("template '$templateTitle' uses " . implode(', ', $bad)
                    . '; the VC notice body may use only ' . implode(', ', self::VC_SAFE_PLACEHOLDERS)
                    . ' and its subject only ' . implode(', ', self::VC_SUBJECT_SAFE_PLACEHOLDERS) . ' (spec §7 R2). Not sent.');
            }
        }
        $recipient = \Civi\Api4\Contact::get(false)
            ->addSelect('display_name', 'email_primary.email', 'do_not_email', 'is_deceased')
            ->addWhere('id', '=', $recipientId)
            ->execute()
            ->first();
        $email = $recipient['email_primary.email'] ?? '';
        if (!$recipient || $email === '' || !empty($recipient['do_not_email']) || !empty($recipient['is_deceased'])) {
            throw new \RuntimeException("recipient $recipientId has no usable email");
        }

        $isVc = $templateTitle === self::TEMPLATE_VC;
        [$subject, $html] = self::render($template, $recipientId, $d,
            $isVc ? self::VC_SAFE_PLACEHOLDERS : null,
            $isVc ? self::VC_SUBJECT_SAFE_PLACEHOLDERS : null,
            $vcId);

        // Marker FIRST, then mail. If the mail fails the marker is removed, so
        // the next save retries. The reverse order risks a sent email with no
        // marker (e.g. the activity create throws), which re-sends on every
        // later save.
        $activity = \Civi\Api4\Activity::create(false)
            ->addValue('activity_type_id:name', LifecycleMailer::TYPE_SENT)
            ->addValue('status_id:name', 'Completed')
            ->addValue('source_contact_id', SystemContact::id())
            ->addValue('target_contact_id', [$recipientId])
            ->addValue('subject', self::activitySubject($templateTitle, $subject, $d))
            ->addValue('details', self::marker((int) $d['id'], $templateTitle, $vcId) . "\n" . self::activityBody($templateTitle, $html, $d));
        $caseId = self::caseIdFor($templateTitle, $d, $vcId);
        if ($caseId) {
            $activity->addValue('case_id', $caseId);
        }
        $activityId = (int) $activity->execute()->first()['id'];

        [$domainName, $domainEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();
        $mail = [
            'from' => "\"{$domainName}\" <{$domainEmail}>",
            'toName' => $recipient['display_name'],
            'toEmail' => $email,
            'subject' => $subject,
            'html' => $html,
        ];
        $sent = false;
        try {
            $sent = \CRM_Utils_Mail::send($mail);
        }
        finally {
            if (!$sent) {
                \Civi\Api4\Activity::delete(false)->addWhere('id', '=', $activityId)->execute();
            }
        }
        if (!$sent) {
            throw new \RuntimeException("mailer failed for $email");
        }
    }

    /**
     * The activity's subject. The VC notice keeps its email subject (project
     * code only). The ED and Treasurer notices get a fixed one: their email
     * subjects name the donor, who may be a private individual, and core
     * shows an activity to anyone who can view one of its contacts.
     */
    public static function activitySubject(string $templateTitle, string $emailSubject, array $d): string
    {
        if ($templateTitle === self::TEMPLATE_VC) {
            return $emailSubject;
        }
        $who = $templateTitle === self::TEMPLATE_TREASURER ? 'Treasurer' : 'ED';
        return "Donation notification ($who) for contribution #" . (int) $d['id'];
    }

    /**
     * What the activity keeps of the email: a pointer, for every notice. The
     * ED and Treasurer emails hold the amount and donor. The VC email holds
     * the amount too since R2, and its activity is filed on the Project case,
     * where other in-scope VCs and the Portal's acl_bypass searches can reach
     * it. The contribution is where staff read the details.
     */
    public static function activityBody(string $templateTitle, string $html, array $d): string
    {
        $who = [self::TEMPLATE_ED => 'ED', self::TEMPLATE_TREASURER => 'Treasurer', self::TEMPLATE_VC => 'VC'][$templateTitle] ?? 'staff';
        return "<p>Donation notification emailed to the $who for contribution #" . (int) $d['id']
            . '. Open the contribution for the details.</p>';
    }

    /**
     * Render recipient contact tokens through core, then the donation's own
     * %%mas_donation.*%% placeholders (escaped). Contribution tokens are not
     * used: the project and VC are custom EntityReference fields, and core
     * exposes custom tokens by numeric id, which does not port dev → prod.
     *
     * @param string[]|null $onlyKeys placeholders the body may fill; NULL = all
     * @param string[]|null $subjectOnlyKeys placeholders the subject may fill; NULL = as $onlyKeys
     * @param int|null $forVc a VC notice: the project placeholders show that VC's projects only
     * @return array{0:string,1:string} subject, html
     */
    public static function render(array $template, int $recipientId, array $d, ?array $onlyKeys = null, ?array $subjectOnlyKeys = null, ?int $forVc = null): array
    {
        $tp = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
            'controller' => self::class,
            'smarty' => false,
            'schema' => ['contactId'],
        ]);
        // Placeholders become a per-render random sentinel BEFORE core renders
        // the contact tokens, and are filled after. So a contact whose name
        // contains "%%mas_donation.amount%%" (or anything else) cannot pull the
        // amount in, since nobody can know the sentinel, and a donor name
        // containing "{contact.…}" is never evaluated as a token. strtr() is
        // single-pass, so filled values are never re-scanned.
        $sentinel = "\x1E" . bin2hex(random_bytes(8)) . '.';
        $hide = static fn(string $t) => str_replace('%%mas_donation.', $sentinel, $t);
        $tp->addMessage('subject', $hide($template['msg_subject'] ?? ''), 'text/plain');
        $tp->addMessage('body', $hide($template['msg_html'] ?? ''), 'text/html');
        $tp->addRow(['contactId' => $recipientId]);
        $tp->evaluate();
        $row = $tp->getRow(0);

        $values = self::placeholderValues($d, $forVc);
        return [
            self::fill($row->render('subject'), $values, $sentinel, false, $subjectOnlyKeys ?? $onlyKeys),
            self::fill($row->render('body'), $values, $sentinel, true, $onlyKeys),
        ];
    }

    /**
     * Fill hidden placeholders in token-rendered text. Pure, so
     * DonationRulesTest pins it.
     *
     * $onlyKeys is the VC notice's real guarantee: values outside it are NOT
     * AVAILABLE to fill, so no template, token, filter or contact value can
     * assemble "amount%%" after the sentinel and get the amount (round 4 of
     * PR #76 did exactly that with {contact.x|default:"amount"}). Whatever is
     * left unfilled goes back to visible "%%mas_donation." text rather than an
     * invisible control character in the mail.
     *
     * @param string[]|null $onlyKeys placeholder names allowed to fill; NULL = all
     */
    public static function fill(string $rendered, array $values, string $sentinel, bool $html, ?array $onlyKeys = null): string
    {
        if ($onlyKeys !== null) {
            $values = array_intersect_key($values, array_flip($onlyKeys));
        }
        $filled = strtr($rendered, self::wrap($values, $html, $sentinel));
        return str_replace($sentinel, '%%mas_donation.', $filled);
    }

    /**
     * @param int|null $forVc a VC notice: project, project_code and vc show
     *   that VC's projects and name only; the amount is still the whole cheque
     * @return array<string,string> placeholder name => plain-text value
     */
    public static function placeholderValues(array $d, ?int $forVc = null): array
    {
        $money = static fn($v) => \Civi::format()->money((float) ($v ?? 0), $d['currency'] ?? 'CAD');
        $projects = $d['projects'] ?? [];
        $shown = $forVc ? array_intersect_key($projects, array_flip(self::vcProjects($d, $forVc))) : $projects;
        $vcNames = $d['vc_names'] ?? [];
        $vcs = $forVc ? [(string) ($vcNames[$forVc] ?? '')] : array_values(array_map('strval', $vcNames));
        $cid = (int) $d['id'];
        return [
            'donor' => (string) ($d['contact_id.display_name'] ?? ''),
            'type' => (string) ($d['financial_type_id:label'] ?? ''),
            'amount' => $money($d['total_amount']),
            // Only this donor's live Projects count: a stray link must not
            // make a VC read "covering 3 projects" when one qualifies.
            'split' => self::splitNote((float) ($d['split_total'] ?? 0), (int) ($d['split_count'] ?? 1), $money, count($d['client_projects'] ?? [])),
            'fee' => $money($d['fee_amount']),
            'net' => $money($d['net_amount']),
            'received' => $d['receive_date'] ? date('Y-m-d', strtotime((string) $d['receive_date'])) : '',
            'method' => (string) ($d['payment_instrument_id:label'] ?? ''),
            'status' => (string) ($d['contribution_status_id:label'] ?? ''),
            'reference' => trim((string) ($d['check_number'] ?: $d['trxn_id'] ?: '')),
            'source' => (string) ($d['source'] ?? ''),
            // The case-code field, not the subject: pre-2024 subjects omit the "P".
            'project_code' => implode(', ', array_column($shown, 'code')),
            'project' => $shown ? implode('; ', array_map(static fn($p) => $p['subject'] !== '' ? $p['subject'] : $p['code'], $shown)) : '(no project linked)',
            'vc' => implode(', ', array_filter($vcs)) ?: '(none)',
            'link' => \CRM_Utils_System::url(
                'civicrm/contact/view/contribution',
                "reset=1&action=view&id={$cid}&cid=" . (int) $d['contact_id'],
                true, null, false, false, true
            ),
        ];
    }

    /**
     * What follows the amount: for a cheque that covers several projects, how
     * many (R10); for a legacy gift split across contributions (R2), the
     * whole gift. Empty otherwise. Pure, so DonationRulesTest pins it.
     *
     * @param callable(float):string $money
     */
    public static function splitNote(float $giftTotal, int $parts, callable $money, int $projects = 1): string
    {
        if ($parts >= 2) {
            return " (part of a single gift of {$money($giftTotal)}, split across $parts contributions)";
        }
        if ($projects >= 2) {
            return " (one gift covering $projects projects)";
        }
        return '';
    }

    /** @return array<string,string> '%%mas_donation.x%%' => value */
    private static function wrap(array $values, bool $html, string $sentinel): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out[$sentinel . "$k%%"] = $html ? htmlspecialchars($v) : $v;
        }
        return $out;
    }
}
