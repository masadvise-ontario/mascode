<?php

declare(strict_types=1);

/**
 * VC portal case-detail page — custom-field group cards.
 *
 * Spec: ~/gdrive-brianpkm/3-Resources/mascode-vc-portal-security-spec.md
 * Tests: tests/Security/CaseDetailAccessTest.php
 *
 * One grid (card) display per case custom-field group, so the page mirrors the
 * native vertical label/value layout. Groups are case-type scoped, so each card
 * additionally filters on case_type — only the cards for the open case's type
 * return a row. That makes the Project vs Service-Request layout conditional
 * automatically (no ng-if): a Project case shows the five Project_* cards and
 * the SR card stays empty, and vice-versa.
 *
 * SECURITY (spec risk #2): every card re-applies the SAME entitlement gate as
 * the header (pool OR coordinated-by-me, scoped to user_contact_id), so a forged
 * case id yields zero rows in every card. See buildCard() below.
 */

/**
 * Build a [SavedSearch, SearchDisplay] managed pair for one custom-field group.
 *
 * @param string $name        SavedSearch name (also display = <name>_Card_1).
 * @param string $label       Human label.
 * @param string $caseType    'service_request' | 'project'.
 * @param array  $fields      list of [selectKey, columnLabel].
 */
if (!function_exists('_vcCaseDetailCard')) {
function _vcCaseDetailCard(string $name, string $label, string $caseType, array $fields, bool $requireAnyNonNull = FALSE): array {
  $gate = $name . '_gate_rc';
  $select = ['id'];
  $columns = [];
  $nonNull = [];
  foreach ($fields as [$key, $colLabel]) {
    $select[] = $key;
    $columns[] = ['type' => 'field', 'key' => $key, 'label' => $colLabel, 'sortable' => FALSE];
    // strip :label / :name suffix for the emptiness check on the raw field.
    // IS NOT EMPTY (not IS NOT NULL) so empty-string custom fields also count
    // as empty — otherwise a touched-but-blank field keeps the group visible.
    $nonNull[] = [preg_replace('/:(label|name|abbr)$/', '', $key), 'IS NOT EMPTY'];
  }
  $where = [
    ['OR', [
      ['status_id:name', '=', 'Sent for Assignment'],
      [$gate . '.near_contact_id', '=', 'user_contact_id'],
    ]],
    ['case_type_id:name', '=', $caseType],
  ];
  // When requested, only return a row if at least one field in the group is
  // populated — so a fully-empty group returns 0 rows and the page hides it.
  if ($requireAnyNonNull) {
    $where[] = ['OR', $nonNull];
  }
  return [
    [
      'name' => 'SavedSearch_' . $name,
      'entity' => 'SavedSearch',
      'cleanup' => 'unused',
      'update' => 'unmodified',
      'params' => [
        'version' => 4,
        'values' => [
          'name' => $name,
          'label' => $label,
          'api_entity' => 'Case',
          'api_params' => [
            'version' => 4,
            'select' => $select,
            'orderBy' => [],
            'where' => $where,
            'groupBy' => ['id'],
            'join' => [
              [
                'RelationshipCache AS ' . $gate,
                'LEFT',
                ['id', '=', $gate . '.case_id'],
                [$gate . '.near_relation:name', '=', '"Case Coordinator is"'],
                [$gate . '.is_active', '=', TRUE],
              ],
            ],
            'having' => [],
          ],
        ],
        'match' => ['name'],
      ],
    ],
    [
      'name' => 'SavedSearch_' . $name . '_SearchDisplay_' . $name . '_Card_1',
      'entity' => 'SearchDisplay',
      'cleanup' => 'unused',
      'update' => 'unmodified',
      'params' => [
        'version' => 4,
        'values' => [
          'name' => $name . '_Card_1',
          'label' => $label . ' Card',
          'saved_search_id.name' => $name,
          'type' => 'grid',
          'settings' => [
            'description' => NULL,
            'sort' => [],
            'limit' => 1,
            'pager' => [],
            'placeholder' => 1,
            'colno' => 1,
            'columns' => $columns,
            'actions' => ['download'],
            'classes' => ['table'],
            // grid renders each row as a card; colno=1 = single full-width card,
            // CSS (css/vc-case-detail.css) stacks the fields as label/value rows
          ],
          'acl_bypass' => TRUE,
        ],
        'match' => ['saved_search_id', 'name'],
      ],
    ],
  ];
}
}

