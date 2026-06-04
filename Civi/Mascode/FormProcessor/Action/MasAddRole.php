<?php

// file: Civi/Mascode/FormProcessor/Action/MasAddRole.php

namespace Civi\Mascode\FormProcessor\Action;

use Civi\ActionProvider\Action\AbstractAction;
use Civi\ActionProvider\Parameter\ParameterBagInterface;
use Civi\ActionProvider\Parameter\SpecificationBag;
use Civi\ActionProvider\Parameter\Specification;
use CRM_Mascode_ExtensionUtil as E;

class MasAddRole extends AbstractAction
{
    /**
     * Get relationship type options for the configuration form
     */
    private function relationshipTypes()
    {
        $options = [];
        try {
            // Use API4 for better error handling and cleaner code
            $relationshipTypes = \Civi\Api4\RelationshipType::get(false)
            ->addSelect('id', 'label_a_b', 'label_b_a')
            ->addWhere('is_active', '=', true)
            ->execute();

            foreach ($relationshipTypes as $type) {
                $options["{$type['id']}_a_b"] = "{$type['label_a_b']} (a->b)";
                $options["{$type['id']}_b_a"] = "{$type['label_b_a']} (b->a)";
            }

            // Sort options alphabetically using proper comparison function
            uasort($options, function ($a, $b) {
                return strcmp($a, $b);
            });
        } catch (\Exception $e) {
            \Civi::log()->error('MasAddRole.php - Failed to get relationship types: ' . $e->getMessage());
        }

        return $options;
    }

    /**
     * Returns the specification of the configuration options for the action.
     */
    public function getConfigurationSpecification()
    {
        return new SpecificationBag([
        new Specification(
            'relationship_type',
            'String',
            E::ts('Relationship Type'),
            true,
            null,
            null,
            $this->relationshipTypes(),
            false
        ),
        new Specification(
            'activity_source_contact_id',
            'Integer',
            E::ts('Default Source Contact ID'),
            false,
            null,
            'Contact',
            null,
            false
        ),
        ]);
    }

    /**
     * Returns the specification of the parameters for the action.
     */
    public function getParameterSpecification()
    {
        return new SpecificationBag([
        new Specification('contact_id', 'Integer', E::ts('Contact ID'), true, null, null, null, false),
        new Specification('case_id', 'Integer', E::ts('Case ID'), true, null, null, null, false),
        new Specification(
            'source_contact_id',
            'Integer',
            E::ts('Source Contact ID (optional)'),
            false,
            null,
            'Contact',
            null,
            false
        ),
        ]);
    }

    /**
     * Returns the specification of the output parameters of this action.
     */
    public function getOutputSpecification()
    {
        return new SpecificationBag([
        new Specification('relationship_id', 'Integer', E::ts('Relationship ID'), false),
        ]);
    }

