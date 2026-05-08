<?php

// File: Civi/Mascode/Event/AfformSubmitSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Afform\Event\AfformSubmitEvent;
use Civi\Api4\Contact;
use Civi\Api4\MessageTemplate;
use Civi\Api4\AfformSubmission;
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
            'civicrm/mas-pclose-vc' => 'afformProjectCloseVCFeedback'
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
                'civicrm/mas-pclose-vc' => 'MAS Form Submission Confirmation'
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

            // Get the most recent submission for this form
            $submission = AfformSubmission::get(false)
                ->addSelect('id', 'afform_name', 'contact_id', 'data')
                ->addWhere('afform_name', '=', $formName)
                ->addOrderBy('id', 'DESC')
                ->setLimit(1)
                ->execute()
                ->first();

            $formattedSubmissionData = '';
            if ($submission) {
                $formattedSubmissionData = $this->formatSubmissionData($submission['data'] ?? [], $formRoute);
                \Civi::log()->info('AfformSubmitSubscriber.php - Using submission data', [
                    'submission_id' => $submission['id'],
                    'contact_id' => $submission['contact_id'],
                    'form_name' => $formName
                ]);
            }

            // Prepare template content with submission data
            $subject = $template['msg_subject'];
            $textContent = $template['msg_text'] . "\n\n" . $formattedSubmissionData;
            $htmlContent = $template['msg_html'] . "<br><br>" . nl2br($formattedSubmissionData);

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
     * Format submission data for inclusion in emails
     */
    private function formatSubmissionData(array $data, string $formRoute = ''): string
    {
        if (empty($data)) {
            return 'No submission data available.';
        }

        $formatted = '';

        foreach ($data as $entityName => $entityData) {
            if (!is_array($entityData)) {
                continue;
            }

            // Add entity section header
            $entityLabel = $this->getEntityLabel($entityName, $formRoute);
            $formatted .= "\n=== {$entityLabel} ===\n";

            foreach ($entityData as $record) {
                if (!is_array($record) || !isset($record['fields'])) {
                    continue;
                }

                foreach ($record['fields'] as $fieldName => $fieldValue) {
                    if ($fieldValue !== null && $fieldValue !== '') {
                        $fieldLabel = $this->getFieldLabel($fieldName, $formRoute);

                        // Format survey answers if they are numeric ratings
                        if ($formRoute !== 'civicrm/mas-rcs-form' && is_numeric($fieldValue) && $fieldValue >= 1 && $fieldValue <= 5) {
                            $scaleLabels = [
                                1 => 'Strongly Disagree',
                                2 => 'Disagree',
                                3 => 'Neutral',
                                4 => 'Agree',
                                5 => 'Strongly Agree'
                            ];
                            $fieldValue = $fieldValue . ' (' . ($scaleLabels[$fieldValue] ?? 'Unknown') . ')';
                        }

                        $formatted .= "{$fieldLabel}: {$fieldValue}\n";
                    }
                }
                $formatted .= "\n"; // Separator between records
            }
        }

        return $formatted;
    }

    /**
     * Get user-friendly entity label
     */
    private function getEntityLabel(string $entityName, string $formRoute = ''): string
    {
        if ($formRoute === 'civicrm/mas-rcs-form') {
            // RCS Form labels
            $labels = [
                'Organization1' => 'Organization Information',
                'Individual1' => 'President/Board Chair',
                'Individual2' => 'Executive Director',
                'Individual3' => 'Primary Contact',
                'Case1' => 'Request Details',
            ];
        } else {
            // Survey Form labels (SASS/SASF)
            $labels = [
                'Organization1' => 'Organization Information',
                'Individual1' => 'Contact Information',
                'Activity1' => 'Survey Responses',
            ];
        }

        return $labels[$entityName] ?? $entityName;
    }

    /**
     * Get user-friendly field label
     */
    private function getFieldLabel(string $fieldName, string $formRoute = ''): string
    {
        // Common field labels for all forms
        $commonLabels = [
            'organization_name' => 'Organization Name',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'job_title' => 'Job Title',
            'street_address' => 'Address',
            'city' => 'City',
            'state_province_id' => 'Province',
            'postal_code' => 'Postal Code',
            'subject' => 'Subject',
            'url' => 'Website',
            'do_not_email' => 'Email Preference',
        ];

        // Survey question labels (for SASS/SASF forms)
        $surveyLabels = [
            'q01_mission_clear' => '1. Our mission is clear and understood by all staff and board members',
            'q02_vision_inspiring' => '2. We have an inspiring vision that guides our work',
            'q03_values_guide' => '3. Our organizational values clearly guide our decisions and actions',
            'q04_mission_relevant' => '4. Our mission remains relevant to current community needs',
            'q05_strategic_alignment' => '5. All our activities are clearly aligned with our mission',
            'q06_board_effective' => '6. Our board is effective at providing governance and oversight',
            'q07_roles_clear' => '7. Board and staff roles and responsibilities are clearly defined',
            'q08_policies_current' => '8. We have current and comprehensive governance policies',
            'q09_board_diverse' => '9. Our board reflects the diversity of our community',
            'q10_board_recruitment' => '10. We have effective board recruitment and orientation processes',
            'q11_financial_stable' => '11. Our organization is financially stable',
            'q12_budget_process' => '12. We have a sound budgeting and financial planning process',
            'q13_revenue_diverse' => '13. We have diversified revenue sources',
            'q14_financial_controls' => '14. We have strong financial controls and accountability measures',
            'q15_reserves_adequate' => '15. We maintain adequate financial reserves',
            'q16_programs_effective' => '16. Our programs are effective at achieving intended outcomes',
            'q17_data_collection' => '17. We regularly collect and analyze data on program performance',
            'q18_continuous_improvement' => '18. We use evaluation results for continuous program improvement',
            'q19_program_innovation' => '19. We regularly innovate and adapt our programs',
            'q20_impact_measurement' => '20. We effectively measure and communicate our impact',
            'q21_staff_skilled' => '21. Our staff have the skills and resources needed to do their jobs well',
            'q22_professional_development' => '22. We provide adequate professional development opportunities',
            'q23_succession_planning' => '23. We have effective succession planning and knowledge management',
            'q24_compensation_competitive' => '24. Our compensation and benefits are competitive',
            'q25_performance_management' => '25. We have effective performance management systems',
            'q26_communication_open' => '26. We have open and effective internal communication',
            'q27_culture_positive' => '27. Our organizational culture is positive and supportive',
            'q28_change_adaptable' => '28. We are adaptable and responsive to change',
            'q29_collaboration_strong' => '29. We have strong collaboration across departments/programs',
            'q30_learning_culture' => '30. We have a culture of learning and continuous improvement',
            'q31_stakeholder_engaged' => '31. We effectively engage with our key stakeholders',
            'q32_partnerships_strong' => '32. We have strong partnerships that advance our mission',
            'q33_reputation_positive' => '33. We have a positive reputation in our community',
            'q34_marketing_effective' => '34. Our marketing and communications are effective',
            'q35_advocacy_engaged' => '35. We effectively engage in advocacy and policy work when appropriate'
        ];

        // Check survey labels first for survey forms
        if ($formRoute !== 'civicrm/mas-rcs-form' && isset($surveyLabels[$fieldName])) {
            return $surveyLabels[$fieldName];
        }

        // Check common labels
        if (isset($commonLabels[$fieldName])) {
            return $commonLabels[$fieldName];
        }

        // Default formatting
        return ucwords(str_replace('_', ' ', $fieldName));
    }

}
