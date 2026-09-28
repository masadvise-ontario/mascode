<?php

declare(strict_types=1);

/**
 * VC scope sets — the ONE definition of what a volunteer consultant (VC) may
 * see, shared by the VC portal and the MAS CiviCRM MCP (VC access spec D7,
 * D13; mas-civicrm-mcp-server docs/plans/vc-access-tickets.md T3). Each search
 * returns ids only, for the contact in `user_contact_id`:
 *
 *   MAS_VC_Scope_Own_Cases   cases the VC coordinates: an ACTIVE "Case
 *                            Coordinator is" row, ANY end_date (D5 — past
 *                            clients count; is_active = 0 rows are corrected
 *                            mis-assignments and do not). Same rule as My_Cases.
 *   MAS_VC_Scope_Pool_Cases  cases Sent for Assignment (shared by every VC).
 *   MAS_VC_Scope_Orgs        client organisations of own ∪ pool cases.
 *   MAS_VC_Scope_Cases       every case of those organisations (D2).
 *   MAS_VC_Scope_Employees   individuals with an active "Employee of"
 *                            relationship to one of those organisations.
 *
 * SECURITY. These are access boundaries, not reports:
 *   - Callers run them with checkPermissions FALSE and rely on the
 *     `user_contact_id` predicate alone. It must resolve to the contact the
 *     caller authenticated in THIS request (the MCP's token, the portal's
 *     WordPress login) — never a contact id taken from input.
 *   - With NO contact (anonymous), Own_Cases is empty but the pool and every
 *     set reached from it are still returned. Callers must refuse to run
 *     these searches without an authenticated contact.
 *   - update = 'always' re-applies this file whenever the declaration changes
 *     (and in upgrade mode). A plain `cv flush` does NOT undo a Search Kit UI
 *     edit (core CRM_Core_ManagedEntities::optimizePlan), so callers should
 *     compare the stored api_params with this declaration and fail closed on
 *     drift; scripts/check-vc-scope-searches.php reports it.
 *   - Every Case and Contact alias names is_deleted = FALSE. APIv4 hides trash
 *     on the base entity only; joined cases and contacts need the guard.
 *   - A widened set is a data leak. Any change here needs a fresh-context
 *     adversarial review, and the MCP's live equality test (Orgs-derived
 *     cases / employees in PHP == these searches) must stay green.
 *
 * Performance (spike S1, masdemo 2026-09-28): own / pool / orgs < 60 ms; cases
 * and employees ~300-500 ms. So the MCP runs the first three and derives the
 * other two from Orgs in PHP; the portal runs all five.
 * Verify counts against a step-by-step reference: scripts/check-vc-scope-searches.php.
 */

// The caller's active coordinator row on case alias $c (LEFT: a pool case
// has none, and the where clause below admits it by status instead).
$mine = function (string $c): array {
  return ['RelationshipCache AS mine', 'LEFT',
    [$c . '.id', '=', 'mine.case_id'],
    ['mine.near_relation:name', '=', '"Case Coordinator is"'],
    ['mine.is_active', '=', TRUE],
  ];
};
// Case alias $c is in scope: the caller coordinates it, or it is in the pool.
$inScope = function (string $c): array {
  return ['OR', [
    ['mine.near_contact_id', '=', 'user_contact_id'],
    [$c . '.status_id:name', '=', 'Sent for Assignment'],
  ]];
};

$search = function (string $name, string $label, string $entity, array $params): array {
  return [
    'name' => 'SavedSearch_' . $name, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'always',
    'params' => ['version' => 4, 'values' => [
      'name' => $name, 'label' => $label, 'api_entity' => $entity,
      'description' => 'VC access boundary (mascode SavedSearch_MAS_VC_Scope_Sets.mgd.php). Do not edit in the UI — changes are overwritten.',
      'api_params' => array_merge(['version' => 4, 'select' => ['id'], 'orderBy' => [], 'having' => [], 'join' => []], $params),
    ], 'match' => ['name']],
  ];
};

return [
  $search('MAS_VC_Scope_Own_Cases', 'MAS VC Scope - Own cases', 'Case', [
    'join' => [[
      'RelationshipCache AS mine', 'INNER',
      ['id', '=', 'mine.case_id'],
      ['mine.near_relation:name', '=', '"Case Coordinator is"'],
      ['mine.is_active', '=', TRUE],
    ]],
    'where' => [['mine.near_contact_id', '=', 'user_contact_id'], ['is_deleted', '=', FALSE]],
    'groupBy' => ['id'],
  ]),

  $search('MAS_VC_Scope_Pool_Cases', 'MAS VC Scope - Pool cases', 'Case', [
    'where' => [['status_id:name', '=', 'Sent for Assignment'], ['is_deleted', '=', FALSE]],
    'groupBy' => [],
  ]),

  // Base = the organisation; c = its in-scope case.
  $search('MAS_VC_Scope_Orgs', 'MAS VC Scope - Organisations', 'Contact', [
    'join' => [
      ['Case AS c', 'INNER', 'CaseContact', ['id', '=', 'c.contact_id']],
      $mine('c'),
    ],
    'where' => [
      ['contact_type', '=', 'Organization'], ['is_deleted', '=', FALSE],
      ['c.is_deleted', '=', FALSE], $inScope('c'),
    ],
    'groupBy' => ['id'],
  ]),

  // Base = any case; org = its client organisation; c = an in-scope case of
  // that organisation.
  $search('MAS_VC_Scope_Cases', 'MAS VC Scope - Cases of the organisations', 'Case', [
    'join' => [
      ['Contact AS org', 'INNER', 'CaseContact', ['id', '=', 'org.case_id'],
        ['org.contact_type', '=', '"Organization"'], ['org.is_deleted', '=', FALSE]],
      ['Case AS c', 'INNER', 'CaseContact', ['org.id', '=', 'c.contact_id']],
      $mine('c'),
    ],
    'where' => [['is_deleted', '=', FALSE], ['c.is_deleted', '=', FALSE], $inScope('c')],
    'groupBy' => ['id'],
  ]),

  // Base = the individual; emp = their active Employee-of row; org = the
  // employer, joined explicitly so it is a live Organization (the same set as
  // MAS_VC_Scope_Orgs); c = an in-scope case of that organisation.
  $search('MAS_VC_Scope_Employees', 'MAS VC Scope - Employees of the organisations', 'Contact', [
    'join' => [
      ['RelationshipCache AS emp', 'INNER', ['id', '=', 'emp.near_contact_id'],
        ['emp.near_relation:name', '=', '"Employee of"'], ['emp.is_active', '=', TRUE]],
      ['Contact AS org', 'INNER', ['emp.far_contact_id', '=', 'org.id'],
        ['org.contact_type', '=', '"Organization"'], ['org.is_deleted', '=', FALSE]],
      ['Case AS c', 'INNER', 'CaseContact', ['org.id', '=', 'c.contact_id']],
      $mine('c'),
    ],
    'where' => [
      ['contact_type', '=', 'Individual'], ['is_deleted', '=', FALSE],
      ['c.is_deleted', '=', FALSE], $inScope('c'),
    ],
    'groupBy' => ['id'],
  ]),
];
