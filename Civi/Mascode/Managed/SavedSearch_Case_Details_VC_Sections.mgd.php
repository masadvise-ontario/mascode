<?php

declare(strict_types=1);

/**
 * VC portal case-detail page — content sections (activities timeline, case
 * roles/contacts, client-org context). Companion to SavedSearch_Case_Details_VC
 * (the case header / access gate).
 *
 * Spec: ~/gdrive-brianpkm/3-Resources/mascode-vc-portal-security-spec.md
 * Tests: tests/Security/CaseDetailAccessTest.php
 *
 * SECURITY (spec risk #2 — every sub-display is INDEPENDENTLY gated):
 * Each search re-applies the same entitlement predicate as the header gate —
 * base entity Case, LEFT join the coordinator RelationshipCache, and
 * WHERE ( status = 'Sent for Assignment' OR coordinator.near_contact_id = user_contact_id ).
 * The page supplies the case id as a runtime filter on `id`. An unentitled case
 * id therefore yields zero rows in EVERY section, not just the header — a forged
 * id cannot reach activities, roles, or client data via a direct display call.
 *
 * One-to-many note: the gate join can duplicate the Case row, so each section
 * groups by the CONTENT row's id (activity / relationship / client contact),
 * NOT the case id, to dedupe the gate join while preserving section rows.
 *
 * The shared gate (reused verbatim in each search below):
 *   join: LEFT RelationshipCache AS *_gate_rc
 *           ON case_id = id, near_relation:name = "Case Coordinator is", is_active = TRUE
 *   where: OR( status_id:name = 'Sent for Assignment', *_gate_rc.near_contact_id = user_contact_id )
 */

