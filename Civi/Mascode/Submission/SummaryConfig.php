<?php

declare(strict_types=1);

namespace Civi\Mascode\Submission;

/**
 * Per-form composition spec for SubmissionSummaryService.
 *
 * Keyed by Afform server_route, plus a few "mas:"-prefixed RECORD keys at the
 * bottom that are not routes at all — they compose a complete printable
 * document for the VC (project header + every group that makes up the
 * paperwork) rather than echoing one form's answers back to its submitter.
 *
 * Replaces the old hardcoded label maps in AfformSubmitSubscriber — labels and
 * option values are resolved from CiviCRM metadata at render time, so only the
 * STRUCTURE (which entity / which custom group / which contact blocks) lives
 * here.
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
                // Mirror the form's six themed categories in the summary
                // (activity details + confirmation email). Field lists match
                // ang/afformMASSASF.aff.html section order; any field not
                // listed falls into a catch-all section so new questions
                // never silently vanish.
                'activitySections' => [
                    'Full_Self_Assessment_Survey' => [
                        'Strategy' => [
                            'q01_vision_mission_clear',
                            'q02_unique_services',
                            'q03_strategic_goals',
                            'q04_annual_operational_plan',
                        ],
                        'Governance' => [
                            'q05_governance_documents',
                            'q06_board_composition',
                            'q07_board_committees',
                            'q08_board_effectiveness',
                            'q09_board_self_assessment',
                        ],
                        'Finance' => [
                            'q10_budget_financial_statements',
                            'q11_risk_management',
                            'q12_contingency_fund',
                            'q13_audit_review',
                            'q14_funding_contracts',
                            'q15_donations_policy',
                            'q16_financial_reporting',
                            'q17_financial_viability',
                        ],
                        'Human Resources' => [
                            'q18_executive_director_confidence',
                            'q19_executive_limitations',
                            'q20_sufficient_qualified_staff',
                            'q21_diverse_employee_cohort',
                            'q22_job_descriptions_evaluations',
                            'q23_hr_policy_manual',
                            'q24_compensation_review',
                        ],
                        'Volunteers' => [
                            'q25_volunteer_involvement',
                            'q26_volunteer_job_descriptions',
                            'q27_volunteer_screening',
                            'q28_diverse_volunteer_cohort',
                            'q29_client_group_volunteers',
                            'q30_volunteer_positions_effective',
                        ],
                        'Communications and Fundraising' => [
                            'q31_fundraising_strategy',
                            'q32_compelling_communications',
                            'q33_communication_guidelines',
                            'q34_website_technology',
                            'q35_positive_reputation',
                        ],
                    ],
                ],
            ],
            // Project close: answers live on the PROJECT CASE (2026-06-14
            // data-model decision).
            // Goes to the VC (its submitter), so it carries the project header
            // and is printable as a record of what they filed.
            'civicrm/mas-pclose-vc' => [
                'kind' => 'case',
                'caseHeader' => true,
                'caseGroup' => 'Project_Close_VC',
                'caseGroupTitle' => 'Project Close - VC Report',
            ],
            'civicrm/mas-pclose-client' => [
                'kind' => 'case',
                'caseGroup' => 'Project_Close_Client',
                'caseGroupTitle' => 'Project Close - Client Feedback',
            ],
            // Project Definition: answers live on the PROJECT CASE (2026-06-14
            // data-model decision). The VC definition and the client
            // authorization are separate case custom groups.
            // Goes to the VC (its submitter) — same reasoning as mas-pclose-vc.
            'civicrm/mas-pdef-vc' => [
                'kind' => 'case',
                'caseHeader' => true,
                'caseGroup' => 'Project_Definition',
                'caseGroupTitle' => 'Project Definition',
            ],
            'civicrm/mas-pdef-client' => [
                'kind' => 'case',
                'caseGroup' => 'Project_Definition_Authorization',
                'caseGroupTitle' => 'Project Definition - Authorization',
            ],

            // ---------------------------------------------------------------
            // Record compositions (not Afform routes).
            //
            // Keyed and resolved exactly like a route, but composed for the
            // VC's own records rather than echoing one form's answers back to
            // whoever submitted it. MAS's ask was that a VC be able to print
            // the email instead of the form, so each of these is a complete,
            // self-contained document: which project, then every group that
            // makes up the paperwork.
            //
            // The "mas:" prefix keeps them from ever colliding with a real
            // server_route.
            // ---------------------------------------------------------------

            // Client has authorized the definition. Both halves belong here:
            // the VC's definition is what was agreed to, the authorization is
            // the agreement. Either alone is an incomplete record.
            'mas:record-pd-vc' => [
                'kind' => 'case',
                'caseHeader' => true,
                'caseGroups' => [
                    ['group' => 'Project_Definition', 'title' => 'Project Definition'],
                    ['group' => 'Project_Definition_Authorization', 'title' => 'Client Authorization'],
                ],
            ],

            // Client's close feedback, forwarded to the VC when the client
            // agreed to share it (Project_Close_Client.share_with_vc = Yes).
            // share_with_vc and use_in_marketing are the client's answers about
            // consent, not feedback about the project — excluded so the VC
            // reads the feedback rather than the permissions paperwork.
            'mas:record-close-client-for-vc' => [
                'kind' => 'case',
                'caseHeader' => true,
                'caseGroups' => [
                    [
                        'group' => 'Project_Close_Client',
                        'title' => 'Client Feedback',
                        'exclude' => ['share_with_vc', 'use_in_marketing'],
                    ],
                ],
            ],
        ];
    }
}
