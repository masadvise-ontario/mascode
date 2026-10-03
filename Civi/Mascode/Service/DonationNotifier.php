<?php

declare(strict_types=1);

namespace Civi\Mascode\Service;

use Civi\Mascode\Util\SystemContact;

/**
 * Donation notification fan-out (donations ticket DN-3; spec BrianPKM
 * 3-Resources/mas-donation-process.md decision D-E and §4 Q4).
 *
 * One donation produces up to three emails, each sent once:
 *  - donation_notify__ed and __treasurer: every donation.
 *  - donation_notify__vc: a CLIENT donation (organization donor) linked to a
 *    Project case, WITHOUT the amount (Brian 2026-10-03, TBC with the
 *    Treasurer). The VC template may use only VC_SAFE_PLACEHOLDERS, checked
 *    when it is sent. See vcRecipient() and vcTemplateViolations().
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
 * donation created and received in the last RECENT_DAYS sends the notices it
 * never had. To avoid that backlog, enable it right after the last donation
 * you do NOT want announced, or accept the catch-up.
 *
 * Idempotency: each send is recorded as a "Sent Automated Email" activity whose
 * details carry a marker naming the contribution and template, and a send is
 * skipped while that marker exists. Only the VC's activity is filed on the
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
     * The only %%mas_donation.*%% placeholders the VC notice may use. An
     * ALLOWLIST, because the danger is not only "amount": the free-text Source
     * and the reference routinely hold amounts and other people's names. Any
     * other placeholder, or any core {contribution.*} token, refuses the send.
     */
    public const VC_SAFE_PLACEHOLDERS = ['donor', 'project', 'project_code', 'vc', 'received'];

    /** Placeholders are swapped to this prefix before core renders tokens; see render(). */
    private const SENTINEL = "\x1Emas_donation.";

    private const MARKER = '<!--mas-donation-notify c=%d t=%s -->';

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
            self::TEMPLATE_ED => (int) \Civi::settings()->get(self::SETTING_ED),
            self::TEMPLATE_TREASURER => (int) \Civi::settings()->get(self::SETTING_TREASURER),
            self::TEMPLATE_VC => self::vcRecipient($d),
        ];
        $out = [];
        foreach ($plan as $template => $recipientId) {
            if (!$recipientId) {
                $out[$template] = 'no recipient';
                continue;
            }
            if (self::alreadySent($contributionId, $template)) {
                $out[$template] = 'already';
                continue;
            }
            try {
                self::send($d, $template, $recipientId);
                $out[$template] = 'sent';
            }
            catch (\Throwable $e) {
                // One failed recipient must not stop the others, nor fail the
                // contribution save that triggered us.
                $prev = $e->getPrevious() ? ' (after: ' . $e->getPrevious()->getMessage() . ')' : '';
                \Civi::log()->error("DonationNotifier: $template for contribution $contributionId failed: " . $e->getMessage() . $prev);
                $out[$template] = 'error: ' . $e->getMessage();
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
                'payment_instrument_id:label', 'contribution_status_id:name', 'contribution_status_id:label',
                DonationLinker::FIELD_PROJECT, DonationLinker::FIELD_VC,
                DonationLinker::FIELD_PROJECT . '.subject',
                DonationLinker::FIELD_PROJECT . '.case_type_id:name',
                DonationLinker::FIELD_PROJECT . '.Projects.MAS_Project_Case_Code',
                DonationLinker::FIELD_VC . '.display_name'
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
        $limits = ['created_date' => self::RECENT_DAYS, 'receive_date' => self::RECEIVED_WITHIN_DAYS];
        foreach ($limits as $field => $days) {
            $ts = strtotime((string) ($d[$field] ?? ''));
            if (!$ts || $ts < strtotime("-$days days")) {
                return null;
            }
        }
        return $d;
    }

    /**
     * Who gets the VC notice: the Linked VC of a CLIENT donation with a Linked
     * Project, else nobody (0).
     *
     * Client only, per the ticket (DN-3). A private donation can carry a
     * project link (an individual mentions the work), and sending it would put
     * a private donor's name in front of the VC and on the case, which every
     * in-scope VC sees in the Portal. A legacy "Donation" counts as client when
     * the donor is an Organization, the same rule the reports use.
     */
    public static function vcRecipient(array $d): int
    {
        // Organization donor for BOTH types: an individual's gift mis-typed as
        // Client Donation must not put that person's name in front of the VC.
        $isClient = in_array($d['financial_type_id:name'] ?? '', ['Client Donation', 'Donation'], true)
            && ($d['contact_id.contact_type'] ?? '') === 'Organization';
        if (!$isClient || !self::linkedToProject($d)) {
            return 0;
        }
        return (int) ($d[DonationLinker::FIELD_VC] ?? 0);
    }

    /**
     * The case a notice's activity is filed on: the VC notice only.
     *
     * The ED and Treasurer notices carry the amount, and the VC Portal lists
     * every case activity subject, so they must never be filed on a case.
     * Pure, so DonationRulesTest pins it.
     */
    public static function caseIdFor(string $templateTitle, array $d): ?int
    {
        if ($templateTitle !== self::TEMPLATE_VC || !self::linkedToProject($d)) {
            return null;
        }
        return (int) $d[DonationLinker::FIELD_PROJECT];
    }

    /**
     * Linked to a PROJECT case. The picker offers only Projects, but an API
     * write can link any case, and the VC notice must not be filed on one.
     */
    private static function linkedToProject(array $d): bool
    {
        return !empty($d[DonationLinker::FIELD_PROJECT])
            && ($d[DonationLinker::FIELD_PROJECT . '.case_type_id:name'] ?? '') === 'project';
    }

    /**
     * TRUE when a template's text would show the amount: used to keep
     * amounts out of every SUBJECT (DonationDeclarationsTest).
     */
    public static function showsAmount(string $text): bool
    {
        return (bool) preg_match('/%%mas_donation\.(amount|fee|net)%%|\{contribution\.[a-z_]*amount/', $text);
    }

    /**
     * What in a template the VC notice may NOT use: any placeholder outside
     * VC_SAFE_PLACEHOLDERS, and any core {contribution.*} token. Checked at
     * send time, because the declaration is `update => 'unmodified'`: once
     * staff edit it in the UI the unit test no longer sees what is sent.
     *
     * @return string[] the offending placeholders/tokens; empty means safe
     */
    public static function vcTemplateViolations(string $text): array
    {
        preg_match_all('/%%mas_donation\.([A-Za-z_]+)%%|\{contribution\.[^}]*\}/', $text, $m, PREG_SET_ORDER);
        $bad = [];
        foreach ($m as $hit) {
            if (!isset($hit[1]) || $hit[1] === '' || !in_array($hit[1], self::VC_SAFE_PLACEHOLDERS, true)) {
                $bad[] = $hit[0];
            }
        }
        return array_values(array_unique($bad));
    }

    private static function alreadySent(int $contributionId, string $template): bool
    {
        return (bool) \Civi\Api4\Activity::get(false)
            ->selectRowCount()
            ->addWhere('activity_type_id:name', '=', LifecycleMailer::TYPE_SENT)
            ->addWhere('details', 'LIKE', '%' . sprintf(self::MARKER, $contributionId, $template) . '%')
            ->execute()
            ->countMatched();
    }

    private static function send(array $d, string $templateTitle, int $recipientId): void
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
            $bad = self::vcTemplateViolations(($template['msg_subject'] ?? '') . ($template['msg_html'] ?? ''));
            if ($bad) {
                throw new \RuntimeException("template '$templateTitle' uses " . implode(', ', $bad)
                    . '; the VC notice may use only ' . implode(', ', self::VC_SAFE_PLACEHOLDERS) . ' (spec §4 Q4). Not sent.');
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

        [$subject, $html] = self::render($template, $recipientId, $d);

        // Marker FIRST, then mail. If the mail fails the marker is removed, so
        // the next save retries. The reverse order risks a sent email with no
        // marker (e.g. the activity create throws), which re-sends on every
        // later save.
        $activity = \Civi\Api4\Activity::create(false)
            ->addValue('activity_type_id:name', LifecycleMailer::TYPE_SENT)
            ->addValue('status_id:name', 'Completed')
            ->addValue('source_contact_id', SystemContact::id())
            ->addValue('target_contact_id', [$recipientId])
            ->addValue('subject', $subject)
            ->addValue('details', sprintf(self::MARKER, (int) $d['id'], $templateTitle) . "\n" . self::activityBody($templateTitle, $html, $d));
        $caseId = self::caseIdFor($templateTitle, $d);
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
     * What the activity keeps of the email. The VC notice carries no amount
     * and is the VC's own record on the case, so it keeps the email. The ED
     * and Treasurer notices keep only a pointer: the email holds the amount
     * and donor, and the contribution is where staff read them.
     */
    private static function activityBody(string $templateTitle, string $html, array $d): string
    {
        if ($templateTitle === self::TEMPLATE_VC) {
            return $html;
        }
        return '<p>Donation notification emailed for contribution #' . (int) $d['id']
            . '. Open the contribution for the details.</p>';
    }

    /**
     * Render recipient contact tokens through core, then the donation's own
     * %%mas_donation.*%% placeholders (escaped). Contribution tokens are not
     * used: the project and VC are custom EntityReference fields, and core
     * exposes custom tokens by numeric id, which does not port dev → prod.
     *
     * @return array{0:string,1:string} subject, html
     */
    public static function render(array $template, int $recipientId, array $d): array
    {
        $tp = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
            'controller' => self::class,
            'smarty' => false,
            'schema' => ['contactId'],
        ]);
        // Placeholders become a control-character sentinel BEFORE core renders
        // the contact tokens, and are filled after. So a contact whose name
        // contains "%%mas_donation.amount%%" cannot pull the amount in, and a
        // donor name containing "{contact.…}" is never evaluated as a token.
        $hide = static fn(string $t) => str_replace('%%mas_donation.', self::SENTINEL, $t);
        $tp->addMessage('subject', $hide($template['msg_subject'] ?? ''), 'text/plain');
        $tp->addMessage('body', $hide($template['msg_html'] ?? ''), 'text/html');
        $tp->addRow(['contactId' => $recipientId]);
        $tp->evaluate();
        $row = $tp->getRow(0);

        $values = self::placeholderValues($d);
        $subject = strtr($row->render('subject'), self::wrap($values, false));
        $html = strtr($row->render('body'), self::wrap($values, true));
        return [$subject, $html];
    }

    /** @return array<string,string> placeholder name => plain-text value */
    public static function placeholderValues(array $d): array
    {
        $money = static fn($v) => \Civi::format()->money((float) ($v ?? 0), $d['currency'] ?? 'CAD');
        $subject = (string) ($d[DonationLinker::FIELD_PROJECT . '.subject'] ?? '');
        // The case-code field, not the subject: pre-2024 subjects omit the "P".
        $code = (string) ($d[DonationLinker::FIELD_PROJECT . '.Projects.MAS_Project_Case_Code'] ?? '');
        $cid = (int) $d['id'];
        return [
            'donor' => (string) ($d['contact_id.display_name'] ?? ''),
            'type' => (string) ($d['financial_type_id:label'] ?? ''),
            'amount' => $money($d['total_amount']),
            'fee' => $money($d['fee_amount']),
            'net' => $money($d['net_amount']),
            'received' => $d['receive_date'] ? date('Y-m-d', strtotime((string) $d['receive_date'])) : '',
            'method' => (string) ($d['payment_instrument_id:label'] ?? ''),
            'status' => (string) ($d['contribution_status_id:label'] ?? ''),
            'reference' => trim((string) ($d['check_number'] ?: $d['trxn_id'] ?: '')),
            'source' => (string) ($d['source'] ?? ''),
            'project_code' => $code,
            'project' => $subject !== '' ? $subject : '(no project linked)',
            'vc' => (string) ($d[DonationLinker::FIELD_VC . '.display_name'] ?? '') ?: '(none)',
            'link' => \CRM_Utils_System::url(
                'civicrm/contact/view/contribution',
                "reset=1&action=view&id={$cid}&cid=" . (int) $d['contact_id'],
                true, null, false, false, true
            ),
        ];
    }

    /** @return array<string,string> '%%mas_donation.x%%' => value */
    private static function wrap(array $values, bool $html): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out[self::SENTINEL . "$k%%"] = $html ? htmlspecialchars($v) : $v;
        }
        return $out;
    }
}
