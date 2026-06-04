<?php

declare(strict_types=1);

// file: Civi/Mascode/Service/LifecycleMailer.php

namespace Civi\Mascode\Service;

/**
 * Propose-mode runtime for the MAS engagement lifecycle
 * (BrianPKM 3-Resources/mas-engagement-lifecycle-automation-spec.md).
 *
 * Renders a CiviCRM message template against a case + recipient and either:
 *  - propose: writes a "Draft Email - Needs Review" activity on the case for
 *    the CSM to review and click-send (graduated-autonomy gate), or
 *  - auto: sends the email immediately and records a "Sent Automated Email"
 *    activity for traceability.
 *
 * The CiviRules action (Civi\Mascode\CiviRules\Action\LifecycleEmail) is a
 * thin wrapper; this service is independently callable/testable via cv.
 */
class LifecycleMailer
{
    public const TYPE_DRAFT = 'Draft Email - Needs Review';
    public const TYPE_SENT = 'Sent Automated Email';
    private const META_PREFIX = '<!--mas-lifecycle ';
    private const META_SUFFIX = ' -->';

    /**
     * Render a template for a case + recipient, then draft or send.
     *
     * @param array $params
     *   - case_id (int, required)
     *   - template (int template id, or string msg_title, required)
     *   - recipient_contact_id (int, required)
     *   - source_contact_id (int, optional — defaults to mascode_admin_contact_id)
     *   - mode ('propose'|'auto', default 'propose')
     *
     * @return array{activity_id:int, mode:string, recipient_email:string, subject:string}
     */
    public static function execute(array $params): array
    {
        $caseId = (int) ($params['case_id'] ?? 0);
        $recipientId = (int) ($params['recipient_contact_id'] ?? 0);
        $mode = $params['mode'] ?? 'propose';
        $sourceId = (int) ($params['source_contact_id'] ?? 0)
            ?: (int) \Civi::settings()->get('mascode_admin_contact_id');

        if (!$caseId || !$recipientId || empty($params['template'])) {
            throw new \InvalidArgumentException('LifecycleMailer requires case_id, template, recipient_contact_id');
        }

        $template = self::loadTemplate($params['template']);
        $recipient = self::loadRecipient($recipientId);
        [$subject, $html] = self::render($template, $recipientId, $caseId);

        if ($mode === 'auto') {
            self::sendMail($recipient, $subject, $html);
            $activityId = self::createActivity(
                self::TYPE_SENT,
                'Completed',
                $caseId,
                $sourceId,
                $recipientId,
                $subject,
                $html,
                $template,
                $recipient['email']
            );
        } else {
            $activityId = self::createActivity(
                self::TYPE_DRAFT,
                'Scheduled',
                $caseId,
                $sourceId,
                $recipientId,
                $subject,
                $html,
                $template,
                $recipient['email']
            );
        }

        \Civi::log()->info('LifecycleMailer.php - ' . ($mode === 'auto' ? 'Sent' : 'Drafted') . ' lifecycle email', [
            'case_id' => $caseId,
            'template' => $template['msg_title'],
            'recipient' => $recipient['email'],
            'activity_id' => $activityId,
            'mode' => $mode,
        ]);

        return [
            'activity_id' => $activityId,
            'mode' => $mode,
            'recipient_email' => $recipient['email'],
            'subject' => $subject,
        ];
    }

    /**
     * The click-send path: send a previously drafted lifecycle email.
     *
     * Sends using the draft's stored (already-rendered) subject/body, marks
     * the draft Completed, and records a "Sent Automated Email" activity.
     *
     * @return array{draft_activity_id:int, sent_activity_id:int, recipient_email:string}
     */
    public static function sendDraft(int $draftActivityId): array
    {
        $draft = \Civi\Api4\Activity::get(false)
            ->addSelect('id', 'subject', 'details', 'case_id', 'status_id:name', 'activity_type_id:name')
            ->addWhere('id', '=', $draftActivityId)
            ->execute()
            ->first();

        if (!$draft) {
            throw new \InvalidArgumentException("Activity {$draftActivityId} not found");
        }
        if ($draft['activity_type_id:name'] !== self::TYPE_DRAFT) {
            throw new \InvalidArgumentException("Activity {$draftActivityId} is not a '" . self::TYPE_DRAFT . "' activity");
        }
        if ($draft['status_id:name'] === 'Completed') {
            throw new \InvalidArgumentException("Draft {$draftActivityId} has already been sent");
        }

        $meta = self::parseMeta($draft['details'] ?? '');
        if (empty($meta['recipient_contact_id'])) {
            throw new \RuntimeException("Draft {$draftActivityId} has no recipient metadata");
        }

        $recipient = self::loadRecipient((int) $meta['recipient_contact_id']);
        $html = self::stripMeta($draft['details'] ?? '');
        self::sendMail($recipient, $draft['subject'], $html);

        \Civi\Api4\Activity::update(false)
            ->addValue('status_id:name', 'Completed')
            ->addWhere('id', '=', $draftActivityId)
            ->execute();

        $sourceId = (int) \CRM_Core_Session::getLoggedInContactID()
            ?: (int) \Civi::settings()->get('mascode_admin_contact_id');
        $sentId = self::createActivity(
            self::TYPE_SENT,
            'Completed',
            (int) $draft['case_id'],
            $sourceId,
            (int) $meta['recipient_contact_id'],
            $draft['subject'],
            $html,
            ['id' => $meta['template_id'] ?? null, 'msg_title' => $meta['template_title'] ?? ''],
            $recipient['email']
        );

        \Civi::log()->info('LifecycleMailer.php - Draft sent', [
            'draft_activity_id' => $draftActivityId,
            'sent_activity_id' => $sentId,
            'recipient' => $recipient['email'],
        ]);

        return [
            'draft_activity_id' => $draftActivityId,
            'sent_activity_id' => $sentId,
            'recipient_email' => $recipient['email'],
        ];
    }