return array_merge(

  // -------- Service Request --------
  _vcCaseDetailCard('Case_Details_VC_SR_Fields', 'Service Request', 'service_request', [
    ['Cases_SR_Projects_.MAS_SR_Case_Code', 'MAS SR Case Code'],
    ['Cases_SR_Projects_.Related_Project_Case_Code', 'Related Project Case Code'],
    ['Cases_SR_Projects_.Practice_Area:label', 'Practice Area'],
    ['Cases_SR_Projects_.Referral:label', 'Source of Interest'],
    ['Cases_SR_Projects_.Notes', 'Notes'],
    ['Cases_SR_Projects_.Request_Details', 'Request Details'],
    ['Cases_SR_Projects_.Virtual_Work:label', 'Virtual Work'],
    ['Cases_SR_Projects_.Requested_Start_Date', 'Requested Start Date'],
    ['Cases_SR_Projects_.Flexible_Start_Date:label', 'Flexible Start Date'],
    ['Cases_SR_Projects_.Link_to_RCS_Document', 'Link to RCS Document'],
    ['Cases_SR_Projects_.Link_to_SAS_Document', 'Link to SAS Document'],
    ['Cases_SR_Projects_.Link_to_Signed_T_C_s', "Link to Signed T&C's"],
    ['Cases_SR_Projects_.Board_Approval:label', 'Board Approval'],
    ['Cases_SR_Projects_.Board_Support:label', 'Board Support'],
    ['Cases_SR_Projects_.T_C_Authorized_and_Approved:label', 'T&C Authorized and Approved'],
    ['Cases_SR_Projects_.Authorized:label', 'Authorized'],
    ['Cases_SR_Projects_.Authorized_Name', 'Authorized Name'],
    ['Cases_SR_Projects_.Authorized_Title', 'Authorized Title'],
    ['Cases_SR_Projects_.Authorized_Date', 'Authorized Date'],
  ]),

  // -------- Project --------
  _vcCaseDetailCard('Case_Details_VC_Proj', 'Project', 'project', [
    ['Projects.MAS_Project_Case_Code', 'MAS Project Case Code'],
    ['Projects.Related_SR_Case_Code', 'Related SR Case Code'],
    ['Projects.Practice_Area:label', 'Practice Area'],
    ['Projects.Project_Type:label', 'Project Type'],
    ['Projects.Notes', 'Notes'],
    ['Projects.Estimated_Completion_Date', 'Estimated Completion Date'],
    ['Projects.Link_to_Project_Definition_Document', 'Link to Project Definition Document'],
    ['Projects.Link_to_Project_Completion', 'Link to Project Completion'],
  ]),
  _vcCaseDetailCard('Case_Details_VC_ProjDef', 'Project Definition', 'project', [
    ['Project_Definition.estimated_duration', 'Estimated Duration'],
    ['Project_Definition.assistance_provided', 'Assistance the Volunteer Consultant has agreed to provide'],
    ['Project_Definition.expected_benefits', 'Expected project benefits, impact, consequences'],
    ['Project_Definition.project_completion', 'What defines project completion?'],
  ], TRUE),
  _vcCaseDetailCard('Case_Details_VC_ProjAuth', 'Project Definition - Authorization', 'project', [
    ['Project_Definition_Authorization.capacity_increase', 'How should this project increase your capacity to serve your clients?'],
    ['Project_Definition_Authorization.client_signature', 'Client Contact Signature'],
    ['Project_Definition_Authorization.client_title', 'Title'],
    ['Project_Definition_Authorization.authorized_certification:label', 'I certify that I am authorized to sign for this agency'],
  ], TRUE),
  _vcCaseDetailCard('Case_Details_VC_ProjCloseVC', 'Project Close - VC Report', 'project', [
    ['Project_Close_VC.hours_worked', 'Hours Worked by Volunteer Consultant'],
    ['Project_Close_VC.expenses_incurred', 'Expenses Incurred'],
    ['Project_Close_VC.services_delivered', 'Description of Services Delivered'],
  ], TRUE),
  _vcCaseDetailCard('Case_Details_VC_ProjCloseClient', 'Project Close - Client Feedback', 'project', [
    ['Project_Close_Client.satisfaction:label', 'How satisfied are you with the work done by MAS?'],
    ['Project_Close_Client.satisfaction_comment', 'Satisfaction Comment'],
    ['Project_Close_Client.would_use_mas_again:label', 'Would you use MAS again?'],
    ['Project_Close_Client.reuse_comment', 'Reuse Comment'],
    ['Project_Close_Client.would_work_with_vc_again:label', 'Would you consider working with the MAS Volunteer Consultant again?'],
    ['Project_Close_Client.would_recommend_mas:label', 'Would you recommend MAS to another not for profit organization?'],
    ['Project_Close_Client.benefits_realized', 'Please describe the benefits realized and any other additional comments'],
    ['Project_Close_Client.use_in_marketing:label', 'Could we use your comments in our marketing materials?'],
    ['Project_Close_Client.share_with_vc:label', 'Could we share your comments with the Volunteer Consultant who worked with you?'],
  ], TRUE),

);
