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
     *   - activity_id (int, optional — adds the activity to the token
     *     context so {activity.*} tokens render, e.g. PD answers)
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

        // Idempotency guard. CiviCRM saves a case several times during one
        // status change, so CiviRules' changed_case trigger multi-fires and
        // delayed actions get queued in duplicate. Propose: skip while an
        // unsent draft of this template already sits on the case. Auto: skip
        // if this template was already sent for this case within 23 hours.
        $dupeId = self::findDuplicate($caseId, $template['msg_title'], $mode);
        if ($dupeId) {
            \Civi::log()->info('LifecycleMailer.php - Skipped duplicate lifecycle email', [
                'case_id' => $caseId,
                'template' => $template['msg_title'],
                'mode' => $mode,
                'existing_activity_id' => $dupeId,
            ]);
            return [
                'activity_id' => $dupeId,
                'mode' => $mode,
                'recipient_email' => '',
                'subject' => '',
                'skipped' => true,
            ];
        }

        $recipient = self::loadRecipient($recipientId);
        $activityId = (int) ($params['activity_id'] ?? 0) ?: null;
        [$subject, $html] = self::render($template, $recipientId, $caseId, $activityId);

        if ($mode === 'auto') {
            // Final placeholder pass before the mail leaves (no draft step
            // to re-resolve later).
            $subject = self::resolveActivityFieldPlaceholders($subject, $activityId);
            $html = self::resolveActivityFieldPlaceholders($html, $activityId);
            $subject = self::resolveCaseFieldPlaceholders($subject, $caseId);
            $html = self::resolveCaseFieldPlaceholders($html, $caseId);
            self::sendMail($recipient, $subject, $html);
            $createdId = self::createActivity(
                self::TYPE_SENT,
                'Completed',
                $caseId,
                $sourceId,
                $recipientId,
                $subject,
                $html,
                $template,
                $recipient['email'],
                $activityId
            );
        } else {
            $createdId = self::createActivity(
                self::TYPE_DRAFT,
                'Scheduled',
                $caseId,
                $sourceId,
                $recipientId,
                $subject,
                $html,
                $template,
                $recipient['email'],
                $activityId
            );
        }
        $activityId = $createdId;

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
        // Lazily resolve placeholders that were empty at draft time (custom
        // data not yet committed when the proposing rule fired): %%mas_activity%%
        // against the context activity, %%mas_case%% against the draft's case.
        $contextActivityId = (int) ($meta['context_activity_id'] ?? 0) ?: null;
        $draftCaseId = (int) ($draft['case_id'] ?? 0);
        $sendSubject = self::resolveActivityFieldPlaceholders((string) $draft['subject'], $contextActivityId);
        $sendSubject = self::resolveCaseFieldPlaceholders($sendSubject, $draftCaseId);
        $html = self::resolveActivityFieldPlaceholders($html, $contextActivityId);
        $html = self::resolveCaseFieldPlaceholders($html, $draftCaseId);
        self::sendMail($recipient, $sendSubject, $html);

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
            $sendSubject,
            $html,
            ['id' => $meta['template_id'] ?? null, 'msg_title' => $meta['template_title'] ?? ''],
            $recipient['email'],
            $contextActivityId
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

    /**
     * Find an activity that makes this draft/send a duplicate.
     *
     * Propose: any not-yet-sent (non-Completed) draft of the same template on
     * the same case. Auto: a 'Sent Automated Email' of the same template on
     * the same case within the last 23 hours.
     */
    private static function findDuplicate(int $caseId, string $templateTitle, string $mode): ?int
    {
        $marker = '"template_title":' . json_encode($templateTitle);
        $get = \Civi\Api4\Activity::get(false)
            ->addSelect('id')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('details', 'LIKE', '%' . $marker . '%')
            ->addOrderBy('id', 'DESC')
            ->setLimit(1);
        if ($mode === 'auto') {
            $get->addWhere('activity_type_id:name', '=', self::TYPE_SENT)
                ->addWhere('created_date', '>', date('Y-m-d H:i:s', strtotime('-23 hours')));
        } else {
            $get->addWhere('activity_type_id:name', '=', self::TYPE_DRAFT)
                ->addWhere('status_id:name', '!=', 'Completed');
        }
        $row = $get->execute()->first();
        return $row ? (int) $row['id'] : null;
    }

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
     * Render subject + html body with full case/contact/custom/vc token
     * support; activity tokens too when an activity id is in context.
     */
    private static function render(array $template, int $contactId, int $caseId, ?int $activityId = null): array
    {
        $schema = ['contactId', 'caseId'];
        if ($activityId) {
            $schema[] = 'activityId';
        }
        $tp = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
            'controller' => self::class,
            'smarty' => false,
            'schema' => $schema,
        ]);
        $tp->addMessage('subject', $template['msg_subject'] ?? '', 'text/plain');
        $tp->addMessage('body', $template['msg_html'] ?? '', 'text/html');
        $row = ['contactId' => $contactId, 'caseId' => $caseId];
        if ($activityId) {
            $row['activityId'] = $activityId;
        }
        $tp->addRow($row);
        $tp->evaluate();
        $row = $tp->getRow(0);
        $subject = self::resolveActivityFieldPlaceholders($row->render('subject'), $activityId, false);
        $body = self::resolveActivityFieldPlaceholders($row->render('body'), $activityId, false);
        $subject = self::resolveCaseFieldPlaceholders($subject, $caseId, false);
        $body = self::resolveCaseFieldPlaceholders($body, $caseId, false);
        return [$subject, $body];
    }

    /**
     * Resolve %%mas_case.<CustomGroup>.<field>%% placeholders against the
     * case, by NAME — the portable way to embed case custom fields (core
     * {case.custom_N} tokens are id-based and don't port dev→prod; name-based
     * case tokens don't resolve in this CiviCRM version).
     *
     * Like the activity resolver: at draft time (non-final) an empty value is
     * LEFT IN PLACE — the proposing rule can fire before the same submission's
     * case custom data is committed — and is resolved for real at click-send.
     */
    private static function resolveCaseFieldPlaceholders(string $text, int $caseId, bool $final = true): string
    {
        if (!preg_match_all('/%%mas_case\.([A-Za-z0-9_]+\.[A-Za-z0-9_]+)%%/', $text, $m)) {
            return $text;
        }
        $fields = array_unique($m[1]);
        $values = [];
        if ($caseId) {
            try {
                $values = \Civi\Api4\CiviCase::get(false)
                    ->setSelect($fields)
                    ->addWhere('id', '=', $caseId)
                    ->execute()
                    ->first() ?: [];
            } catch (\Throwable $e) {
                \Civi::log()->warning('LifecycleMailer.php - Case placeholder resolution failed: ' . $e->getMessage(), [
                    'case_id' => $caseId,
                ]);
            }
        }
        foreach ($fields as $field) {
            $value = (string) ($values[$field] ?? '');
            if ($value === '' && !$final) {
                continue;
            }
            $text = str_replace('%%mas_case.' . $field . '%%', nl2br(htmlspecialchars($value)), $text);
        }
        return $text;
    }

    /**
     * Resolve %%mas_activity.<CustomGroup>.<field>%% placeholders against the
     * context activity, by NAME (core activity custom tokens are id-based —
     * {activity.custom_N} — which doesn't port across environments).
     * %%-delimited so the TokenProcessor doesn't blank them as unknown
     * {curly} tokens before this resolver runs.
     *
     * Non-final pass (draft rendering): placeholders whose values are still
     * empty are LEFT IN PLACE — hook_civicrm_post fires before custom data
     * is committed, so the rule-time render often can't see the values yet.
     * Final pass (sending): every placeholder is replaced, empty or not.
     */
    private static function resolveActivityFieldPlaceholders(string $text, ?int $activityId, bool $final = true): string
    {
        if (!preg_match_all('/%%mas_activity\.([A-Za-z0-9_]+\.[A-Za-z0-9_]+)%%/', $text, $m)) {
            return $text;
        }
        $fields = array_unique($m[1]);
        $values = [];
        if ($activityId) {
            try {
                $values = \Civi\Api4\Activity::get(false)
                    ->setSelect($fields)
                    ->addWhere('id', '=', $activityId)
                    ->execute()
                    ->first() ?: [];
            } catch (\Throwable $e) {
                \Civi::log()->warning('LifecycleMailer.php - Activity placeholder resolution failed: ' . $e->getMessage(), [
                    'activity_id' => $activityId,
                ]);
            }
        }
        foreach ($fields as $field) {
            $value = (string) ($values[$field] ?? '');
            if ($value === '' && !$final) {
                continue;
            }
            $text = str_replace('%%mas_activity.' . $field . '%%', nl2br(htmlspecialchars($value)), $text);
        }
        return $text;
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

    private static function createActivity(
        string $type,
        string $status,
        int $caseId,
        int $sourceId,
        int $targetId,
        string $subject,
        string $html,
        array $template,
        string $recipientEmail,
        ?int $contextActivityId = null
    ): int {
        $meta = self::META_PREFIX . json_encode([
            'template_id' => $template['id'] ?? null,
            'template_title' => $template['msg_title'] ?? '',
            'recipient_contact_id' => $targetId,
            'recipient_email' => $recipientEmail,
            // Source activity for {mas_activity.*} placeholders. They may not
            // have resolved at draft time (hook_civicrm_post fires before
            // custom data is committed), so sendDraft() re-resolves lazily.
            'context_activity_id' => $contextActivityId,
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
