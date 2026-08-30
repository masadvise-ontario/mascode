<?php

// File: Civi/Mascode/Event/AfformSubmitSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Afform\Event\AfformSubmitEvent;
use Civi\Api4\Contact;
use Civi\Api4\MessageTemplate;
use Civi\Token\TokenProcessor;

class AfformSubmitSubscriber extends AutoSubscriber
{
    /**
     * Store entity IDs during form submission processing
     */
    private static array $submissionData = [];

    /**
     * The two VC-facing project forms that let the VC maintain the client rep.
     *
     * Keyed by server_route and valued by afform name, and checked as a pair, the
     * same way $emailForms is checked in onFormSubmit() — a route alone does not
     * identify a form.
     */
    private const VC_CLIENT_REP_FORMS = [
        'civicrm/mas-pdef-vc' => 'afformMASProjectDefinitionVC',
        'civicrm/mas-pclose-vc' => 'afformProjectCloseVCFeedback',
    ];

    /**
     * Afform entity name of the client representative on those two forms.
     *
     * Note this collides by name with the RCS form's Individual2 (the Executive
     * Director), which is why every use of it is gated on VC_CLIENT_REP_FORMS
     * first. The two never appear on the same form.
     */
    private const CLIENT_REP_ENTITY = 'Individual2';

    /**
     * Afform entity name of the project case on those two forms.
     */
    private const CLIENT_REP_CASE_ENTITY = 'Case1';

    /**
     * Relationship type names (not labels) used for the client rep.
     *
     * Names rather than labels: labels are editable in the UI and 'Case
     * Coordinator is' already carries the differing label 'Case Coordinator is
     * (MAS Rep)' on this install, so matching on label is a live portability
     * risk. createRCSRelationshipsPostCommit() still matches on label_a_b; that
     * is pre-existing and deliberately not changed here.
     */
    private const CLIENT_REP_REL_NAME = 'Case Client Rep is';
    private const EMPLOYEE_REL_NAME = 'Employee of';