    /**
     * Run the action
     */
    protected function doAction(ParameterBagInterface $parameters, ParameterBagInterface $output)
    {
        // Get basic parameters
        $contactId = (int) $parameters->getParameter('contact_id');
        $caseId = (int) $parameters->getParameter('case_id');

        // Get source contact ID from parameters or configuration
        $sourceContactId = 0;
        if ($parameters->doesParameterExists('source_contact_id') &&
              !empty($parameters->getParameter('source_contact_id'))) {
            $sourceContactId = (int) $parameters->getParameter('source_contact_id');
        } elseif (
            $this->configuration->doesParameterExists('activity_source_contact_id') &&
            !empty($this->configuration->getParameter('activity_source_contact_id'))
        ) {
            $sourceContactId = (int) $this->configuration->getParameter('activity_source_contact_id');
        }

        // If no source contact was provided, use a default
        if (!$sourceContactId) {
            try {
                // Try to get domain contact
                $domainContactId = \Civi\Api4\Domain::get(false)
                ->addSelect('contact_id')
                ->setLimit(1)
                ->execute()
                ->first()['contact_id'] ?? null;

                if ($domainContactId) {
                    $sourceContactId = $domainContactId;
                } else {
                    // Fallback to user ID 1
                    $sourceContactId = 1;
                }
            } catch (\Exception $e) {
                $sourceContactId = 1; // Final fallback
            }
        }

        try {
            // Parse relationship type
            $relTypeConfig = $this->configuration->getParameter('relationship_type');
            list($relationshipTypeId, $direction) = $this->parseRelationshipType($relTypeConfig);

            // Find the case client
            $caseClientResult = \Civi\Api4\CaseContact::get(false)
            ->addSelect('contact_id')
            ->addWhere('case_id', '=', $caseId)
            ->execute();

            if ($caseClientResult->count() == 0) {
                throw new \Exception("Could not find client for case ID: $caseId");
            }

            $caseClient = $caseClientResult->first()['contact_id'];

            // Set relationship parameters based on direction
            $contactIdA = ($direction == 'a_b') ? $caseClient : $contactId;
            $contactIdB = ($direction == 'a_b') ? $contactId : $caseClient;

            // Check if relationship already exists
            $existingRel = \Civi\Api4\Relationship::get(false)
            ->addSelect('id')
            ->addWhere('relationship_type_id', '=', $relationshipTypeId)
            ->addWhere('contact_id_a', '=', $contactIdA)
            ->addWhere('contact_id_b', '=', $contactIdB)
            ->addWhere('case_id', '=', $caseId)
            ->execute();

            if ($existingRel->count() > 0) {
                // Relationship already exists, return its ID
                $relationshipId = $existingRel->first()['id'];
            } else {
                // Create new relationship
                $relationshipResult = \Civi\Api4\Relationship::create(false)
                ->addValue('relationship_type_id', $relationshipTypeId)
                ->addValue('contact_id_a', $contactIdA)
                ->addValue('contact_id_b', $contactIdB)
                ->addValue('case_id', $caseId)
                ->addValue('is_active', true)
                ->execute();

                $relationshipId = $relationshipResult->first()['id'] ?? null;

                if (!$relationshipId) {
                    throw new \Exception("Failed to create relationship");
                }
            }

            // Stamp activity_date_time from the DB's local "now" (same source as
            // created_date). Anonymous FormProcessor web requests can leave PHP's
            // default timezone at UTC, so letting activity_date_time default would
            // store it ~4h ahead of the real (server-local) time.
            $localNow = \CRM_Core_DAO::singleValueQuery('SELECT NOW()');

            // Create activity to record the relationship assignment
            $activityResult = \Civi\Api4\Activity::create(false)
            ->addValue('activity_type_id:name', 'Assign Case Role')
            ->addValue('source_contact_id', $sourceContactId)
            ->addValue('target_contact_id', [$contactId])
            ->addValue('case_id', [$caseId])
            ->addValue('status_id:name', 'Completed')
            ->addValue('activity_date_time', $localNow)
            ->addValue('subject', 'Case role assigned')
            ->execute();

            // Set output
            $output->setParameter('relationship_id', $relationshipId);

            \Civi::log()->info('MasAddRole.php - Successfully added case role: ' . $relationshipId);
        } catch (\Exception $e) {
            \Civi::log()->error('MasAddRole.php - MasAddRole error: ' . $e->getMessage());
            throw new \Exception('Error adding case role: ' . $e->getMessage());
        }
    }

    /**
     * Parse the relationship type configuration string
     *
     * @param string $relationshipType Format: "123_a_b" or "123_b_a"
     * @return array [relationshipTypeId, direction]
     */
    private function parseRelationshipType($relationshipType)
    {
        // Debug log the input value
        \Civi::log()->debug('MasAddRole.php - Parsing relationship type: ' . print_r($relationshipType, true));

        if (empty($relationshipType)) {
            throw new \Exception("Relationship type not specified");
        }

        // Handle string format "123_a_b" or "123_b_a"
        if (is_string($relationshipType) && strpos($relationshipType, '_') !== false) {
            $parts = explode('_', $relationshipType);
            if (count($parts) >= 3 && is_numeric($parts[0])) {
                return [(int)$parts[0], $parts[1] . '_' . $parts[2]];
            }
        }

        // Handle direct numeric ID - default to "a_b" direction
        if (is_numeric($relationshipType)) {
            return [(int)$relationshipType, 'a_b'];
        }

        throw new \Exception("Invalid relationship type format: " . print_r($relationshipType, true));
    }
}
