<?php

declare(strict_types=1);

/**
 * Donation → Project / VC link (donations ticket DN-1; spec BrianPKM
 * 3-Resources/mas-donation-process.md §3a.2).
 *
 * Replaces the free-text "P99001 - <VC> - <topic>" convention in
 * Contribution.source with real references, so the Treasurer's
 * donations-per-completed-project report can be computed instead of
 * re-keyed.
 *
 *  - Linked_Project: the Project case(s) the donation is for. The picker is
 *    restricted to Project cases by DonationSubscriber::onApiPrepare (core's
 *    EntityReference custom fields have no stored filter). That narrows the
 *    PICKER only: an API write can still link a non-Project case, and the
 *    reports simply ignore such a link.
 *  - Linked_VC: the VC(s) credited. When the list is empty, DonationSubscriber
 *    fills in each linked project's coordinator where it has only one, and
 *    the CSM picks the rest. STORED, so a later role change does not rewrite
 *    who a past donation is credited to.
 *
 * Extends every contribution type deliberately. Restricting via
 * extends_entity_column_value:name silently stores NULL (= every type) when the
 * pseudoconstant does not resolve, so "all" is stated rather than implied.
 * One contribution per cheque (2026-10-08): both fields hold SEVERAL values
 * (serialize), so a cheque that covers two projects is one contribution
 * linked to both. The quarterly report splits its net evenly across them.
 * Core converted the existing single values in place when serialize was
 * switched on (CRM_Core_BAO_CustomField::getAlterSerializeSQL), and drops
 * the FK constraint, which a value-separated column cannot carry. Read them
 * as arrays (DonationLinker::ids()); APIv4 cannot implicit-join through a
 * serialized field, so the reports load the cases and contacts separately.
 */
return [
  [
    'name' => 'CustomGroup_Donation_Link',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Donation_Link',
        'title' => 'Project & Volunteer Consultant',
        'extends' => 'Contribution',
        'style' => 'Inline',
        'collapse_display' => FALSE,
        'is_active' => TRUE,
        'weight' => 1,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomField_Donation_Link_Linked_Project',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Donation_Link',
        'name' => 'Linked_Project',
        'label' => 'Projects',
        'data_type' => 'EntityReference',
        'fk_entity' => 'Case',
        'html_type' => 'Autocomplete-Select',
        'serialize' => 1,
        'help_post' => 'Type the project number without the P, e.g. 26123 for P26123 (older project names omit the P). Client donations only. Pick every project this cheque covers.',
        'is_required' => FALSE,
        'is_searchable' => TRUE,
        'is_active' => TRUE,
        'weight' => 1,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_Donation_Link_Linked_VC',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Donation_Link',
        'name' => 'Linked_VC',
        'label' => 'Volunteer Consultants',
        'data_type' => 'EntityReference',
        'fk_entity' => 'Contact',
        'html_type' => 'Autocomplete-Select',
        'serialize' => 1,
        'help_post' => 'Lists the coordinators of every project picked above. A project with only one coordinator fills in automatically; pick the lead for the others.',
        'is_required' => FALSE,
        'is_searchable' => TRUE,
        'is_active' => TRUE,
        'weight' => 2,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_Donation_Link_Linked_Project_Codes',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Donation_Link',
        'name' => 'Linked_Project_Codes',
        'label' => 'Project codes',
        'data_type' => 'String',
        'html_type' => 'Text',
        'text_length' => 255,
        // Written by DonationLinker::refreshCodes() after every save; staff never type it.
        'is_view' => TRUE,
        'help_post' => 'Filled in from Projects when the contribution is saved.',
        'is_required' => FALSE,
        'is_searchable' => TRUE,
        'is_active' => TRUE,
        'weight' => 3,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
];
