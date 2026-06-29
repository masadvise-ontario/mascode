<?php

require_once 'dataload_header.php';

class CiviCaseImport
{
    public function __construct() {}

    public function run()
    {
        // This is source and the target database
        // since MAS does not have the authority to create databases
        global $wpdb;
        global $nina;
        global $last_id;
        echo 'Source and Target Database is ' . $wpdb->dbname . ' and Ninas ID is ' . $nina . ' and $last_id is ' . $last_id . '<br>';

        // Fetch case statuses and build the associative array  (each array entry row is an array)
        $caseStatuses = \Civi\Api4\OptionValue::get(TRUE)
            ->addSelect('label', 'grouping')
            ->addWhere('option_group_id:label', '=', 'Case Status')
            ->setLimit(50)
            ->execute();
        $statusMap = [];
        foreach ($caseStatuses as $status) {
            $statusMap[$status['label']] = $status['grouping'];
        }

        // Fetch a limited set of rows from the project table
        $p_sql = $wpdb->prepare(
            "SELECT * FROM bgf_dataload_tProject WHERE ProjectID > %d AND Processed IS NOT TRUE ORDER BY ProjectID LIMIT %d",
            $last_id,
            500 // For example, to limit the results to x rows
        );
        $p_results = $wpdb->get_results($p_sql);

        // Iterate through each row
        foreach ($p_results as $project) {

            $projectID = str_pad($project->ProjectID, 5, '0', STR_PAD_LEFT);

            if ($projectID <= '24156') {
                $updateProject = true;
            } else {
                $updateProject = false;
            }

            $subject = $projectID . ' ' . $project->Title;

            // update the practice area and type attributes of the project object
            [$practiceArea, $projectType] = $this->updatePracticeAreaAndType($project->PreacticeArea, $project->ProjectType);

            // Fetch the project hours
            $hr_sql = $wpdb->prepare(
                "SELECT * FROM bgf_dataload_tProjectMASRepHours WHERE ProjectID = %d",
                $project->ProjectID
            );
            $hr_results = $wpdb->get_results($hr_sql);
            if (!empty($hr_results)) {
                $hours = $hr_results[0]->Hours;
            } else {
                $hours = null;
            }

            // Get the CiviCRM ClientID
            $project->ClientID_Clean = $this->getClientID($project->ClientID);

            $end_date = $this->updateEndDate($projectID, $project->Status, $project->EndDate, $project->LastUpdateDate);

            if ($project->Status == 'Closed') {
                $status = 'Closed - Not Completed';
            } else {
                $status = $project->Status;
            }

            if (!empty($project->ClientID_Clean)) {
                // Create a case
                if ($updateProject) {
                    $civiCase = \Civi\Api4\CiviCase::update(TRUE)
                        ->addValue('start_date', $project->StartDate)
                        ->addValue('end_date', $end_date)
                        ->addValue('status_id:label', $status)
                        ->addValue('Projects.Practice_Area', $practiceArea)
                        ->addValue('Projects.Project_Type', $projectType)
                        ->addValue('Project_Close_VC.hours_worked', $hours)
                        ->addValue('Projects.Notes', $project->Notes)
                        ->addWhere('subject', '=', $subject)
                        ->execute();
                } else {
                    $civiCase = \Civi\Api4\CiviCase::create(TRUE)
                        ->addValue('case_type_id.name', 'project')
                        ->addValue('subject', $subject)
                        ->addValue('creator_id', $nina)
                        ->addValue('start_date', $project->StartDate)
                        ->addValue('end_date', $end_date)
                        ->addValue('status_id:label', $status)
                        ->addValue('Projects.Practice_Area', $practiceArea)
                        ->addValue('Projects.Project_Type', $projectType)
                        ->addValue('Project_Close_VC.hours_worked', $hours)
                        ->addValue('Projects.Notes', $project->Notes)
                        // DefinitionDocDate, CompletionDocDate, EvaluationDocDate - last used over 5 yrs ago
                        ->addValue(
                            'contact_id',
                            [
                                $project->ClientID_Clean,
                            ]
                        )
                        ->execute();
                }

                // Access the ID of the first created case
                $case_id = $civiCase[0]['id'];

                // Should the relationships be active or inactive?
                $thisStatus = $this->getStatusClass($project->Status, $statusMap);
                if ($thisStatus == "Closed") {
                    $relationshipActive = FALSE;
                } else {
                    $relationshipActive = TRUE;
                }

                if ($updateProject) {
                    $civiRelationship = \Civi\Api4\Relationship::delete(TRUE)
                        ->addWhere('case_id', '=', $case_id)
                        ->execute();
                }

                // Create an activity to link to a Service Request if applicable  BGF
                if (!empty($project->RequestID)) {
                    $project->RequestID_Clean = $this->getRequestID($project->RequestID);
                    if (!empty($project->RequestID_Clean)) {
                        $this->linkSR($case_id, $project);
                    } else {
                        echo "Unable to link request $project->RequestID to project $project->ProjectID because request id is missing.  <br>";
                    }
                }

                // link mas reps
                $this->linkMASReps($case_id, $project, $relationshipActive);

                // link client reps
                $this->linkClientReps($case_id, $project, $relationshipActive);

                // link activities
                $this->linkActivities($case_id, $project);

                // add a status change activity for the end date if applicable
                if ($project->EndDate <> "") {
                    $civiActivity = \Civi\Api4\Activity::create(TRUE)
                        ->addValue('activity_type_id:label', 'Change Case Status')
                        ->addValue('source_contact_id', $nina)
                        ->addValue('target_contact_id', [
                            $project->ClientID_Clean,
                        ])
                        ->addValue('case_id', $case_id)
                        ->addValue('status_id:label', 'Completed')
                        ->addValue('subject', 'Case status changed to ' . $status)
                        ->addvalue('activity_date_time', $end_date)
                        ->execute();
                }
            }

            $last_id = $project->ProjectID;
        }

        echo 'Works OK so far...<br>';
        // Construct the URL correctly using site_url or home_url
        $url = site_url('/wp-content/uploads/civicrm/ext/mascode/extern/project_import.php?last_id=' . urlencode($last_id));
        // Output the correct URL
        echo 'Run <a href="' . esc_url($url) . '">' . esc_url($url) . '</a><br>';
    }