    // ---------------------------------------------------------------------

    private static function loadTemplate(int|string $template): array
    {
        $get = \Civi\Api4\MessageTemplate::get(false)
            ->addSelect('id', 'msg_title', 'msg_subject', 'msg_html')
            ->setLimit(1);
        if (is_numeric($template)) {
            $get->addWhere('id', '=', (int) $template);
        } else {
            $get->addWhere('msg_title', '=', $template);
        }
        $row = $get->execute()->first();
        if (!$row) {
            throw new \InvalidArgumentException("Message template '{$template}' not found");
        }
        return $row;
    }

    private static function loadRecipient(int $contactId): array
    {
        $contact = \Civi\Api4\Contact::get(false)
            ->addSelect('id', 'display_name', 'email_primary.email', 'is_deceased', 'do_not_email')
            ->addWhere('id', '=', $contactId)
            ->execute()
            ->first();
        if (!$contact) {
            throw new \InvalidArgumentException("Recipient contact {$contactId} not found");
        }
        if (empty($contact['email_primary.email'])) {
            throw new \RuntimeException("Recipient contact {$contactId} has no primary email");
        }
        return [
            'id' => $contact['id'],
            'display_name' => $contact['display_name'],
            'email' => $contact['email_primary.email'],
        ];
    }

    /**
     * Render subject + html body with full case/contact/custom/vc token support.
     */
    private static function render(array $template, int $contactId, int $caseId): array
    {
        $tp = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
            'controller' => self::class,
            'smarty' => false,
            'schema' => ['contactId', 'caseId'],
        ]);
        $tp->addMessage('subject', $template['msg_subject'] ?? '', 'text/plain');
        $tp->addMessage('body', $template['msg_html'] ?? '', 'text/html');
        $tp->addRow(['contactId' => $contactId, 'caseId' => $caseId]);
        $tp->evaluate();
        $row = $tp->getRow(0);
        return [$row->render('subject'), $row->render('body')];
    }

    private static function sendMail(array $recipient, string $subject, string $html): void
    {
        [$domainName, $domainEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();
        $sent = \CRM_Utils_Mail::send([
            'from' => "\"{$domainName}\" <{$domainEmail}>",
            'toName' => $recipient['display_name'],
            'toEmail' => $recipient['email'],
            'subject' => $subject,
            'html' => $html,
        ]);
        if (!$sent) {
            throw new \RuntimeException("Mailer failed to send to {$recipient['email']}");
        }
    }

    private static function createActivity(
        string $type,
        string $status,
        int $caseId,
        int $sourceId,
        int $targetId,
        string $subject,
        string $html,
        array $template,
        string $recipientEmail
    ): int {
        $meta = self::META_PREFIX . json_encode([
            'template_id' => $template['id'] ?? null,
            'template_title' => $template['msg_title'] ?? '',
            'recipient_contact_id' => $targetId,
            'recipient_email' => $recipientEmail,
        ]) . self::META_SUFFIX;

        $activity = \Civi\Api4\Activity::create(false)
            ->addValue('activity_type_id:name', $type)
            ->addValue('status_id:name', $status)
            ->addValue('case_id', $caseId)
            ->addValue('source_contact_id', $sourceId)
            ->addValue('target_contact_id', [$targetId])
            ->addValue('subject', $subject)
            ->addValue('details', $meta . "\n" . $html)
            ->execute()
            ->first();

        return (int) $activity['id'];
    }

    private static function parseMeta(string $details): array
    {
        $start = strpos($details, self::META_PREFIX);
        if ($start === false) {
            return [];
        }
        $start += strlen(self::META_PREFIX);
        $end = strpos($details, self::META_SUFFIX, $start);
        if ($end === false) {
            return [];
        }
        return json_decode(substr($details, $start, $end - $start), true) ?: [];
    }

    private static function stripMeta(string $details): string
    {
        $start = strpos($details, self::META_PREFIX);
        if ($start === false) {
            return $details;
        }
        $end = strpos($details, self::META_SUFFIX, $start);
        if ($end === false) {
            return $details;
        }
        return ltrim(substr($details, 0, $start) . substr($details, $end + strlen(self::META_SUFFIX)));
    }
}
