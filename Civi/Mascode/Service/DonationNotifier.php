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
 *  - donation_notify__vc: a donation with a Linked VC, WITHOUT the amount
 *    (Brian 2026-10-03, TBC with the Treasurer).
 *
 * Gates:
 *  - setting `mascode_donation_notify_enabled`, default off, so a deploy sends
 *    nothing until the office turns it on;
 *  - recipients from settings `mascode_donation_notify_ed_contact_id` and
 *    `mascode_donation_notify_treasurer_contact_id`; an unset recipient is
 *    skipped with a log line;
 *  - only contributions CREATED within RECENT_DAYS, so editing an old
 *    donation (or the DN-5 backfill) never mails about history.
 *
 * Idempotency: each send is recorded as a "Sent Automated Email" activity whose
 * details carry a marker naming the contribution and template, and a send is
 * skipped while that marker exists. Only the VC's activity is filed on the
 * Project case: the VC Portal lists every case activity subject, and the ED
 * and Treasurer subjects carry the amount.
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
            self::TEMPLATE_VC => (int) ($d[DonationLinker::FIELD_VC] ?? 0),
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
                \Civi::log()->error("DonationNotifier: $template for contribution $contributionId failed: " . $e->getMessage());
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
        $created = strtotime((string) ($d['created_date'] ?? ''));
        if (!$created || $created < strtotime('-' . self::RECENT_DAYS . ' days')) {
            return null;
        }
        return $d;
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

        [$domainName, $domainEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();
        $mail = [
            'from' => "\"{$domainName}\" <{$domainEmail}>",
            'toName' => $recipient['display_name'],
            'toEmail' => $email,
            'subject' => $subject,
            'html' => $html,
        ];
        if (!\CRM_Utils_Mail::send($mail)) {
            throw new \RuntimeException("mailer failed for $email");
        }

        $activity = \Civi\Api4\Activity::create(false)
            ->addValue('activity_type_id:name', LifecycleMailer::TYPE_SENT)
            ->addValue('status_id:name', 'Completed')
            ->addValue('source_contact_id', SystemContact::id())
            ->addValue('target_contact_id', [$recipientId])
            ->addValue('subject', $subject)
            ->addValue('details', sprintf(self::MARKER, (int) $d['id'], $templateTitle) . "\n" . $html);
        if ($templateTitle === self::TEMPLATE_VC && !empty($d[DonationLinker::FIELD_PROJECT])) {
            $activity->addValue('case_id', (int) $d[DonationLinker::FIELD_PROJECT]);
        }
        $activity->execute();
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
        $tp->addMessage('subject', $template['msg_subject'] ?? '', 'text/plain');
        $tp->addMessage('body', $template['msg_html'] ?? '', 'text/html');
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
            $out["%%mas_donation.$k%%"] = $html ? htmlspecialchars($v) : $v;
        }
        return $out;
    }
}
