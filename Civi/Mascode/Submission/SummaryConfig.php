<?php

declare(strict_types=1);

namespace Civi\Mascode\Submission;

/**
 * Per-form composition spec for SubmissionSummaryService.
 *
 * Keyed by Afform server_route. Replaces the old hardcoded label maps in
 * AfformSubmitSubscriber — labels and option values are resolved from CiviCRM
 * metadata at render time, so only the STRUCTURE (which entity / which custom
 * group / which contact blocks) lives here.
 *
 * `idKey` values match the keys AfformSubmitSubscriber already stores in its
 * per-session $submissionData (organization_id, primary_contact_id, ...), so the
 * summary is built from the actual submitted entities — no AfformSubmission
 * re-query (which would be race-prone).
 */
class SummaryConfig
{
    public static function forRoute(string $route): ?array
    {
        return self::map()[$route] ?? null;
    }

    private static function map(): array
    {
        return [
            // RCS: data lives on the Service Request case + the contacts.
            'civicrm/mas-rcs-form' => [
                'kind' => 'case',
                'caseGroup' => 'Cases_SR_Projects_',
                'caseGroupTitle' => 'Request Details',
                // Internal / system-generated fields that should not appear in the
                // (client-facing) confirmation email or the activity summary.
                'caseGroupExclude' => [
                    'Notes',
                    'MAS_SR_Case_Code',
                    'Related_Project_Case_Code',
                    'Link_to_RCS_Document',
                    'Link_to_SAS_Document',
                    'Link_to_Signed_T_C_s',
                    'Referral',
                    'Practice_Area',
                    'Board_Approval',
                    'Authorized',
                ],
                'contactBlocks' => [
                    [
                        'idKey' => 'organization_id',
                        'title' => 'Organization',
                        'fields' => [
                            ['field' => 'organization_name', 'label' => 'Organization Name'],
                            ['field' => 'email_primary.email', 'label' => 'Email'],
                            ['field' => 'phone_primary.phone', 'label' => 'Phone'],
                        ],
                    ],
                    [
                        'idKey' => 'primary_contact_id',
                        'title' => 'Primary Contact',
                        'fields' => [
                            ['field' => 'display_name', 'label' => 'Name'],
                            ['field' => 'job_title', 'label' => 'Title'],
                            ['field' => 'email_primary.email', 'label' => 'Email'],
                            ['field' => 'phone_primary.phone', 'label' => 'Phone'],
                        ],
                    ],
                    [
                        'idKey' => 'president_id',
                        'title' => 'President / Board Chair',
                        'fields' => [
                            ['field' => 'display_name', 'label' => 'Name'],
                            ['field' => 'email_primary.email', 'label' => 'Email'],
                        ],
                    ],
                    [
                        'idKey' => 'executive_director_id',
                        'title' => 'Executive Director',
                        'fields' => [
                            ['field' => 'display_name', 'label' => 'Name'],
                            ['field' => 'email_primary.email', 'label' => 'Email'],
                        ],
                    ],
                ],
            ],

            // Surveys + project-close: data lives on the activity's custom group.
            'civicrm/mas-sass-form' => [
                'kind' => 'activity',
                'activityGroups' => ['Short_Self_Assessment_Survey'],
            ],
            'civicrm/mas-sasf-form' => [
                'kind' => 'activity',
                'activityGroups' => ['Full_Self_Assessment_Survey'],
            ],
            'civicrm/mas-pclose-vc' => [
                'kind' => 'activity',
                'activityGroups' => ['Project_Close_VC_Fields'],
            ],
            'civicrm/mas-pclose-client' => [
                'kind' => 'activity',
                'activityGroups' => ['Project_Close_Client_Fields'],
            ],
        ];
    }
}
