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
 *  - Linked_Project: the Project case the donation is for. The picker is
 *    restricted to Project cases by DonationSubscriber::onApiPrepare (core's
 *    EntityReference custom fields have no stored filter). That narrows the
 *    PICKER only: an API write can still link a non-Project case, and the
 *    reports simply ignore such a link.
 *  - Linked_VC: the VC credited. Filled from the project's Case Coordinator by
 *    DonationSubscriber when empty and there is only one, then STORED, so a later role
 *    change does not rewrite who a past donation is credited to.
 *
 * Extends every contribution type deliberately. Restricting via
 * extends_entity_column_value:name silently stores NULL (= every type) when the
 * pseudoconstant does not resolve, so "all" is stated rather than implied.
 * A gift covering two projects is entered as two contributions.
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
        'label' => 'Project',
        'data_type' => 'EntityReference',
        'fk_entity' => 'Case',
        'html_type' => 'Autocomplete-Select',
        'help_post' => 'Type the project number without the P, e.g. 26123 for P26123 (older project names omit the P). Client donations only; one project per contribution (split a gift that covers two).',
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
        'label' => 'Volunteer Consultant',
        'data_type' => 'EntityReference',
        'fk_entity' => 'Contact',
        'html_type' => 'Autocomplete-Select',
        'help_post' => 'Lists the project\'s coordinators. Filled automatically when there is only one; otherwise choose the lead.',
        'is_required' => FALSE,
        'is_searchable' => TRUE,
        'is_active' => TRUE,
        'weight' => 2,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
];