    /**
     * Written to the description of relationships this subscriber creates for a
     * client rep change, so the provenance is legible in the CiviCRM UI.
     */
    private const CLIENT_REP_REL_DESCRIPTION = 'Created by AfformSubmitSubscriber on a VC project form';

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        // Subscriptions with a priority of > 0 happen before the data is saved to the database.
        // Subscriptions with a priority of < 0 happen after the data is saved to the database.
        // Data is saved to the database in
        //     civicrm/ext/afform/core/afform.php:  $dispatcher->addListener('civi.afform.submit',
        //          ['\Civi\Api4\Action\Afform\Submit', 'processGenericEntity'], 0);
        // NOTE on priorities: core's ContactDedupe behavior subscribes to this same
        // event at 101, so it runs BEFORE both pre-process handlers below and may
        // already have rewritten a record's `id` to a matched contact. Neither
        // handler may therefore treat the record's id as "who is on file" —
        // onClientRepPreProcess() reads that from the case instead.
        return [
            'civi.afform.submit' => [
                ['onClientRepPreProcess', 2],   // Before Afform processes (priority > 0)
                ['onFormSubmitPreProcess', 1],  // Before Afform processes (priority > 0)
                ['onFormSubmit', -100],          // After Afform processes (priority < 0)
            ],
        ];
    }

    /**
     * Pre-process form submission BEFORE Afform saves data
     * Detects when president last name changes and forces creation of new contact
     *
     * @param \Civi\Afform\Event\AfformSubmitEvent $event
     */
    public function onFormSubmitPreProcess(AfformSubmitEvent $event): void
    {
        $afform = $event->getAfform();
        $formRoute = $afform['server_route'] ?? null;
        $formName = $afform['name'] ?? null;

        // Only process RCS form
        if ($formRoute !== 'civicrm/mas-rcs-form' || $formName !== 'afformMASRCSForm') {
            return;
        }

        $entityName = $event->getEntityName();

        // Only process Individual1 (President) and Individual2 (Executive Director)
        if ($entityName !== 'Individual1' && $entityName !== 'Individual2') {
            return;
        }

        $records = $event->getRecords();
        if (empty($records[0]['fields'])) {
            return;
        }

        $fields = $records[0]['fields'];
        $submittedLastName = $fields['last_name'] ?? null;
        $contactId = $records[0]['fields']['id'] ?? null;

        // If no contact ID (new contact) or no last name, nothing to check
        if (empty($contactId) || empty($submittedLastName)) {
            return;
        }

        // Get the current last name of the autofilled contact
        try {
            $contact = \Civi\Api4\Contact::get(false)
                ->addSelect('last_name', 'display_name')
                ->addWhere('id', '=', $contactId)
                ->execute()
                ->first();

            $currentLastName = $contact['last_name'] ?? null;

            // If last names differ, this is a role replacement
            if ($currentLastName !== $submittedLastName) {
                $sessionId = $this->getSessionId();
                $roleLabel = $entityName === 'Individual1' ? 'President' : 'Executive Director';
                $storageKey = $entityName === 'Individual1' ? 'old_president_id' : 'old_executive_director_id';

                \Civi::log()->info('AfformSubmitSubscriber.php - ' . $roleLabel . ' replacement detected in pre-process', [
                    'session_id' => $sessionId,
                    'entity_name' => $entityName,
                    'role' => $roleLabel,
                    'old_contact_id' => $contactId,
                    'old_last_name' => $currentLastName,
                    'new_last_name' => $submittedLastName,
                    'old_contact_display_name' => $contact['display_name'] ?? null
                ]);

                // Store old contact ID for post-processing
                if (!isset(self::$submissionData[$sessionId])) {
                    self::$submissionData[$sessionId] = [];
                }
                self::$submissionData[$sessionId][$storageKey] = $contactId;

                // Remove the ID so Afform creates a NEW contact instead of updating
                unset($records[0]['fields']['id']);
                $event->setRecords($records);

                \Civi::log()->info('AfformSubmitSubscriber.php - Removed contact ID to force new contact creation', [
                    'session_id' => $sessionId,
                    'role' => $roleLabel,
                    'old_contact_id' => $contactId
                ]);
            }
        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Error in pre-process president check', [
                'error' => $e->getMessage(),
                'contact_id' => $contactId
            ]);
        }
    }

    /**
     * Pre-process the client representative on the two VC project forms.
     *
     * The rule, set by MAS: correcting the client rep's EMAIL edits that person's
     * contact record in place; changing their FIRST or LAST NAME means a different
     * human now holds the role, so a new contact is created and the case role moves
     * to them (applyClientRepChange(), after the save).
     *
     * Why "who is on file" is read from the CASE and not from the record id
     * -------------------------------------------------------------------
     * The obvious implementation compares the submitted name against the contact
     * named by $records[0]['fields']['id'], the way onFormSubmitPreProcess() does
     * for the RCS form. That is wrong here, because core's ContactDedupe behavior
     * subscribes to this event at priority 101 and has therefore ALREADY run: if
     * the incoming person happens to match an existing contact under
     * Individual.Supervised, dedupe has rewritten the id to that contact, and a
     * comparison against it finds the names equal and concludes nothing changed.
     * The case role would then silently never move — the one outcome this feature
     * exists to produce. So the current rep is read from the case's own active
     * "Case Client Rep is" role, which no form-level behavior can rewrite.
     *
     * Blank fieldset
     * --------------
     * The client rep fields are deliberately optional: on the 2026-05-30 dev clone
     * 23 of 154 Active project cases carry no active client rep, and a VC must not
     * be blocked from filing a close report because CiviCRM is missing a contact.
     * A wholly blank fieldset therefore has its fields cleared, which makes core's
     * own `empty($record['fields'])` guard in processGenericEntity() skip the
     * entity — otherwise an empty Individual would be created on every submission
     * for those cases.
     *
     * Join ids
     * --------
     * When the id is removed to force a new contact, the submitted Email join's id
     * must go with it. It is the id of the OUTGOING rep's email row, echoed back by
     * the browser from the prefill, and Afform::saveJoins() only overwrites it with
     * a re-derived one when loadJoins() finds an existing row — which for a
     * brand-new contact it does not. The id would then survive into
     * Email::replace(), whose BasicReplaceAction merges the where clause
     * (contact_id = the NEW contact) into the record as a default, moving the
     * outgoing rep's email row onto the incoming contact and leaving the outgoing
     * one with no email address.
     *
     * @param \Civi\Afform\Event\AfformSubmitEvent $event
     */
    public function onClientRepPreProcess(AfformSubmitEvent $event): void
    {
        $afform = $event->getAfform();
        $formRoute = $afform['server_route'] ?? null;
        $formName = $afform['name'] ?? null;

        // Route and name must agree — a route alone does not identify a form.
        if (!isset(self::VC_CLIENT_REP_FORMS[$formRoute]) || self::VC_CLIENT_REP_FORMS[$formRoute] !== $formName) {
            return;
        }
        if ($event->getEntityName() !== self::CLIENT_REP_ENTITY) {
            return;
        }

        $records = $event->getRecords();
        if (!isset($records[0]['fields'])) {
            return;
        }

        $submittedFirst = trim((string) ($records[0]['fields']['first_name'] ?? ''));
        $submittedLast = trim((string) ($records[0]['fields']['last_name'] ?? ''));
        $submittedEmail = trim((string) ($records[0]['joins']['Email'][0]['email'] ?? ''));

        $sessionId = $this->getSessionId();

        // Nothing entered at all: clear the record so core skips the entity rather
        // than creating a blank contact.
        //
        // Reachable only for a case that HAS a rep — i.e. a VC who deliberately
        // emptied a prefilled fieldset, treated here as "leave the existing rep
        // alone". For a case with NO rep, core's own preprocessContact (priority
        // 10, so ahead of this handler) has already set fields to NULL for a
        // contact with no id, name or email, and the isset() guard above returned.
        // Both halves are kept: this one is the reachable case, and relying on
        // core's to cover it would be relying on an ordering that is not ours.
        if ($submittedFirst === '' && $submittedLast === '' && $submittedEmail === '') {
            $records[0]['fields'] = [];
            $records[0]['joins'] = [];
            $event->setRecords($records);
            return;
        }

        try {
            $caseId = (int) ($event->getEntityIds(self::CLIENT_REP_CASE_ENTITY)[0] ?? 0);
            if (!$caseId) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - Client rep submitted with no case in scope; leaving it to Afform', [
                    'session_id' => $sessionId,
                    'afform' => $formName,
                ]);
                return;
            }

            if (!isset(self::$submissionData[$sessionId])) {
                self::$submissionData[$sessionId] = [];
            }

            $currentRepId = $this->getCurrentClientRepId($caseId, $sessionId);

            // No rep on file. Whatever Afform creates or matches becomes the rep;
            // applyClientRepChange() associates it with the case after the save.
            if (!$currentRepId) {
                self::$submissionData[$sessionId]['client_rep_case_id'] = $caseId;
                self::$submissionData[$sessionId]['client_rep_had_none'] = true;
                return;
            }

            $current = \Civi\Api4\Contact::get(false)
                ->addSelect('first_name', 'last_name', 'display_name')
                ->addWhere('id', '=', $currentRepId)
                ->execute()
                ->first();
            if (!$current) {
                return;
            }

            // Case- and whitespace-insensitive: fixing "smith" to "Smith" is a
            // correction to one person's record, not the arrival of a different
            // person, and spawning a duplicate contact for it would be wrong.
            $nameChanged = strcasecmp($submittedFirst, trim((string) ($current['first_name'] ?? ''))) !== 0
                || strcasecmp($submittedLast, trim((string) ($current['last_name'] ?? ''))) !== 0;

            if (!$nameChanged) {
                // Email-only edit (or no edit). Two things still have to be pinned
                // down before handing back to Afform.
                //
                // (1) WRITE TO THE PERSON ON FILE. The record id cannot be trusted
                // even here: ContactDedupe (priority 101) may have pointed it at a
                // DIFFERENT contact that matched the submitted first+last+email —
                // exactly the duplicate-contact shape the mas-vc-sync skill
                // documents. Afform would then write the correction onto that
                // duplicate while the case's actual rep keeps the stale email, with
                // nothing logged. Pinning the id makes "correcting the email edits
                // the person on file" true by construction rather than by luck.
                if ((int) ($records[0]['fields']['id'] ?? 0) !== $currentRepId) {
                    \Civi::log()->info('AfformSubmitSubscriber.php - Re-pinned client rep edit to the case role holder', [
                        'session_id' => $sessionId,
                        'case_id' => $caseId,
                        'submitted_contact_id' => $records[0]['fields']['id'] ?? null,
                        'case_rep_contact_id' => $currentRepId,
                    ]);
                }
                $records[0]['fields']['id'] = $currentRepId;

                // (2) A CLEARED EMAIL MEANS "LEAVE IT ALONE", NOT "DELETE IT".
                // The email field is optional here, and the join allows update and
                // delete, so an empty value reaches saveJoins() either as a blank
                // write onto the rep's primary email row or — when the browser sent
                // no row id — as an Email::delete over the whole where clause.
                // Either way the client rep silently loses their address, which is
                // the same harm the join-id strip below exists to prevent, arriving
                // through the front door. Dropping the join leaves the row untouched.
                if ($submittedEmail === '' && !empty($records[0]['joins']['Email'])) {
                    unset($records[0]['joins']['Email']);
                    \Civi::log()->info('AfformSubmitSubscriber.php - Blank client rep email ignored; existing address left in place', [
                        'session_id' => $sessionId,
                        'case_id' => $caseId,
                        'contact_id' => $currentRepId,
                    ]);
                }

                $event->setRecords($records);
                return;
            }

            self::$submissionData[$sessionId]['old_client_rep_id'] = $currentRepId;
            self::$submissionData[$sessionId]['client_rep_case_id'] = $caseId;

            \Civi::log()->info('AfformSubmitSubscriber.php - Client rep replacement detected in pre-process', [
                'session_id' => $sessionId,
                'afform' => $formName,
                'case_id' => $caseId,
                'old_contact_id' => $currentRepId,
                'old_display_name' => $current['display_name'] ?? null,
                'new_first_name' => $submittedFirst,
                'new_last_name' => $submittedLast,
            ]);

            // Force a new contact, and take the outgoing rep's join ids with it.
            unset($records[0]['fields']['id']);
            foreach (($records[0]['joins'] ?? []) as $joinEntity => $joinRows) {
                $joinIdField = \Civi\Api4\Utils\CoreUtil::getIdFieldName($joinEntity);
                foreach (array_keys((array) $joinRows) as $joinIndex) {
                    unset($records[0]['joins'][$joinEntity][$joinIndex][$joinIdField]);
                }
            }
            $event->setRecords($records);
        } catch (\Exception $e) {
            // Leave the submission to Afform rather than blocking the VC's report.
            // The consequence of failing here is that the case role is not moved,
            // which is visible in this log line and recoverable by hand.
            \Civi::log()->error('AfformSubmitSubscriber.php - Error in client rep pre-process', [
                'session_id' => $sessionId,
                'afform' => $formName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process form submission to collect entity IDs and create relationships
     *
     * @param \Civi\Afform\Event\AfformSubmitEvent $event
     */
    public function onFormSubmit(AfformSubmitEvent $event): void
    {
        $afform = $event->getAfform();
        $formRoute = $afform['server_route'] ?? null;
        $formName = $afform['name'] ?? null;

        // Define forms that should trigger email confirmations
        $emailForms = [
            'civicrm/mas-rcs-form' => 'afformMASRCSForm',
            'civicrm/mas-sasf-form' => 'afformMASSASF',
            'civicrm/mas-sass-form' => 'afformMASSASS',
            'civicrm/mas-pclose-client' => 'afformProjectCloseClientFeedback',
            'civicrm/mas-pclose-vc' => 'afformProjectCloseVCFeedback',
            'civicrm/mas-pdef-vc' => 'afformMASProjectDefinitionVC',
            'civicrm/mas-pdef-client' => 'afformMASProjectDefinitionClient'
        ];

        // Check if this is one of our target forms
        if (!isset($emailForms[$formRoute]) || $emailForms[$formRoute] !== $formName) {
            return;
        }

        $entityName = $event->getEntityName();
        $entityId = $event->getEntityId(0); // Get first record (index 0)

        \Civi::log()->debug('AfformSubmitSubscriber.php - Processing entity', [
            'entity_name' => $entityName,
            'entity_id' => $entityId,
            'all_entity_ids' => $event->getEntityIds($entityName),
            'api_request_class' => get_class($event->getApiRequest())
        ]);

        // Get or create submission tracking data
        $sessionId = $this->getSessionId();
        if (!isset(self::$submissionData[$sessionId])) {
            self::$submissionData[$sessionId] = [];
        }

        // Store form type and entity IDs based on entity name
        self::$submissionData[$sessionId]['form_name'] = $formName;
        self::$submissionData[$sessionId]['form_route'] = $formRoute;

        // Handle different form types
        if ($formRoute === 'civicrm/mas-rcs-form') {
            // RCS Form - existing logic
            switch ($entityName) {
                case 'Organization1':
                    self::$submissionData[$sessionId]['organization_id'] = $entityId;
                    break;
                case 'Individual1': // President
                    self::$submissionData[$sessionId]['president_id'] = $entityId;
                    break;
                case 'Individual2': // Executive Director
                    self::$submissionData[$sessionId]['executive_director_id'] = $entityId;
                    break;
                case 'Individual3': // Primary Contact
                    self::$submissionData[$sessionId]['primary_contact_id'] = $entityId;
                    break;
                case 'Case1':
                    self::$submissionData[$sessionId]['case_id'] = $entityId;

                    // Update case status when processing Case1 (last entity processed)
                    $this->updateCaseStatus($sessionId);

                    // Record the RCS submission as an activity on the Service Request case.
                    // The RCS form (unlike the survey/close forms) has no Activity entity in
                    // its layout, so the activity is created here server-side.
                    $rcsActivityId = $this->createRCSActivity($sessionId);
                    if ($rcsActivityId) {
                        self::$submissionData[$sessionId]['activity_id'] = $rcsActivityId;
                        // Write a readable summary of the request onto the activity.
                        $this->writeSubmissionSummary($sessionId, $rcsActivityId);
                    }

                    // Create relationships (now that CiviRules won't cause rollback)
                    $this->createRCSRelationshipsPostCommit(self::$submissionData[$sessionId], $sessionId);

                    // Send confirmation email
                    $this->sendConfirmationEmail($sessionId);
                    // Clean up after processing
                    unset(self::$submissionData[$sessionId]);
                    break;
            }
        } else {
            // Survey Forms (SASS/SASF) - simpler structure
            switch ($entityName) {
                case 'Organization1':
                    self::$submissionData[$sessionId]['organization_id'] = $entityId;
                    break;
                case 'Individual1': // Primary Contact
                    self::$submissionData[$sessionId]['primary_contact_id'] = $entityId;
                    break;
                case self::CLIENT_REP_ENTITY: // Client rep, VC project forms only
                    // Gated on the form: no other form reaching this branch declares
                    // an Individual2, but the gate is what makes that safe rather
                    // than incidental.
                    if (isset(self::VC_CLIENT_REP_FORMS[$formRoute]) && self::VC_CLIENT_REP_FORMS[$formRoute] === $formName) {
                        // getEntityId() is NOT proof that a contact was saved in
                        // this submission: loadEntities() pre-populates it with the
                        // incumbent rep and ContactDedupe may have rewritten it, while
                        // processGenericEntity() catches and merely LOGS a failed
                        // Contact::save. Reading the id from the save itself means a
                        // silently failed save leaves client_rep_saved false, and the
                        // relationship writes below are skipped rather than moving the
                        // case role onto a contact this submission never wrote to.
                        $savedRepId = (int) ($event->getSaved()[0]['id'] ?? 0);
                        self::$submissionData[$sessionId]['client_rep_id'] = $savedRepId ?: $entityId;
                        self::$submissionData[$sessionId]['client_rep_saved'] = ($savedRepId > 0);
                        // Done here rather than from the Activity1 branch below so it
                        // does not depend on the order Afform happens to process
                        // entities in: everything needed (the new contact id, the old
                        // one from pre-process, and the case) is available right now.
                        $this->applyClientRepChange($sessionId);
                    }
                    break;
                case 'Activity1':
                    self::$submissionData[$sessionId]['activity_id'] = $entityId;
                    // VC forms: the VC (Individual1) isn't related to the client org,
                    // so set the project-owning organization (the case client) as the
                    // activity target here.
                    if (in_array($formRoute, ['civicrm/mas-pclose-vc', 'civicrm/mas-pdef-vc'], true)) {
                        $this->linkProjectOwnerAsTarget($entityId, $sessionId);
                    }
                    // Client PD authorization: the project definition is now
                    // authorized — the project goes Active. TRUE only on the
                    // submission that actually moved it, which is what gates
                    // the VC notice below to one send per project.
                    $pdJustAuthorized = false;
                    if ($formRoute === 'civicrm/mas-pdef-client') {
                        $pdJustAuthorized = $this->advanceCaseToActive($entityId);
                    }
                    // PD and project-close answers live on the CASE, so their
                    // confirmation summary is case-kind — capture the case id
                    // from the activity.
                    if (in_array($formRoute, ['civicrm/mas-pdef-vc', 'civicrm/mas-pdef-client', 'civicrm/mas-pclose-vc', 'civicrm/mas-pclose-client'], true)) {
                        $caseStoredActivity = \Civi\Api4\CaseActivity::get(false)
                            ->addWhere('activity_id', '=', $entityId)
                            ->addSelect('case_id')
                            ->setLimit(1)
                            ->execute()
                            ->first();
                        if (!empty($caseStoredActivity['case_id'])) {
                            self::$submissionData[$sessionId]['case_id'] = $caseStoredActivity['case_id'];
                        }
                    }
                    // Write a readable summary of the answers onto the activity.
                    $this->writeSubmissionSummary($sessionId, $entityId);
                    // Tell the assigned VC their definition was authorized.
                    // Called here rather than from sendConfirmationEmail() so
                    // it doesn't inherit that method's client-shaped guards —
                    // a client with no primary email must not silently cost
                    // the VC their notice.
                    if ($pdJustAuthorized) {
                        $this->sendVcSignoffNotice(self::$submissionData[$sessionId]);
                    }
                    // Client close feedback: share it with the VC who did the
                    // work, if the client said we could. Same placement as the
                    // notice above but NOT the same gate — that one rides on a
                    // one-way status transition, this one has to check for
                    // itself that it hasn't already run (see the method).
                    if ($formRoute === 'civicrm/mas-pclose-client') {
                        $this->sendVcClientFeedback(self::$submissionData[$sessionId]);
                    }
                    // Send confirmation email for survey forms (last entity processed)
                    $this->sendConfirmationEmail($sessionId);
                    // Clean up after processing
                    unset(self::$submissionData[$sessionId]);
                    break;
            }
        }
    }

    /**
     * Fallback submission key, memoised for the life of the request.
     *
     * @see getSessionId()
     */
    private static ?string $fallbackSessionId = null;

    /**
     * Get unique session identifier for this submission.
     *
     * The no-session fallback is MEMOISED rather than recomputed. It used to end
     * in time(), which silently returns a different key either side of a second
     * boundary — and this key is what pairs a pre-process handler with its
     * post-save counterpart across a contact save and an Email write. Observed in
     * the logs: pid 378120 produced both …-1788098597 and …-1788098598 within one
     * run. When that happens mid-submission the stored data is unreachable and the
     * post-save handler returns having done nothing, without logging.
     *
     * Web submissions were never exposed (CRM_Utils_System_WordPress::sessionStart()
     * starts a session for any non-CLI SAPI, so session_id() is non-empty and
     * stable); CLI and `cv scr` were, which is exactly where the tests run.
     * PHP tears class statics down at request shutdown, so nothing leaks between
     * requests.
     */
    private function getSessionId(): string
    {
        $sessionId = session_id();
        if (!$sessionId) {
            // Fallback if no session (e.g., in testing)
            if (self::$fallbackSessionId === null) {
                self::$fallbackSessionId = 'no-session-' . getmypid() . '-' . uniqid();
            }
            $sessionId = self::$fallbackSessionId;
        }
        return $sessionId;
    }

    /**
     * Create relationships for RCS form submission (after transaction commits)
     *
     * @param array $submissionData
     * @param string $sessionId
     */
    protected function createRCSRelationshipsPostCommit(array $submissionData, string $sessionId): void
    {
        try {
            $organizationId = $submissionData['organization_id'] ?? null;

            \Civi::log()->info('AfformSubmitSubscriber.php - Starting RCS relationship creation', [
                'session_id' => $sessionId,
                'submission_data' => $submissionData
            ]);

            if (empty($organizationId)) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No organization ID found for relationship creation', [
                    'session_id' => $sessionId,
                    'submission_data' => $submissionData
                ]);
                return;
            }

            // Get relationship type IDs by name (environment-agnostic)
            $relationshipTypes = \Civi\Api4\RelationshipType::get(false)
                ->addSelect('id', 'label_a_b')
                ->addWhere('label_a_b', 'IN', ['President of', 'Executive Director of', 'Employee of', 'Case Client Rep is'])
                ->execute()
                ->indexBy('label_a_b');

            $presidentTypeId = $relationshipTypes['President of']['id'] ?? null;
            $executiveDirectorTypeId = $relationshipTypes['Executive Director of']['id'] ?? null;
            $employeeTypeId = $relationshipTypes['Employee of']['id'] ?? null;
            $caseClientRepTypeId = $relationshipTypes['Case Client Rep is']['id'] ?? null;

            // Create relationships for Individual1 (President)
            if (!empty($submissionData['president_id'])) {
                try {
                    $this->createRelationshipIfNotExists(
                        $submissionData['president_id'],
                        $organizationId,
                        $employeeTypeId,
                        'Employee of',
                        $sessionId
                    );
                } catch (\Exception $e) {
                    \Civi::log()->error('AfformSubmitSubscriber.php - Failed to create Employee relationship for President', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage()
                    ]);
                }

                try {
                    // Check if this is a president replacement (old president ID stored in pre-process)
                    if (!empty($submissionData['old_president_id'])) {
                        $this->endPresidentRelationship(
                            $submissionData['old_president_id'],
                            $organizationId,
                            $presidentTypeId,
                            $sessionId
                        );
                    }

                    // Now create the new president relationship
                    $this->createRelationshipIfNotExists(
                        $submissionData['president_id'],
                        $organizationId,
                        $presidentTypeId,
                        'President of',
                        $sessionId
                    );
                } catch (\Exception $e) {
                    \Civi::log()->error('AfformSubmitSubscriber.php - Failed to create President relationship', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Create relationships for Individual2 (Executive Director)
            if (!empty($submissionData['executive_director_id'])) {
                try {
                    $this->createRelationshipIfNotExists(
                        $submissionData['executive_director_id'],
                        $organizationId,
                        $employeeTypeId,
                        'Employee of',
                        $sessionId
                    );
                } catch (\Exception $e) {
                    \Civi::log()->error('AfformSubmitSubscriber.php - Failed to create Employee relationship for Executive Director', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage()
                    ]);
                }

                try {
                    // Check if this is an executive director replacement (old executive director ID stored in pre-process)
                    if (!empty($submissionData['old_executive_director_id'])) {
                        $this->endExecutiveDirectorRelationship(
                            $submissionData['old_executive_director_id'],
                            $organizationId,
                            $executiveDirectorTypeId,
                            $sessionId
                        );
                    }

                    // Now create the new executive director relationship
                    $this->createRelationshipIfNotExists(
                        $submissionData['executive_director_id'],
                        $organizationId,
                        $executiveDirectorTypeId,
                        'Executive Director of',
                        $sessionId
                    );
                } catch (\Exception $e) {
                    \Civi::log()->error('AfformSubmitSubscriber.php - Failed to create Executive Director relationship', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Create relationships for Individual3 (Primary Contact)
            if (!empty($submissionData['primary_contact_id'])) {
                try {
                    $this->createRelationshipIfNotExists(
                        $submissionData['primary_contact_id'],
                        $organizationId,
                        $employeeTypeId,
                        'Employee of',
                        $sessionId
                    );
                } catch (\Exception $e) {
                    \Civi::log()->error('AfformSubmitSubscriber.php - Failed to create Employee relationship for Primary Contact', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage()
                    ]);
                }

                // Create Case Client Rep relationship for the case
                if (!empty($submissionData['case_id']) && !empty($caseClientRepTypeId)) {
                    try {
                        $this->createCaseRelationshipIfNotExists(
                            $submissionData['primary_contact_id'],
                            $organizationId,
                            $submissionData['case_id'],
                            $caseClientRepTypeId,
                            'Case Client Rep is',
                            $sessionId
                        );
                    } catch (\Exception $e) {
                        \Civi::log()->error('AfformSubmitSubscriber.php - Failed to create Case Client Rep relationship', [
                            'session_id' => $sessionId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            \Civi::log()->info('AfformSubmitSubscriber.php - RCS relationships created successfully', [
                'session_id' => $sessionId,
                'organization_id' => $organizationId,
                'president_id' => $submissionData['president_id'] ?? null,
                'executive_director_id' => $submissionData['executive_director_id'] ?? null,
                'primary_contact_id' => $submissionData['primary_contact_id'] ?? null,
                'case_id' => $submissionData['case_id'] ?? null
            ]);

        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Exception while creating RCS relationships', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * End president relationship for old president when replacement occurs
     *
     * @param int $oldPresidentId Contact ID of the old president
     * @param int $organizationId Organization contact ID
     * @param int $presidentTypeId Relationship type ID for "President of"
     * @param string $sessionId Session ID for logging
     */
    protected function endPresidentRelationship(
        int $oldPresidentId,
        int $organizationId,
        int $presidentTypeId,
        string $sessionId
    ): void {
        try {
            // Find active "President of" relationship for the old president
            $existingRelationship = \Civi\Api4\Relationship::get(false)
                ->addWhere('contact_id_a', '=', $oldPresidentId)
                ->addWhere('contact_id_b', '=', $organizationId)
                ->addWhere('relationship_type_id', '=', $presidentTypeId)
                ->addWhere('is_active', '=', true)
                ->execute()
                ->first();

            if ($existingRelationship) {
                // End the relationship
                \Civi\Api4\Relationship::update(false)
                    ->addValue('is_active', false)
                    ->addValue('end_date', date('Y-m-d'))
                    ->addWhere('id', '=', $existingRelationship['id'])
                    ->execute();

                \Civi::log()->info('AfformSubmitSubscriber.php - Ended previous President relationship', [
                    'session_id' => $sessionId,
                    'relationship_id' => $existingRelationship['id'],
                    'old_president_id' => $oldPresidentId,
                    'organization_id' => $organizationId,
                    'end_date' => date('Y-m-d')
                ]);
            } else {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No active President relationship found to end', [
                    'session_id' => $sessionId,
                    'old_president_id' => $oldPresidentId,
                    'organization_id' => $organizationId
                ]);
            }

        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Failed to end president relationship', [
                'session_id' => $sessionId,
                'old_president_id' => $oldPresidentId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * End executive director relationship for old executive director when replacement occurs
     *
     * @param int $oldExecutiveDirectorId Contact ID of the old executive director
     * @param int $organizationId Organization contact ID
     * @param int $executiveDirectorTypeId Relationship type ID for "Executive Director of"
     * @param string $sessionId Session ID for logging
     */
    protected function endExecutiveDirectorRelationship(
        int $oldExecutiveDirectorId,
        int $organizationId,
        int $executiveDirectorTypeId,
        string $sessionId
    ): void {
        try {
            // Find active "Executive Director of" relationship for the old executive director
            $existingRelationship = \Civi\Api4\Relationship::get(false)
                ->addWhere('contact_id_a', '=', $oldExecutiveDirectorId)
                ->addWhere('contact_id_b', '=', $organizationId)
                ->addWhere('relationship_type_id', '=', $executiveDirectorTypeId)
                ->addWhere('is_active', '=', true)
                ->execute()
                ->first();

            if ($existingRelationship) {
                // End the relationship
                \Civi\Api4\Relationship::update(false)
                    ->addValue('is_active', false)
                    ->addValue('end_date', date('Y-m-d'))
                    ->addWhere('id', '=', $existingRelationship['id'])
                    ->execute();

                \Civi::log()->info('AfformSubmitSubscriber.php - Ended previous Executive Director relationship', [
                    'session_id' => $sessionId,
                    'relationship_id' => $existingRelationship['id'],
                    'old_executive_director_id' => $oldExecutiveDirectorId,
                    'organization_id' => $organizationId,
                    'end_date' => date('Y-m-d')
                ]);
            } else {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No active Executive Director relationship found to end', [
                    'session_id' => $sessionId,
                    'old_executive_director_id' => $oldExecutiveDirectorId,
                    'organization_id' => $organizationId
                ]);
            }

        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Failed to end executive director relationship', [
                'session_id' => $sessionId,
                'old_executive_director_id' => $oldExecutiveDirectorId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Move the case's client-rep role onto the contact the VC just submitted.
     *
     * Called after Afform has saved the Individual2 record, so the incoming
     * contact exists. Three outcomes, decided by what onClientRepPreProcess()
     * recorded:
     *
     *   - a name change was detected: the outgoing rep's case role is ended and
     *     the incoming contact gets the role plus an "Employee of" link to the
     *     client organisation, mirroring what the RCS form does for a new
     *     President or Executive Director;
     *   - the case had no rep at all: the incoming contact is simply associated;
     *   - neither: an in-place edit (an email correction), already written by
     *     Afform, and there is nothing to move.
     *
     * The outgoing rep's own "Employee of" link is deliberately left active. The
     * role changing hands on one project is not evidence that the person has left
     * the organisation, and MAS reads employment from that relationship elsewhere.
     *
     * Failures are logged, not thrown: the VC's project definition or close report
     * is the point of the submission and must not be lost because a relationship
     * could not be written.
     *
     * @param string $sessionId
     */
    protected function applyClientRepChange(string $sessionId): void
    {
        $data = self::$submissionData[$sessionId] ?? [];
        $newRepId = (int) ($data['client_rep_id'] ?? 0);
        $oldRepId = (int) ($data['old_client_rep_id'] ?? 0);
        $caseId = (int) ($data['client_rep_case_id'] ?? 0);
        $hadNone = !empty($data['client_rep_had_none']);

        // In-place edit: pre-process found the same person, so Afform has already
        // written the change to their record and the case role is unaffected.
        if (!$oldRepId && !$hadNone) {
            return;
        }

        // A replacement was expected but no contact was actually saved. Core's
        // processGenericEntity() catches CRM_Core_Exception and only logs
        // ('Silently ignoring exception on submit'), so the submission looks normal
        // and client_rep_id would still hold the pre-populated or dedupe-matched
        // id. Moving the case role onto that contact would be worse than doing
        // nothing, so stop here and say so loudly.
        if ($oldRepId && empty($data['client_rep_saved'])) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Client rep replacement expected but no contact was saved; case role left unchanged', [
                'session_id' => $sessionId,
                'case_id' => $caseId ?: null,
                'old_contact_id' => $oldRepId,
            ]);
            return;
        }

        if (!$newRepId || !$caseId) {
            \Civi::log()->warning('AfformSubmitSubscriber.php - Cannot apply client rep change; missing ids', [
                'session_id' => $sessionId,
                'new_contact_id' => $newRepId ?: null,
                'old_contact_id' => $oldRepId ?: null,
                'case_id' => $caseId ?: null,
            ]);
            return;
        }

        // Dedupe can legitimately land the "new" rep back on the outgoing contact
        // (same person re-entered). Nothing to move, and ending the role we are
        // about to recreate would be a needless churn of the case history.
        if ($newRepId === $oldRepId) {
            \Civi::log()->info('AfformSubmitSubscriber.php - Client rep resolved to the existing contact; no role change', [
                'session_id' => $sessionId,
                'case_id' => $caseId,
                'contact_id' => $newRepId,
            ]);
            return;
        }

        try {
            $organizationId = $this->getCaseOrganizationId($caseId);
            if (!$organizationId) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - Case has no organization client; cannot move client rep role', [
                    'session_id' => $sessionId,
                    'case_id' => $caseId,
                ]);
                return;
            }

            $relationshipTypes = \Civi\Api4\RelationshipType::get(false)
                ->addSelect('id', 'name_a_b')
                ->addWhere('name_a_b', 'IN', [self::CLIENT_REP_REL_NAME, self::EMPLOYEE_REL_NAME])
                ->execute()
                ->indexBy('name_a_b');

            $clientRepTypeId = $relationshipTypes[self::CLIENT_REP_REL_NAME]['id'] ?? null;
            $employeeTypeId = $relationshipTypes[self::EMPLOYEE_REL_NAME]['id'] ?? null;

            if (empty($clientRepTypeId)) {
                \Civi::log()->error('AfformSubmitSubscriber.php - Relationship type not found; cannot move client rep role', [
                    'session_id' => $sessionId,
                    'relationship_type' => self::CLIENT_REP_REL_NAME,
                ]);
                return;
            }

            // ORDER MATTERS, and it is the opposite of the way the sentence reads.
            //
            // Afform.submit is NOT transactional — Civi\API\Subscriber\
            // TransactionSubscriber returns early for APIv4 — so these two writes
            // commit independently and there is no rollback net. Ending the
            // outgoing role first means anything that interrupts the pair (a hook
            // or CiviRules action vetoing the Relationship create, a deadlock, a
            // max_execution_time kill on shared hosting) leaves the case with NO
            // active client rep: the autofill renders blank on every future form
            // open and lifecycle email to the client rep silently has no
            // recipient, behind a normal confirmation screen.
            //
            // Creating first inverts the failure into two active reps for the same
            // case — visible, self-correcting on the next submission, and already
            // handled by getCurrentClientRepId(). A transient duplicate beats a
            // silent absence.
            $this->createCaseRelationshipIfNotExists(
                $newRepId,
                $organizationId,
                $caseId,
                $clientRepTypeId,
                self::CLIENT_REP_REL_NAME,
                $sessionId,
                self::CLIENT_REP_REL_DESCRIPTION
            );

            // Only now that the incoming role exists, stand the outgoing one down.
            // Scoped to THIS case, so the person keeps the role on the
            // organisation's other projects.
            if ($oldRepId) {
                $this->endCaseClientRepRelationship($oldRepId, $caseId, $clientRepTypeId, $sessionId);
            }

            // Give the incoming rep a standing link to the client organisation, so
            // they look like every other client contact outside this one case.
            $this->createRelationshipIfNotExists(
                $newRepId,
                $organizationId,
                $employeeTypeId,
                self::EMPLOYEE_REL_NAME,
                $sessionId,
                self::CLIENT_REP_REL_DESCRIPTION
            );

            \Civi::log()->info('AfformSubmitSubscriber.php - Client rep role applied', [
                'session_id' => $sessionId,
                'case_id' => $caseId,
                'organization_id' => $organizationId,
                'old_contact_id' => $oldRepId ?: null,
                'new_contact_id' => $newRepId,
            ]);
        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Failed to apply client rep change', [
                'session_id' => $sessionId,
                'case_id' => $caseId,
                'old_contact_id' => $oldRepId ?: null,
                'new_contact_id' => $newRepId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The contact currently holding the client-rep role on a case.
     *
     * Read through RelationshipCache with is_current, which is the same predicate
     * core's ContactAutofillBasedOnCase uses to populate the form — so this
     * answers "whose details is the VC looking at" rather than merely "who has a
     * row".
     *
     * ORDERED BY CACHE ID, deliberately, to match core: ContactAutofillBasedOnCase
     * issues this query with no ORDER BY at all and loads every match, so the
     * non-repeating fieldset displays whichever row the database returned first.
     * Ordering by contact id instead — which an earlier version of this method did
     * — picks a different "first" than the form showed whenever a case carries two
     * reps, so the submitted name gets compared against the wrong person and a real
     * handover reads as an in-place edit. Cache id is the closest stable stand-in
     * for core's natural order.
     *
     * Multiple rows do NOT necessarily mean multiple people: dev case 18734 carries
     * two is_current rows pointing at the SAME contact. The warning is therefore
     * raised on distinct contacts, not on row count. No project case on the
     * 2026-05-30 dev clone had more than one client rep.
     *
     * @param int $caseId
     * @param string $sessionId
     * @return int|null Contact ID, or NULL when the case has no client rep.
     */
    protected function getCurrentClientRepId(int $caseId, string $sessionId): ?int
    {
        $reps = \Civi\Api4\RelationshipCache::get(false)
            ->addSelect('near_contact_id')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('near_relation:name', '=', self::CLIENT_REP_REL_NAME)
            ->addWhere('near_contact_id.is_deleted', '=', false)
            ->addWhere('is_current', '=', true)
            ->addOrderBy('id', 'ASC')
            ->execute();

        $contactIds = array_values(array_unique(array_map(
            'intval',
            array_column((array) $reps, 'near_contact_id')
        )));

        if (!$contactIds) {
            return null;
        }
        if (count($contactIds) > 1) {
            \Civi::log()->warning('AfformSubmitSubscriber.php - Case has multiple active client reps; using the first', [
                'session_id' => $sessionId,
                'case_id' => $caseId,
                'contact_ids' => $contactIds,
            ]);
        }

        return $contactIds[0];
    }

    /**
     * The organisation client of a case — the party the client rep represents.
     *
     * @param int $caseId
     * @return int|null
     */
    protected function getCaseOrganizationId(int $caseId): ?int
    {
        $client = \Civi\Api4\CaseContact::get(false)
            ->addSelect('contact_id')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('contact_id.contact_type', '=', 'Organization')
            ->addWhere('contact_id.is_deleted', '=', false)
            ->addOrderBy('contact_id', 'ASC')
            ->setLimit(1)
            ->execute()
            ->first();

        return isset($client['contact_id']) ? (int) $client['contact_id'] : null;
    }

    /**
     * End the outgoing rep's client-rep role on one case.
     *
     * Scoped by case_id, so a person who is still the client rep on the
     * organisation's OTHER projects keeps those roles. Mirrors
     * endPresidentRelationship(): deactivated and end-dated rather than deleted,
     * so the case history still shows who held the role and until when.
     *
     * @param int $oldRepId
     * @param int $caseId
     * @param int $relationshipTypeId
     * @param string $sessionId
     */
    protected function endCaseClientRepRelationship(
        int $oldRepId,
        int $caseId,
        int $relationshipTypeId,
        string $sessionId
    ): void {
        $existing = \Civi\Api4\Relationship::get(false)
            ->addSelect('id')
            ->addWhere('contact_id_a', '=', $oldRepId)
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('relationship_type_id', '=', $relationshipTypeId)
            ->addWhere('is_active', '=', true)
            ->execute();

        if (!$existing->count()) {
            \Civi::log()->warning('AfformSubmitSubscriber.php - No active client rep relationship found to end', [
                'session_id' => $sessionId,
                'case_id' => $caseId,
                'old_contact_id' => $oldRepId,
            ]);
            return;
        }

        foreach ($existing as $relationship) {
            \Civi\Api4\Relationship::update(false)
                ->addValue('is_active', false)
                ->addValue('end_date', date('Y-m-d'))
                ->addWhere('id', '=', $relationship['id'])
                ->execute();

            \Civi::log()->info('AfformSubmitSubscriber.php - Ended previous client rep relationship', [
                'session_id' => $sessionId,
                'relationship_id' => $relationship['id'],
                'case_id' => $caseId,
                'old_contact_id' => $oldRepId,
                'end_date' => date('Y-m-d'),
            ]);
        }
    }

    /**
     * Create a relationship if it doesn't already exist
     *
     * @param int $contactIdA Individual contact ID
     * @param int $contactIdB Organization contact ID
     * @param int|null $relationshipTypeId Relationship type ID
     * @param string $relationshipLabel Label for logging
     * @param string $sessionId Session ID for logging
     * @param string $description Written to the relationship's description; defaults
     *   to the RCS wording so existing callers are unchanged.
     */
    protected function createRelationshipIfNotExists(
        int $contactIdA,
        int $contactIdB,
        ?int $relationshipTypeId,
        string $relationshipLabel,
        string $sessionId,
        string $description = 'Created by AfformSubmitSubscriber for RCS form'
    ): void {
        if (empty($relationshipTypeId)) {
            \Civi::log()->warning('AfformSubmitSubscriber.php - Relationship type not found', [
                'relationship_label' => $relationshipLabel,
                'session_id' => $sessionId
            ]);
            return;
        }

        // Check if relationship already exists.
        // Scoped to NON-case rows: this method creates the standing, organisation-
        // wide relationship, and a case-scoped row of the same type between the same
        // two contacts is a different fact. Without this clause an existing
        // case-scoped row suppresses creation of the standing one, leaving the
        // contact with no organisation-level link outside that single case.
        $existingRelationship = \Civi\Api4\Relationship::get(false)
            ->addWhere('contact_id_a', '=', $contactIdA)
            ->addWhere('contact_id_b', '=', $contactIdB)
            ->addWhere('relationship_type_id', '=', $relationshipTypeId)
            ->addWhere('case_id', 'IS NULL')
            ->addWhere('is_active', '=', true)
            ->setLimit(1)
            ->execute()
            ->first();

        if ($existingRelationship) {
            \Civi::log()->info('AfformSubmitSubscriber.php - Relationship already exists, skipping', [
                'relationship_id' => $existingRelationship['id'],
                'relationship_type' => $relationshipLabel,
                'contact_id_a' => $contactIdA,
                'contact_id_b' => $contactIdB,
                'session_id' => $sessionId
            ]);
            return;
        }

        // Log what we're about to create
        \Civi::log()->info('AfformSubmitSubscriber.php - Attempting to create relationship', [
            'relationship_type' => $relationshipLabel,
            'relationship_type_id' => $relationshipTypeId,
            'contact_id_a' => $contactIdA,
            'contact_id_b' => $contactIdB,
            'session_id' => $sessionId
        ]);

        // Create the relationship.
        // is_current_employer triggers CiviCRM's setCurrentEmployer for "Employee of" type only
        // (gated server-side by isRelationshipTypeCurrentEmployer); ignored for other types.
        \Civi\Api4\Relationship::create(false)
            ->addValue('contact_id_a', $contactIdA)
            ->addValue('contact_id_b', $contactIdB)
            ->addValue('relationship_type_id', $relationshipTypeId)
            ->addValue('is_active', true)
            ->addValue('is_current_employer', true)
            ->addValue('description', $description)
            ->execute();

        \Civi::log()->info('AfformSubmitSubscriber.php - Relationship created successfully', [
            'relationship_type' => $relationshipLabel,
            'contact_id_a' => $contactIdA,
            'contact_id_b' => $contactIdB,
            'session_id' => $sessionId
        ]);
    }

    /**
     * Create a case-specific relationship if it doesn't already exist
     *
     * @param int $contactIdA Individual contact ID
     * @param int $contactIdB Organization contact ID
     * @param int $caseId Case ID
     * @param int|null $relationshipTypeId Relationship type ID
     * @param string $relationshipLabel Label for logging
     * @param string $sessionId Session ID for logging
     * @param string $description Written to the relationship's description; defaults
     *   to the RCS wording so existing callers are unchanged.
     */
    protected function createCaseRelationshipIfNotExists(
        int $contactIdA,
        int $contactIdB,
        int $caseId,
        ?int $relationshipTypeId,
        string $relationshipLabel,
        string $sessionId,
        string $description = 'Created by AfformSubmitSubscriber for RCS form'
    ): void {
        if (empty($relationshipTypeId)) {
            \Civi::log()->warning('AfformSubmitSubscriber.php - Relationship type not found', [
                'relationship_label' => $relationshipLabel,
                'session_id' => $sessionId
            ]);
            return;
        }

        // Check if relationship already exists for this case
        $existingRelationship = \Civi\Api4\Relationship::get(false)
            ->addWhere('contact_id_a', '=', $contactIdA)
            ->addWhere('contact_id_b', '=', $contactIdB)
            ->addWhere('relationship_type_id', '=', $relationshipTypeId)
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('is_active', '=', true)
            ->setLimit(1)
            ->execute()
            ->first();

        if ($existingRelationship) {
            \Civi::log()->info('AfformSubmitSubscriber.php - Case relationship already exists, skipping', [
                'relationship_id' => $existingRelationship['id'],
                'relationship_type' => $relationshipLabel,
                'contact_id_a' => $contactIdA,
                'contact_id_b' => $contactIdB,
                'case_id' => $caseId,
                'session_id' => $sessionId
            ]);
            return;
        }

        // Log what we're about to create
        \Civi::log()->info('AfformSubmitSubscriber.php - Attempting to create case relationship', [
            'relationship_type' => $relationshipLabel,
            'relationship_type_id' => $relationshipTypeId,
            'contact_id_a' => $contactIdA,
            'contact_id_b' => $contactIdB,
            'case_id' => $caseId,
            'session_id' => $sessionId
        ]);

        // Create the relationship
        \Civi\Api4\Relationship::create(false)
            ->addValue('contact_id_a', $contactIdA)
            ->addValue('contact_id_b', $contactIdB)
            ->addValue('relationship_type_id', $relationshipTypeId)
            ->addValue('case_id', $caseId)
            ->addValue('is_active', true)
            ->addValue('description', $description)
            ->execute();

        \Civi::log()->info('AfformSubmitSubscriber.php - Case relationship created successfully', [
            'relationship_type' => $relationshipLabel,
            'contact_id_a' => $contactIdA,
            'contact_id_b' => $contactIdB,
            'case_id' => $caseId,
            'session_id' => $sessionId
        ]);
    }

    /**
     * Client PD authorization received: advance the project to Active.
     * Forward-only — fires only from the two definition-stage statuses, so a
     * stray re-submission never regresses a project that has moved on.
     *
     * The return value is that forward-only test: TRUE only on the submission
     * that actually moved the project. Callers use it to fire once-per-project
     * side effects — see sendVcSignoffNotice(), which must not mail the VC
     * again when a client reopens the 60-day tokenized link and re-submits.
     *
     * @param int $activityId The "Project Definition - Client Authorization" activity
     * @return bool TRUE when this submission moved the project to Active
     */
    protected function advanceCaseToActive(int $activityId): bool
    {
        try {
            $caseActivity = \Civi\Api4\CaseActivity::get(false)
                ->addWhere('activity_id', '=', $activityId)
                ->addSelect('case_id')
                ->setLimit(1)
                ->execute()
                ->first();
            $caseId = $caseActivity['case_id'] ?? null;
            if (!$caseId) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - PD authorization activity has no case', [
                    'activity_id' => $activityId,
                ]);
                return false;
            }

            $case = \Civi\Api4\CiviCase::get(false)
                ->addSelect('status_id:name', 'case_type_id:name')
                ->addWhere('id', '=', $caseId)
                ->execute()
                ->first();
            $fromStatuses = ['Awaiting VC Project Definition', 'Awaiting Client Project Definition'];
            if (
                empty($case)
                || $case['case_type_id:name'] !== 'project'
                || !in_array($case['status_id:name'], $fromStatuses, true)
            ) {
                // Logged because this return is now the once-per-project gate
                // on the VC notice: without it, a VC who never got told is
                // undiagnosable.
                \Civi::log()->debug('AfformSubmitSubscriber.php - PD authorization not eligible to advance', [
                    'case_id' => $caseId,
                    'case_type' => $case['case_type_id:name'] ?? null,
                    'status' => $case['status_id:name'] ?? null,
                ]);
                return false;
            }

            // The status is re-tested inside the UPDATE, not just in the read
            // above: this return value gates the VC notice, so two concurrent
            // submissions must not both see a definition-stage status and both
            // claim the transition (and both mail the VC).
            $updated = \Civi\Api4\CiviCase::update(false)
                ->addValue('status_id:name', 'Active')
                ->addWhere('id', '=', $caseId)
                ->addWhere('status_id:name', 'IN', $fromStatuses)
                ->execute();

            if (count($updated) === 0) {
                return false;
            }

            \Civi::log()->info('AfformSubmitSubscriber.php - PD authorized, project advanced to Active', [
                'case_id' => $caseId,
                'activity_id' => $activityId,
                'previous_status' => $case['status_id:name'],
            ]);

            return true;
        } catch (\Throwable $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - advanceCaseToActive failed: ' . $e->getMessage(), [
                'activity_id' => $activityId,
            ]);

            return false;
        }
    }

    /**
     * Set the project-owning organization (the case's Organization client) as a
     * target ("With") on a project-close activity. Used for the VC close form, whose
     * submitter (the VC) is not related to the client org, so the org link can't be
     * derived on the form the way the Client close form does.
     *
     * @param int $activityId
     * @param string $sessionId
     */
    protected function linkProjectOwnerAsTarget(int $activityId, string $sessionId): void
    {
        try {
            // Find the case this close activity belongs to
            $caseActivity = \Civi\Api4\CaseActivity::get(false)
                ->addWhere('activity_id', '=', $activityId)
                ->addSelect('case_id')
                ->setLimit(1)
                ->execute()
                ->first();

            if (empty($caseActivity['case_id'])) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - VC close activity has no linked case; cannot set project owner target', [
                    'session_id' => $sessionId,
                    'activity_id' => $activityId,
                ]);
                return;
            }
            $caseId = $caseActivity['case_id'];

            // The project owner is the case's Organization client
            $orgClients = \Civi\Api4\CaseContact::get(false)
                ->addWhere('case_id', '=', $caseId)
                ->addWhere('contact_id.contact_type', '=', 'Organization')
                ->addSelect('contact_id')
                ->execute();

            foreach ($orgClients as $client) {
                $orgId = $client['contact_id'];

                // Skip if already a target (idempotent)
                $exists = \Civi\Api4\ActivityContact::get(false)
                    ->addWhere('activity_id', '=', $activityId)
                    ->addWhere('contact_id', '=', $orgId)
                    ->addWhere('record_type_id:name', '=', 'Activity Targets')
                    ->selectRowCount()
                    ->execute()
                    ->count();
                if ($exists) {
                    continue;
                }

                \Civi\Api4\ActivityContact::create(false)
                    ->addValue('activity_id', $activityId)
                    ->addValue('contact_id', $orgId)
                    ->addValue('record_type_id:name', 'Activity Targets')
                    ->execute();

                \Civi::log()->info('AfformSubmitSubscriber.php - Set project-owning org as target on VC close activity', [
                    'session_id' => $sessionId,
                    'activity_id' => $activityId,
                    'case_id' => $caseId,
                    'organization_id' => $orgId,
                ]);
            }
        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Failed to set project owner target on VC close activity', [
                'session_id' => $sessionId,
                'activity_id' => $activityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create the "Request for Consulting Services (RCS)" activity on submission.
     *
     * Source = Primary Contact (Individual3, the person completing the form),
     * target ("With") = the Organization (the client org), linked to the Service
     * Request case. The activity
     * type is referenced by name so it stays stable across dev/prod (it is brought
     * under mascode management in OptionValue_ActivityType_RCS.mgd.php).
     *
     * @param string $sessionId
     */
    protected function createRCSActivity(string $sessionId): ?int
    {
        try {
            $submissionData = self::$submissionData[$sessionId] ?? [];
            $primaryContactId = $submissionData['primary_contact_id'] ?? null;
            $organizationId = $submissionData['organization_id'] ?? null;
            $caseId = $submissionData['case_id'] ?? null;

            if (empty($primaryContactId) || empty($caseId)) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - Cannot create RCS activity: missing primary contact or case', [
                    'session_id' => $sessionId,
                    'primary_contact_id' => $primaryContactId,
                    'case_id' => $caseId,
                ]);
                return null;
            }

            $create = \Civi\Api4\Activity::create(false)
                ->addValue('activity_type_id:name', 'Request for Consulting Services (RCS)')
                ->addValue('source_contact_id', $primaryContactId)
                ->addValue('status_id', 2) // Completed
                ->addValue('subject', 'Request for Consulting Services')
                ->addValue('case_id', $caseId);

            if (!empty($organizationId)) {
                $create->addValue('target_contact_id', [$organizationId]);
            }

            $activity = $create->execute()->first();

            \Civi::log()->info('AfformSubmitSubscriber.php - RCS activity created', [
                'session_id' => $sessionId,
                'activity_id' => $activity['id'] ?? null,
                'source_contact_id' => $primaryContactId,
                'organization_id' => $organizationId,
                'case_id' => $caseId,
            ]);

            return $activity['id'] ?? null;
        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Failed to create RCS activity', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build the entered-data summary for a submission, store it on the activity's
     * `details` field, and cache the HTML on the session data for the email reuse.
     *
     * @param string $sessionId
     * @param int    $activityId
     */
    protected function writeSubmissionSummary(string $sessionId, int $activityId): void
    {
        try {
            $formRoute = self::$submissionData[$sessionId]['form_route'] ?? '';
            $svc = new \Civi\Mascode\Submission\SubmissionSummaryService();
            $html = $svc->buildForForm($formRoute, self::$submissionData[$sessionId]);
            if ($html === '') {
                return;
            }

            self::$submissionData[$sessionId]['summary_html'] = $html;

            \Civi\Api4\Activity::update(false)
                ->addWhere('id', '=', $activityId)
                ->addValue('details', $html)
                ->execute();
        } catch (\Throwable $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Failed to write submission summary', [
                'session_id' => $sessionId,
                'activity_id' => $activityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update case status to "RCS Completed"
     *
     * @param string $sessionId
     */
    protected function updateCaseStatus(string $sessionId): void
    {
        try {
            $submissionData = self::$submissionData[$sessionId] ?? [];

            if (empty($submissionData['case_id'])) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No case ID found for status update', [
                    'session_id' => $sessionId,
                    'submission_data' => $submissionData
                ]);
                return;
            }

            // Get the "RCS Completed" status value
            $caseStatus = \Civi\Api4\OptionValue::get(false)
                ->addWhere('option_group_id:name', '=', 'case_status')
                ->addWhere('label', '=', 'RCS Completed')
                ->addSelect('value')
                ->execute()
                ->first();

            if (!$caseStatus) {
                \Civi::log()->error('AfformSubmitSubscriber.php - "RCS Completed" case status not found', [
                    'session_id' => $sessionId,
                    'case_id' => $submissionData['case_id']
                ]);
                return;
            }

            // Update the case status
            \Civi\Api4\CiviCase::update(false)
                ->addWhere('id', '=', $submissionData['case_id'])
                ->addValue('status_id', $caseStatus['value'])
                ->execute();

            \Civi::log()->info('AfformSubmitSubscriber.php - Case status updated to "RCS Completed"', [
                'case_id' => $submissionData['case_id'],
                'status_value' => $caseStatus['value'],
                'session_id' => $sessionId
            ]);

        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Exception while updating case status', [
                'session_id' => $sessionId,
                'case_id' => $submissionData['case_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Send confirmation email
     *
     * @param string $sessionId
     */
    protected function sendConfirmationEmail(string $sessionId): void
    {
        try {
            $submissionData = self::$submissionData[$sessionId] ?? [];
            $primaryContactId = $submissionData['primary_contact_id'] ?? null;
            $formName = $submissionData['form_name'] ?? 'Unknown Form';
            $formRoute = $submissionData['form_route'] ?? '';

            if (empty($primaryContactId)) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No primary contact ID found for form', [
                    'session_id' => $sessionId,
                    'form_name' => $formName,
                    'submission_data' => $submissionData
                ]);
                return;
            }

            // Get primary contact email details
            $contactDetails = Contact::get(false)
                ->addSelect('display_name', 'email_primary.email')
                ->addWhere('id', '=', $primaryContactId)
                ->execute()
                ->first();

            if (empty($contactDetails['email_primary.email'])) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No email found for contact', [
                    'contact_id' => $primaryContactId,
                    'form_name' => $formName
                ]);
                return;
            }

            // Map form routes to their message template names
            $templateNames = [
                'civicrm/mas-rcs-form' => 'MAS Form Submission Confirmation',
                'civicrm/mas-sasf-form' => 'MAS Form Submission Confirmation',
                'civicrm/mas-sass-form' => 'MAS Form Submission Confirmation',
                'civicrm/mas-pclose-client' => 'MAS Form Submission Confirmation',
                'civicrm/mas-pclose-vc' => 'MAS Form Submission Confirmation',
                'civicrm/mas-pdef-vc' => 'MAS Form Submission Confirmation',
                'civicrm/mas-pdef-client' => 'MAS Form Submission Confirmation'
            ];

            $templateName = $templateNames[$formRoute] ?? null;
            if (!$templateName) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No template name mapped for form route', [
                    'form_route' => $formRoute,
                    'form_name' => $formName
                ]);
                return;
            }

            // Get the message template by name (environment-agnostic)
            $template = MessageTemplate::get(false)
                ->addSelect('msg_subject', 'msg_text', 'msg_html')
                ->addWhere('msg_title', '=', $templateName)
                ->addWhere('is_active', '=', true)
                ->execute()
                ->first();

            if (!$template) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - Message template not found', [
                    'template_name' => $templateName,
                    'form_name' => $formName
                ]);
                return;
            }

            // Use the summary built at submission time (writeSubmissionSummary).
            // Fall back to building it now from the actual submitted entities —
            // never a "most recent AfformSubmission" lookup, which is race-prone.
            $summaryHtml = $submissionData['summary_html'] ?? '';
            if ($summaryHtml === '') {
                $svc = new \Civi\Mascode\Submission\SubmissionSummaryService();
                $summaryHtml = $svc->buildForForm($formRoute, $submissionData);
            }

            // The template is the email shell (greeting + footer); the summary is the
            // entered-data block appended below it.
            $subject = $template['msg_subject'];
            $divider = '<hr style="border:none;border-top:1px solid #dddddd;margin:24px 0;">';
            $htmlContent = $template['msg_html'] . ($summaryHtml !== '' ? $divider . $summaryHtml : '');
            $textContent = ($template['msg_text'] ?? '')
                . ($summaryHtml !== '' ? "\n\n" . (new \Civi\Mascode\Submission\SubmissionSummaryService())->toPlainText($summaryHtml) : '');

            // Use TokenProcessor for modern token replacement
            $tokenProcessor = new TokenProcessor(\Civi::dispatcher(), [
                'controller' => __CLASS__,
                'smarty' => false,
                'schema' => ['contactId'],
            ]);

            $tokenProcessor->addMessage('subject', $subject, 'text/plain');
            $tokenProcessor->addMessage('text', $textContent, 'text/plain');
            $tokenProcessor->addMessage('html', $htmlContent, 'text/html');
            $tokenProcessor->addRow(['contactId' => $primaryContactId]);
            $tokenProcessor->evaluate();

            $row = $tokenProcessor->getRow(0);
            $templateContent = [
                'subject' => $row->render('subject'),
                'text' => $row->render('text'),
                'html' => $row->render('html'),
            ];

            // Send to primary contact
            $mailParams = [
                'from' => 'MAS <info@masadvise.org>',
                'toName' => $contactDetails['display_name'],
                'toEmail' => $contactDetails['email_primary.email'],
                'subject' => $templateContent['subject'],
                'text' => $templateContent['text'],
                'html' => $templateContent['html'],
            ];

            \CRM_Utils_Mail::send($mailParams);

            // Send to info@masadvise.org (using same processed content)
            $adminMailParams = [
                'from' => 'MAS <info@masadvise.org>',
                'toName' => 'MAS Admin',
                'toEmail' => 'info@masadvise.org',
                'subject' => $templateContent['subject'],
                'text' => $templateContent['text'],
                'html' => $templateContent['html'],
            ];

            \CRM_Utils_Mail::send($adminMailParams);

            \Civi::log()->info('AfformSubmitSubscriber.php - Confirmation emails sent successfully', [
                'form_name' => $formName,
                'primary_contact_id' => $primaryContactId
            ]);

        } catch (\Exception $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Failed to send confirmation emails', [
                'form_name' => $formName ?? 'Unknown',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Tell the assigned VC that the client has authorized the Project
     * Definition, appending a complete printable record of the project — the
     * header, the VC's definition, and the client's authorization.
     *
     * @param array $submissionData Submission tracking data (needs case_id)
     * @return int Number of VCs successfully notified
     */
    protected function sendVcSignoffNotice(array $submissionData): int
    {
        return $this->mailVcProjectRecord(
            (int) ($submissionData['case_id'] ?? 0),
            'mas_pd_signoff_notify__vc',
            'mas:record-pd-vc',
            'PD signoff notice'
        );
    }

    /**
     * Forward the client's project-close feedback to the VC who did the work,
     * but ONLY when the client agreed to share it.
     *
     * The close form has always asked "Could we share your comments with the
     * Volunteer Consultant who worked with you?" and nothing ever acted on the
     * answer. The client's consent is the gate: anything other than an explicit
     * "Yes" — including no answer at all — means the VC is not told.
     *
     * @param array $submissionData Submission tracking data (needs case_id)
     * @return int Number of VCs successfully notified
     */
    protected function sendVcClientFeedback(array $submissionData): int
    {
        $caseId = (int) ($submissionData['case_id'] ?? 0);
        $currentActivityId = (int) ($submissionData['activity_id'] ?? 0);
        if (!$caseId || !$currentActivityId) {
            \Civi::log()->warning('AfformSubmitSubscriber.php - No case or activity ID, client feedback not shared with VC', [
                'form_route' => $submissionData['form_route'] ?? '',
                'case_id' => $caseId,
                'activity_id' => $currentActivityId
            ]);
            return 0;
        }

        try {
            // Idempotency, NOT a state transition — unlike the PD path, which
            // rides on advanceCaseToActive(). Nothing moves a project off
            // "Awaiting Client Project Close Form" when the client submits, so
            // mas_lifecycle_close_chase keeps firing at 30/90/150 days with a
            // fresh tokenized link. A client who complies with a chase would
            // otherwise send their VC a second and third copy of their
            // feedback.
            //
            // Counts feedback activities OLDER than this submission's own,
            // rather than counting all of them and expecting one's own to be
            // among them. That expectation would fail OPEN — if the activity
            // type name drifted or the CaseActivity link were missing, the
            // count would be 0 and every submission would notify again. It
            // also stops a staff-entered paper-form activity from suppressing
            // the first genuine online submission.
            $priorFeedback = \Civi\Api4\Activity::get(false)
                ->addJoin('CaseActivity AS ca', 'INNER', ['ca.activity_id', '=', 'id'])
                ->addWhere('ca.case_id', '=', $caseId)
                ->addWhere('activity_type_id:name', '=', 'Project Close - Client Feedback')
                ->addWhere('is_current_revision', '=', true)
                ->addWhere('id', '<', $currentActivityId)
                ->selectRowCount()
                ->execute()
                ->count();

            if ($priorFeedback >= 1) {
                \Civi::log()->info('AfformSubmitSubscriber.php - Client feedback already shared with VC, repeat submission ignored', [
                    'case_id' => $caseId,
                    'prior_feedback_activities' => $priorFeedback
                ]);
                return 0;
            }

            // Read the option NAME, not the stored value. yes_no is a shared,
            // unmanaged option group whose casing is seed-dependent — the
            // sibling groups on this very form use lowercase "yes" — and a
            // value/name divergence here would silently read a consenting
            // client as declining.
            $case = \Civi\Api4\CiviCase::get(false)
                ->addSelect('Project_Close_Client.share_with_vc:name')
                ->addWhere('id', '=', $caseId)
                ->execute()
                ->first();
        } catch (\Throwable $e) {
            \Civi::log()->error('AfformSubmitSubscriber.php - Could not check prior feedback or read share_with_vc consent', [
                'case_id' => $caseId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }

        // Fails closed: anything that is not an explicit Yes — including no
        // answer at all, or a missing custom group — shares nothing.
        $consent = $case['Project_Close_Client.share_with_vc:name'] ?? null;
        if ($consent !== 'Yes') {
            \Civi::log()->info('AfformSubmitSubscriber.php - Client did not consent to share feedback with VC', [
                'case_id' => $caseId,
                'share_with_vc' => $consent
            ]);
            return 0;
        }

        return $this->mailVcProjectRecord(
            $caseId,
            'mas_close_feedback_share__vc',
            'mas:record-close-client-for-vc',
            'client close feedback'
        );
    }

    /**
     * Mail every current VC on a case a template plus a printable project
     * record. Shared by the PD-signoff notice and the close-feedback share.
     *
     * The VC on a MAS case is the "Case Coordinator is" relationship,
     * contact_id_a — the same lookup VcTokenSubscriber uses for {vc.*}.
     *
     * Co-VC projects are real: MAS assigns two or three coordinators often
     * enough that picking one and dropping the rest would be a silent failure.
     * Every distinct current coordinator gets their own copy, rendered against
     * their own contact so the greeting is theirs.
     *
     * Failures are logged and swallowed. These sends run inside an Afform
     * submit handler, after the submission has already been recorded — a
     * missing VC or a mail error must not turn a successful submission into an
     * error for the person who filled the form in.
     *
     * @param int    $caseId        Project case the record is about
     * @param string $templateTitle msg_title of the managed shell template
     * @param string $recordKey     SummaryConfig key for the appended record
     * @param string $label         Human label for log lines
     * @return int Number of VCs successfully mailed
     */
    private function mailVcProjectRecord(
        int $caseId,
        string $templateTitle,
        string $recordKey,
        string $label
    ): int {
        try {
            if (!$caseId) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No case ID, ' . $label . ' skipped');
                return 0;
            }

            $relationships = \Civi\Api4\Relationship::get(false)
                ->addSelect(
                    'contact_id_a',
                    'contact_id_a.display_name',
                    'contact_id_a.email_primary.email'
                )
                ->addWhere('case_id', '=', $caseId)
                ->addWhere('relationship_type_id:name', '=', 'Case Coordinator is')
                ->addWhere('is_active', '=', true)
                // is_active alone does NOT mean "current coordinator" — CiviCRM
                // only clears it via the disable_expired_relationships job, so a
                // reassigned VC's row stays active with a past end_date. Without
                // this an ex-VC would be mailed about a project they left.
                ->addClause('OR', ['end_date', 'IS EMPTY'], ['end_date', '>=', 'now'])
                ->addOrderBy('id', 'DESC')
                ->execute();

            // One email per person, not per relationship row: the same VC can
            // hold more than one coordinator row on a case.
            $recipients = [];
            foreach ($relationships as $relationship) {
                $vcContactId = (int) ($relationship['contact_id_a'] ?? 0);
                $vcEmail = $relationship['contact_id_a.email_primary.email'] ?? '';
                if ($vcContactId && $vcEmail === '') {
                    // Logged rather than skipped silently: a dropped co-VC with
                    // no warning is exactly the failure mode to avoid here.
                    \Civi::log()->warning('AfformSubmitSubscriber.php - Coordinator has no email, ' . $label . ' skipped for them', [
                        'case_id' => $caseId,
                        'vc_contact_id' => $vcContactId
                    ]);
                }
                if (!$vcContactId || $vcEmail === '' || isset($recipients[$vcContactId])) {
                    continue;
                }
                $recipients[$vcContactId] = [
                    'name' => $relationship['contact_id_a.display_name'] ?? 'MAS Volunteer Consultant',
                    'email' => $vcEmail,
                ];
            }

            if (!$recipients) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - No VC with an email on case, ' . $label . ' skipped', [
                    'case_id' => $caseId
                ]);
                return 0;
            }

            $template = MessageTemplate::get(false)
                ->addSelect('msg_subject', 'msg_text', 'msg_html')
                ->addWhere('msg_title', '=', $templateTitle)
                ->addWhere('is_active', '=', true)
                ->execute()
                ->first();

            if (!$template) {
                \Civi::log()->warning('AfformSubmitSubscriber.php - Template not found, ' . $label . ' skipped', [
                    'template_name' => $templateTitle,
                    'case_id' => $caseId
                ]);
                return 0;
            }

            // A complete printable record — project header plus every group
            // that makes up the paperwork — so the VC can print the email
            // rather than the form.
            $summarySvc = new \Civi\Mascode\Submission\SubmissionSummaryService();
            $recordHtml = $summarySvc->buildForForm($recordKey, ['case_id' => $caseId]);

            $divider = '<hr style="border:none;border-top:1px solid #dddddd;margin:24px 0;">';
            $htmlContent = $template['msg_html'] . ($recordHtml !== '' ? $divider . $recordHtml : '');
            // Structured text alternative — a bare strip_tags() of the record
            // table collapses into an unreadable run.
            $textContent = ($template['msg_text'] ?? '')
                . ($recordHtml !== '' ? "\n\n" . $summarySvc->toPlainText($recordHtml) : '');

            $sentCount = 0;
            foreach ($recipients as $vcContactId => $recipient) {
                // Rendered per recipient: {contact.*} resolves against the VC
                // being written to. caseId in the schema as well, so {case.*}
                // resolves against the project.
                $tokenProcessor = new TokenProcessor(\Civi::dispatcher(), [
                    'controller' => __CLASS__,
                    'smarty' => false,
                    'schema' => ['contactId', 'caseId'],
                ]);
                $tokenProcessor->addMessage('subject', $template['msg_subject'], 'text/plain');
                $tokenProcessor->addMessage('text', $textContent, 'text/plain');
                $tokenProcessor->addMessage('html', $htmlContent, 'text/html');
                $tokenProcessor->addRow(['contactId' => $vcContactId, 'caseId' => $caseId]);
                $tokenProcessor->evaluate();

                $row = $tokenProcessor->getRow(0);

                // Assigned to a variable first: CRM_Utils_Mail::send() takes
                // its params by reference and rejects a literal.
                $vcMailParams = [
                    'from' => 'MAS <info@masadvise.org>',
                    'toName' => $recipient['name'],
                    'toEmail' => $recipient['email'],
                    'subject' => $row->render('subject'),
                    'text' => $row->render('text'),
                    'html' => $row->render('html'),
                ];

                // send() returns FALSE on a mailer exception or an
                // abortMailSend hook — don't report a send that didn't happen.
                if (\CRM_Utils_Mail::send($vcMailParams)) {
                    $sentCount++;
                    \Civi::log()->info('AfformSubmitSubscriber.php - ' . $label . ' sent', [
                        'case_id' => $caseId,
                        'vc_contact_id' => $vcContactId
                    ]);
                } else {
                    \Civi::log()->warning('AfformSubmitSubscriber.php - ' . $label . ' failed to send', [
                        'case_id' => $caseId,
                        'vc_contact_id' => $vcContactId
                    ]);
                }
            }

            return $sentCount;
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: these run before or alongside the
            // submitter's own confirmation, so an \Error escaping here would
            // fail their submission on work already recorded.
            \Civi::log()->error('AfformSubmitSubscriber.php - Failed to send ' . $label, [
                'case_id' => $caseId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}
