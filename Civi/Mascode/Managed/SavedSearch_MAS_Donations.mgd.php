<?php

declare(strict_types=1);

/**
 * "MAS Donations" list (donations ticket DN-4; spec BrianPKM
 * 3-Resources/mas-donation-process.md §3d).
 *
 * Every donation, with the project and VC it is linked to, and that project's
 * status and close date. It replaces both of the Treasurer's monthly logs
 * (client and VC) and sheet 1 of his quarterly workbook: filter by type for
 * one log or the other, and by "Project closed" for the per-project view.
 * Gross, fee and net sit side by side because the board sees net (spec §4 Q12)
 * and the Treasurer reconciles gross. CAF Donation is listed too (R7: in
 * totals, though not a donation); filter it out by type when counting gifts.
 *
 * Staff only, hosted on afsearchMASDonations. No acl_bypass: the display runs
 * under the viewer's own contribution permissions.
 */
$p = 'Donation_Link.Linked_Project';
return [
  [
    'name' => 'SavedSearch_MAS_Donations',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Donations',
        'label' => 'MAS Donations',
        'api_entity' => 'Contribution',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'receive_date',
            'contact_id',
            'contact_id.display_name',
            'contact_id.contact_type:label',
            'financial_type_id:label',
            'payment_instrument_id:label',
            'contribution_status_id:label',
            'total_amount',
            'fee_amount',
            'net_amount',
            $p,
            "$p.Projects.MAS_Project_Case_Code",
            "$p.subject",
            "$p.status_id:label",
            "$p.end_date",
            'Donation_Link.Linked_VC.display_name',
            'source',
          ],
          'orderBy' => [],
          'where' => [
            ['financial_type_id:name', 'IN', ['Client Donation', 'Private Donation', 'Donation', 'CAF Donation']],
            ['is_test', '=', FALSE],
          ],
          'groupBy' => [],
          'join' => [],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SearchDisplay_MAS_Donations_Table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'MAS_Donations_Table',
        'label' => 'MAS Donations',
        'saved_search_id.name' => 'MAS_Donations',
        'type' => 'table',
        'settings' => [
          'description' => 'Every donation with its project and VC. Filter by type for the client or private log.',
          'sort' => [['receive_date', 'DESC']],
          'limit' => 50,
          'pager' => ['show_count' => TRUE, 'expose_limit' => TRUE],
          'placeholder' => 5,
          'tally' => ['label' => 'Total'],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'receive_date',
              'label' => 'Received',
              'sortable' => TRUE,
              'link' => [
                'path' => 'civicrm/contact/view/contribution?reset=1&action=view&id=[id]&cid=[contact_id]',
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
              ],
            ],
            ['type' => 'field', 'key' => 'contact_id.display_name', 'label' => 'Donor', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'financial_type_id:label', 'label' => 'Type', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'payment_instrument_id:label', 'label' => 'Method', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'contribution_status_id:label', 'label' => 'Status', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'total_amount', 'label' => 'Gross', 'sortable' => TRUE, 'tally' => ['fn' => 'SUM']],
            ['type' => 'field', 'key' => 'fee_amount', 'label' => 'Fee', 'sortable' => TRUE, 'tally' => ['fn' => 'SUM']],
            ['type' => 'field', 'key' => 'net_amount', 'label' => 'Net', 'sortable' => TRUE, 'tally' => ['fn' => 'SUM']],
            [
              'type' => 'field',
              'key' => "$p.Projects.MAS_Project_Case_Code",
              'label' => 'Project',
              'sortable' => TRUE,
              'title' => "[$p.subject]",
              'link' => [
                'path' => "civicrm/contact/view/case?action=view&reset=1&id=[$p]",
                'entity' => '',
                'action' => '',
                'join' => '',
                'target' => '_blank',
              ],
            ],
            ['type' => 'field', 'key' => "$p.status_id:label", 'label' => 'Project status', 'sortable' => TRUE],
            ['type' => 'field', 'key' => "$p.end_date", 'label' => 'Project closed', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Donation_Link.Linked_VC.display_name', 'label' => 'VC', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'source', 'label' => 'Source (legacy text)', 'sortable' => FALSE],
          ],
          'actions' => ['download'],
          'classes' => ['table', 'table-striped'],
        ],
      ],
      'match' => ['name', 'saved_search_id'],
    ],
  ],
  [
    'name' => 'Navigation_MAS_Donations',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => 'MAS Donations',
        'name' => 'mas_donations',
        'url' => 'civicrm/mas/donations',
        'parent_id.name' => 'Contributions',
        'permission' => ['access CiviContribute'],
        'is_active' => TRUE,
        'has_separator' => 1,
        'weight' => 90,
        'domain_id' => 'current_domain',
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
  [
    'name' => 'Navigation_MAS_Donations_Quarterly',
    'entity' => 'Navigation',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => 'MAS Donations — Quarterly report',
        'name' => 'mas_donations_quarterly',
        'url' => 'civicrm/mas/donations/quarterly',
        'parent_id.name' => 'Contributions',
        'permission' => ['access CiviContribute'],
        'is_active' => TRUE,
        'weight' => 91,
        'domain_id' => 'current_domain',
      ],
      'match' => ['name', 'domain_id'],
    ],
  ],
];