    // Get the CiviCRM Client ID given the External (Access) Client ID
    private function getClientID($clientID)
    {
        $contacts = \Civi\Api4\Contact::get(TRUE)
            ->addSelect('id')
            ->addWhere('external_identifier', '=', $clientID)
            ->execute();
        $count = $contacts->count();  // Store count in variable first
        // Check count() directly
        if ($count == 0) {
            echo "External Client ID $clientID not found. <br>";
            return null;
        } elseif ($count == 1) {
            return $contacts[0]['id']; // Accessing 'id' as an array       
        } else {
            echo "Multiple External Client ID $clientID found. <br>";
            return null;
        }
    }
    // Lookup the status class by label
    private function getStatusClass($label, $statusMap)
    {
        if (isset($statusMap[$label])) {
            return $statusMap[$label];
        } else {
            return null;
        }
    }
    private function updatePracticeAreaAndType($practiceArea, $projectType)
    {
        if ($practiceArea == 'FAC') {
            if (
                $projectType == 'FAC' or
                $projectType == ''
            ) {
                $practiceArea = 'GEN';
                $projectType = 'FAC';
            } else {
                $practiceArea = $projectType;
                $projectType = 'FAC';
            }
        }

        if ($practiceArea == 'PRESENT') {
            if (
                $projectType == 'FAC'
                or
                $projectType == ''
            ) {
                $practiceArea = 'GEN';
                $projectType = 'PRESENT';
            } else {
                $practiceArea = $projectType;
                $projectType = 'PRESENT';
            }
        }

        if ($practiceArea == '') {
            if (
                $projectType == 'FAC'
                or
                $projectType == ''
            ) {
                $practiceArea = 'GEN';
            } else {
                $practiceArea = $projectType;
            }
        }

        if (
            $projectType <> 'FAC' and
            $projectType <> 'PRESENT'
        ) $projectType = '';

        return [$practiceArea, $projectType];
    }
    private function updateEndDate($projectID, $status, $endDate, $lastUpdateDate)
    {
        global $wpdb;

        // Early return if end date exists
        if (!empty($endDate)) {
            return $endDate;
        }

        // Define open statuses
        $openStatuses = ["Open", "Active", "On Hold"];

        // Return empty end date for open statuses
        if (in_array($status, $openStatuses, true)) {
            return '';  // or return null, depending on your needs
        }

        // Fetch the closed date for non-open statuses
        $ph_sql = $wpdb->prepare(
            "SELECT Date FROM bgf_dataload_tProjectStatusHistory 
                WHERE ProjectID = %s
                ORDER BY Date DESC 
                LIMIT 1",
            $projectID
        );

        $ph_results = $wpdb->get_results($ph_sql);

        // Check if results exist before accessing array
        if (!empty($ph_results)) {
            return $ph_results[0]->Date;
        } else {
            return $lastUpdateDate;
        }
    }
    private function getRequestID($requestID)
    {
        $request = \Civi\Api4\CiviCase::get(TRUE)
            ->addSelect('id')
            ->addWhere('subject', '=', $requestID)
            ->setLimit(1)
            ->execute();
        $count = $request->count();  // Store count in variable first
        // Check count() directly
        if ($count == 1) {
            return $request[0]['id'];
        } else {
            return null;
        }
    }
    private function linkSR($case_id, $project)
    {
        global $nina;
        // Create a Link Cases activity, and link it to one case
        $civiActivity = \Civi\Api4\Activity::create(TRUE)
            ->addValue('activity_type_id:label', 'Link Cases')
            ->addValue('source_contact_id', $nina)
            ->addValue('target_contact_id', [
                $project->ClientID_Clean,
            ])
            ->addValue('case_id', $case_id)
            ->addValue('status_id:label', 'Completed')
            ->addValue('subject', 'Create link between - Service Request (CaseID: ' . $project->RequestID . ') and Project (CaseID: ' . $case_id . ').')
            ->execute();
        $activity_id = $civiActivity[0]['id'];
        // Then link the activity to the other case
        $civiCaseActivity = \Civi\Api4\CaseActivity::create(TRUE)
            ->addValue('case_id', $project->RequestID_Clean)
            ->addValue('activity_id', $activity_id)
            ->execute();
    }
    private function linkMASReps($case_id, $project, $relationshipActive)
    {
        global $wpdb;
        // Fetch all rows from the project MAS reps table
        $mr_sql = $wpdb->prepare("SELECT * FROM bgf_dataload_tProjectMASReps WHERE ProjectID = %s", $project->ProjectID);
        $mr_results = $wpdb->get_results($mr_sql);
        // Check if any rows are returned
        if (!empty($mr_results)) {
            // Iterate through each row
            foreach ($mr_results as $masRep) {
                // Get the MAS rep
                $ext_mas_rep_id = strval(intval($masRep->RepID) + 2000000);
                $mas_rep_id = $this->getClientID($ext_mas_rep_id);
                if (!empty($mas_rep_id)) {
                    // Create a MAS rep relationship
                    try {
                        $civiRelationship = \Civi\Api4\Relationship::create(TRUE)
                            ->addValue('contact_id_a', $mas_rep_id)     // mas rep
                            ->addValue('contact_id_b', $project->ClientID_Clean)     // client
                            ->addValue('relationship_type_id:label', 'Case Coordinator is (MAS Rep)')
                            ->addValue('is_active', $relationshipActive)  // depends on project
                            ->addValue('case_id', $case_id)
                            ->execute();
                    } catch (Exception $e) {
                        // Handle duplicate error or log the message
                        echo "Error creating MAS relationship: " . $e->getMessage() . " for Case:$case_id Client:$project->ClientID_Clean MAS Rep:$mas_rep_id<br>";
                    }
                } else {
                    echo "Unable to add MAS rep relationshp for project $project->ProjectID because external id $mas_rep_id is missing.  <br>";
                }
            }
        }
    }
    private function linkClientReps($case_id, $project, $relationshipActive)
    {
        global $wpdb;
        // Fetch all rows from the project client reps table
        $cr_sql = $wpdb->prepare("SELECT * FROM bgf_dataload_tProjectClientReps WHERE ProjectID = %s", $project->ProjectID);
        $cr_results = $wpdb->get_results($cr_sql);
        // Check if any rows are returned
        if (!empty($cr_results)) {
            // Iterate through each row
            foreach ($cr_results as $clientRep) {
                // Get the client rep
                $ext_client_rep_id = strval(intval($clientRep->ClientRepID) + 1000000);
                $client_rep_id = $this->getClientID($ext_client_rep_id);
                if (!empty($client_rep_id)) {
                    // Create a client rep relationship
                    try {
                        $civiRelationship = \Civi\Api4\Relationship::create(TRUE)
                            ->addValue('contact_id_a', $client_rep_id)     // client rep
                            ->addValue('contact_id_b', $project->ClientID_Clean)     // client
                            ->addValue('relationship_type_id:label', 'Case Client Rep is')
                            ->addValue('is_active', $relationshipActive)  // depends on project
                            ->addValue('case_id', $case_id)
                            ->execute();
                    } catch (Exception $e) {
                        // Handle duplicate error or log the message
                        echo "Error creating Client relationship: " . $e->getMessage() . " for Case:$case_id Client:$project->ClientID_Clean Client Rep:$client_rep_id<br>";
                    }
                } else {
                    echo "Unable to add client rep relationshp for project $project->ProjectID because external id $ext_client_rep_id is missing.  <br>";
                }
            }
        }
    }
    private function linkActivities($case_id, $project)
    {
        global $wpdb;
        global $nina;
        // Fetch all rows from the activity table
        $activity_sql = $wpdb->prepare("SELECT * FROM bgf_dataload_tActivity WHERE ProjectID = %s AND Processed IS NOT TRUE", $project->ProjectID);
        $activity_results = $wpdb->get_results($activity_sql);
        // Check if any rows are returned
        if (!empty($activity_results)) {
            // Iterate through each row
            foreach ($activity_results as $activity) {
                // Source is Nina or the MAS Rep
                if (!empty($activity->RepID)) {
                    // Get the MAS rep
                    $ext_mas_rep_id = strval(intval($activity->RepID) + 2000000);
                    $mas_rep_id = $this->getClientID($ext_mas_rep_id);
                    if (!empty($mas_rep_id)) {
                        $in_source = $mas_rep_id;
                    } else {
                        $in_source = $nina;
                    }
                } else {
                    $in_source = $nina;
                }
                // Create activity record
                if (strlen($activity->Notes) < 100) {
                    $activity_subject = $activity->Notes;
                } else {
                    $activity_subject = substr($activity->Notes, 0, 97) . '...';
                }
                $civiActivity = \Civi\Api4\Activity::create(TRUE)
                    ->addValue('activity_type_id:label', 'Case Info Update')
                    ->addValue('case_id', $case_id)
                    ->addValue('source_contact_id', $in_source)
                    ->addValue('subject', $activity_subject)
                    ->addValue('details', $activity->Notes)
                    ->addValue('activity_date_time', $activity->ContactDate)
                    ->addValue('status_id:label', 'Completed')
                    ->execute();
            }
        }
    }
}

function civiCaseImportFn()
{
    $civiCaseImport = new CiviCaseImport();
    $civiCaseImport->run();
}

civiCaseImportFn();

echo 'Now at end01...<br>';
