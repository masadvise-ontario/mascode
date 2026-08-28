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
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        // Subscriptions with a priority of > 0 happen before the data is saved to the database.
        // Subscriptions with a priority of < 0 happen after the data is saved to the database.
        // Data is saved to the database in
        //     civicrm/ext/afform/core/afform.php:  $dispatcher->addListener('civi.afform.submit',
        //          ['\Civi\Api4\Action\Afform\Submit', 'processGenericEntity'], 0);
        return [
            'civi.afform.submit' => [
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
     * Get unique session identifier for this submission
     */
    private function getSessionId(): string
    {
        $sessionId = session_id();
        if (!$sessionId) {
            // Fallback if no session (e.g., in testing)
            $sessionId = 'no-session-' . getmypid() . '-' . time();
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
     * Create a relationship if it doesn't already exist
     *
     * @param int $contactIdA Individual contact ID
     * @param int $contactIdB Organization contact ID
     * @param int|null $relationshipTypeId Relationship type ID
     * @param string $relationshipLabel Label for logging
     * @param string $sessionId Session ID for logging
     */
    protected function createRelationshipIfNotExists(
        int $contactIdA,
        int $contactIdB,
        ?int $relationshipTypeId,
        string $relationshipLabel,
        string $sessionId
    ): void {
        if (empty($relationshipTypeId)) {
            \Civi::log()->warning('AfformSubmitSubscriber.php - Relationship type not found', [
                'relationship_label' => $relationshipLabel,
                'session_id' => $sessionId
            ]);
            return;
        }

        // Check if relationship already exists
        $existingRelationship = \Civi\Api4\Relationship::get(false)
            ->addWhere('contact_id_a', '=', $contactIdA)
            ->addWhere('contact_id_b', '=', $contactIdB)
            ->addWhere('relationship_type_id', '=', $relationshipTypeId)
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
            ->addValue('description', 'Created by AfformSubmitSubscriber for RCS form')
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
     */
    protected function createCaseRelationshipIfNotExists(
        int $contactIdA,
        int $contactIdB,
        int $caseId,
        ?int $relationshipTypeId,
        string $relationshipLabel,
        string $sessionId
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
            ->addValue('description', 'Created by AfformSubmitSubscriber for RCS form')
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