return [

  // ------------------------------------------------------------------ ACTIVITIES
  [
    'name' => 'SavedSearch_Case_Details_VC_Activities',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Case_Details_VC_Activities',
        'label' => 'VC Case Details — Activities',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Case_Activity_Activity_01.id',
            'Case_Activity_Activity_01.activity_date_time',
            'Case_Activity_Activity_01.activity_type_id:label',
            'Case_Activity_Activity_01.subject',
            'Case_Activity_Activity_01.status_id:label',
          ],
          'orderBy' => ['Case_Activity_Activity_01.activity_date_time' => 'DESC'],
          'where' => [
            ['OR', [
              ['status_id:name', '=', 'Sent for Assignment'],
              ['Case_Details_VC_Activities_gate_rc.near_contact_id', '=', 'user_contact_id'],
            ]],
          ],
          'groupBy' => ['Case_Activity_Activity_01.id'],
          'join' => [
            [
              'RelationshipCache AS Case_Details_VC_Activities_gate_rc',
              'LEFT',
              ['id', '=', 'Case_Details_VC_Activities_gate_rc.case_id'],
              ['Case_Details_VC_Activities_gate_rc.near_relation:name', '=', '"Case Coordinator is"'],
              ['Case_Details_VC_Activities_gate_rc.is_active', '=', TRUE],
            ],
            [
              'Activity AS Case_Activity_Activity_01',
              'LEFT',
              'CaseActivity',
              ['id', '=', 'Case_Activity_Activity_01.case_id'],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Case_Details_VC_Activities_SearchDisplay_Case_Details_VC_Activities_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Case_Details_VC_Activities_Table_1',
        'label' => 'VC Case Details Activities Table 1',
        'saved_search_id.name' => 'Case_Details_VC_Activities',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [['Case_Activity_Activity_01.activity_date_time', 'DESC']],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            ['type' => 'field', 'key' => 'Case_Activity_Activity_01.activity_date_time', 'label' => 'Date', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Case_Activity_Activity_01.activity_type_id:label', 'label' => 'Type', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Case_Activity_Activity_01.subject', 'label' => 'Subject', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Case_Activity_Activity_01.status_id:label', 'label' => 'Status', 'sortable' => TRUE],
          ],
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
          'actions_display_mode' => 'menu',
        ],
        'acl_bypass' => TRUE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],

  // ----------------------------------------------------------------------- ROLES
  [
    'name' => 'SavedSearch_Case_Details_VC_Roles',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Case_Details_VC_Roles',
        'label' => 'VC Case Details — Roles',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Case_Relationship_case_id_01.id',
            'Case_Relationship_case_id_01.relationship_type_id:label',
            'Case_Relationship_case_id_01.contact_id_a.sort_name',
            'Case_Relationship_case_id_01.contact_id_a.phone_primary.phone',
            'Case_Relationship_case_id_01.contact_id_a.email_primary.email',
            'Case_Relationship_case_id_01.is_active',
          ],
          'orderBy' => [],
          'where' => [
            ['OR', [
              ['status_id:name', '=', 'Sent for Assignment'],
              ['Case_Details_VC_Roles_gate_rc.near_contact_id', '=', 'user_contact_id'],
            ]],
            ['Case_Relationship_case_id_01.is_active', '=', TRUE],
          ],
          'groupBy' => ['Case_Relationship_case_id_01.id'],
          'join' => [
            [
              'RelationshipCache AS Case_Details_VC_Roles_gate_rc',
              'LEFT',
              ['id', '=', 'Case_Details_VC_Roles_gate_rc.case_id'],
              ['Case_Details_VC_Roles_gate_rc.near_relation:name', '=', '"Case Coordinator is"'],
              ['Case_Details_VC_Roles_gate_rc.is_active', '=', TRUE],
            ],
            [
              'Relationship AS Case_Relationship_case_id_01',
              'LEFT',
              ['id', '=', 'Case_Relationship_case_id_01.case_id'],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Case_Details_VC_Roles_SearchDisplay_Case_Details_VC_Roles_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Case_Details_VC_Roles_Table_1',
        'label' => 'VC Case Details Roles Table 1',
        'saved_search_id.name' => 'Case_Details_VC_Roles',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            ['type' => 'field', 'key' => 'Case_Relationship_case_id_01.relationship_type_id:label', 'label' => 'Role', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Case_Relationship_case_id_01.contact_id_a.sort_name', 'label' => 'Name', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Case_Relationship_case_id_01.contact_id_a.phone_primary.phone', 'label' => 'Phone', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Case_Relationship_case_id_01.contact_id_a.email_primary.email', 'label' => 'Email', 'sortable' => FALSE],
          ],
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
          'actions_display_mode' => 'menu',
        ],
        'acl_bypass' => TRUE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],

  // ---------------------------------------------------------------- CLIENT ORG
  [
    'name' => 'SavedSearch_Case_Details_VC_Client',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Case_Details_VC_Client',
        'label' => 'VC Case Details — Client Org',
        'api_entity' => 'Case',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'Case_CaseContact_Contact_01.id',
            'Case_CaseContact_Contact_01.display_name',
            'Case_CaseContact_Contact_01.contact_sub_type:label',
            'Case_CaseContact_Contact_01.phone_primary.phone',
            'Case_CaseContact_Contact_01.email_primary.email',
            'Case_Client_Website_01.url',
            'Case_CaseContact_Contact_01.address_primary.street_address',
            'Case_CaseContact_Contact_01.address_primary.city',
            'Case_CaseContact_Contact_01.address_primary.postal_code',
          ],
          'orderBy' => [],
          'where' => [
            ['OR', [
              ['status_id:name', '=', 'Sent for Assignment'],
              ['Case_Details_VC_Client_gate_rc.near_contact_id', '=', 'user_contact_id'],
            ]],
            ['Case_CaseContact_Contact_01.contact_type:name', '=', 'Organization'],
          ],
          'groupBy' => ['Case_CaseContact_Contact_01.id'],
          'join' => [
            [
              'RelationshipCache AS Case_Details_VC_Client_gate_rc',
              'LEFT',
              ['id', '=', 'Case_Details_VC_Client_gate_rc.case_id'],
              ['Case_Details_VC_Client_gate_rc.near_relation:name', '=', '"Case Coordinator is"'],
              ['Case_Details_VC_Client_gate_rc.is_active', '=', TRUE],
            ],
            [
              'Contact AS Case_CaseContact_Contact_01',
              'LEFT',
              'CaseContact',
              ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
            ],
            [
              'Website AS Case_Client_Website_01',
              'LEFT',
              ['Case_CaseContact_Contact_01.id', '=', 'Case_Client_Website_01.contact_id'],
            ],
          ],
          'having' => [],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Case_Details_VC_Client_SearchDisplay_Case_Details_VC_Client_Table_1',
    'entity' => 'SearchDisplay',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Case_Details_VC_Client_Table_1',
        'label' => 'VC Case Details Client Table 1',
        'saved_search_id.name' => 'Case_Details_VC_Client',
        'type' => 'table',
        'settings' => [
          'description' => NULL,
          'sort' => [],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.display_name', 'label' => 'Client Organization', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.contact_sub_type:label', 'label' => 'Type', 'sortable' => TRUE],
            ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.phone_primary.phone', 'label' => 'Phone', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.email_primary.email', 'label' => 'Email', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Case_Client_Website_01.url', 'label' => 'Website', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.address_primary.street_address', 'label' => 'Street', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.address_primary.city', 'label' => 'City', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Case_CaseContact_Contact_01.address_primary.postal_code', 'label' => 'Postal Code', 'sortable' => FALSE],
          ],
          'actions' => FALSE,
          'classes' => ['table', 'table-striped'],
          'actions_display_mode' => 'menu',
        ],
        'acl_bypass' => TRUE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],

];
