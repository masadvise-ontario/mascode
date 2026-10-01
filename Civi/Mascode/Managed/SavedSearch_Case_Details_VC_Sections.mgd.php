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
 * Each search carries the same placeholder as the header gate,
 * VcPortalScope::clause('cases'), which VcPortalScopeSubscriber fills with the
 * logged-in contact's S_cases (T12, VC access spec D2, D13, D23 — the same set
 * the MCP uses). The page supplies the case id as a runtime filter on `id`. An
 * unentitled case id therefore yields zero rows in EVERY section, not just the
 * header, and an unfilled placeholder matches nothing.
 *
 * CONTACT DETAILS (D13, D14, D15). Roles and Client show a phone, email,
 * address or website only for a contact in the VC's S_contacts — in-scope
 * organisations, their employees (never a VC or a MAS employee, D25), the
 * eligible individual clients of the VC's own cases, and the VC themself. That
 * is the MCP's Email / Phone / Address rule. Everyone else on the case — other
 * VCs, MAS staff, a pool case's individual client — appears by name only. The
 * details come through a second join (`*_shown`) whose condition carries the
 * `contacts` placeholder, so a contact outside the set joins nothing and its
 * columns are NULL; the name comes from the relationship itself. The details
 * must never be selected through the unconditioned join: on an acl_bypass
 * display every selected field reaches the browser, shown or not.
 *
 * ACTIVITY TEXT (D37). The timeline selects the activity subject, never
 * `details`: activity details carry working form-login links for their
 * recipient, and on the portal this column choice is what keeps them from
 * VCs. scripts/check-vc-portal.php fails if any portal search selects it.
 *
 * One-to-many note: each section groups by the CONTENT row's id (activity /
 * relationship / client contact), NOT the case id, so the joins cannot
 * duplicate section rows.
 *
 * update=always plus the subscriber's drift check: a stored copy that differs
 * from this file is refused, not run. Do not edit these in the Search Kit UI.
 */

use Civi\Mascode\Security\VcPortalScope;

return [

  // ------------------------------------------------------------------ ACTIVITIES
  [
    'name' => 'SavedSearch_Case_Details_VC_Activities',
    'entity' => 'SavedSearch',
    'cleanup' => 'unused',
    'update' => 'always',
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
            VcPortalScope::clause('cases'),
          ],
          'groupBy' => ['Case_Activity_Activity_01.id'],
          'join' => [
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
    'update' => 'always',
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
          'actions' => ['download'],
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
    'update' => 'always',
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
            'Roles_shown.phone_primary.phone',
            'Roles_shown.email_primary.email',
            'Case_Relationship_case_id_01.is_active',
          ],
          'orderBy' => [],
          'where' => [
            VcPortalScope::clause('cases'),
            ['Case_Relationship_case_id_01.is_active', '=', TRUE],
          ],
          'groupBy' => ['Case_Relationship_case_id_01.id'],
          'join' => [
            [
              'Relationship AS Case_Relationship_case_id_01',
              'LEFT',
              ['id', '=', 'Case_Relationship_case_id_01.case_id'],
            ],
            // Contact details only for a contact in S_contacts (D14): see the
            // CONTACT DETAILS note above.
            [
              'Contact AS Roles_shown',
              'LEFT',
              ['Case_Relationship_case_id_01.contact_id_a', '=', 'Roles_shown.id'],
              VcPortalScope::clause('contacts', 'Roles_shown.id'),
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
    'update' => 'always',
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
            ['type' => 'field', 'key' => 'Roles_shown.phone_primary.phone', 'label' => 'Phone', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Roles_shown.email_primary.email', 'label' => 'Email', 'sortable' => FALSE],
          ],
          'actions' => ['download'],
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
    'update' => 'always',
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
            'Client_shown.phone_primary.phone',
            'Client_shown.email_primary.email',
            'Case_Client_Website_01.url',
            'Client_shown.address_primary.street_address',
            'Client_shown.address_primary.city',
            'Client_shown.address_primary.postal_code',
          ],
          'orderBy' => [],
          'where' => [
            VcPortalScope::clause('cases'),
            ['Case_CaseContact_Contact_01.contact_type:name', '=', 'Organization'],
          ],
          'groupBy' => ['Case_CaseContact_Contact_01.id'],
          'join' => [
            [
              'Contact AS Case_CaseContact_Contact_01',
              'LEFT',
              'CaseContact',
              ['id', '=', 'Case_CaseContact_Contact_01.case_id'],
            ],
            // Contact details only for a contact in S_contacts (D14): see the
            // CONTACT DETAILS note above. A pooled internal request's client
            // (the domain organisation, D25) shows by name only.
            [
              'Contact AS Client_shown',
              'LEFT',
              ['Case_CaseContact_Contact_01.id', '=', 'Client_shown.id'],
              VcPortalScope::clause('contacts', 'Client_shown.id'),
            ],
            [
              'Website AS Case_Client_Website_01',
              'LEFT',
              ['Client_shown.id', '=', 'Case_Client_Website_01.contact_id'],
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
    'update' => 'always',
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
            ['type' => 'field', 'key' => 'Client_shown.phone_primary.phone', 'label' => 'Phone', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Client_shown.email_primary.email', 'label' => 'Email', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Case_Client_Website_01.url', 'label' => 'Website', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Client_shown.address_primary.street_address', 'label' => 'Street', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Client_shown.address_primary.city', 'label' => 'City', 'sortable' => FALSE],
            ['type' => 'field', 'key' => 'Client_shown.address_primary.postal_code', 'label' => 'Postal Code', 'sortable' => FALSE],
          ],
          'actions' => ['download'],
          'classes' => ['table', 'table-striped'],
          'actions_display_mode' => 'menu',
        ],
        'acl_bypass' => TRUE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],

];
