<?php

declare(strict_types=1);

/**
 * VC scope sets — the ONE definition of what a volunteer consultant (VC) may
 * see, shared by the VC portal and the MAS CiviCRM MCP (VC access spec D7,
 * D13; mas-civicrm-mcp-server docs/plans/vc-access-tickets.md T3, amended by
 * T32 for D23 and D25). Each search returns case or contact ids for the
 * contact in `user_contact_id`:
 *
 *   MAS_VC_Scope_Own_Cases   cases the VC coordinates: an ACTIVE "Case
 *                            Coordinator is" row, ANY end_date (D5 — past
 *                            clients count; is_active = 0 rows are corrected
 *                            mis-assignments and do not). Same rule as My_Cases.
 *   MAS_VC_Scope_Pool_Cases  cases Sent for Assignment (shared by every VC).
 *   MAS_VC_Scope_Orgs        client organisations of own cases, and of pool
 *                            cases EXCEPT the domain organisation (D25): the
 *                            site's own organisation enters a VC's scope only
 *                            through a case they coordinated, so a pooled
 *                            internal request adds only itself.
 *   MAS_VC_Scope_Cases       every case with an ORGANISATION client in Orgs
 *                            (D2), plus the own and pool cases themselves
 *                            (D23). A case filed only under an individual —
 *                            even an employee of an in-scope organisation —
 *                            is in scope only as an own or pool case.
 *   MAS_VC_Scope_Employees   individuals with an active "Employee of"
 *                            relationship to one of those organisations, never
 *                            a VC (sub-type MAS_Rep, any status) and never
 *                            anyone with an active "Employee of" to a domain
 *                            organisation (D25) — those are reached only
 *                            through the VC directory. Returns a second
 *                            column, domain_employer (always 0), which carries
 *                            that rule through `having`.
 *
 * Cases and Employees repeat the Orgs derivation inline (org -> c -> mine),
 * so the domain-organisation rule is applied in each of them, not only in
 * Orgs. "Domain organisation" is any Domain row's contact_id, matched by a
 * join — never an id written here.
 *
 * SECURITY. These are access boundaries, not reports:
 *   - Callers run them with checkPermissions FALSE and rely on the
 *     `user_contact_id` predicate alone. It must resolve to the contact the
 *     caller authenticated in THIS request (the MCP's token, the portal's
 *     WordPress login) — never a contact id taken from input.
 *   - Run each search with its stored select, having and joins intact.
 *     Employees' D25 rule lives in `having` on its domain_employer column:
 *     dropping `having` alone does NOT fail — it silently re-admits domain
 *     employees. Two other changes do fail closed: dropping the column
 *     throws, and with checkPermissions TRUE a VC cannot join Domain, so the
 *     three amended searches throw `Invalid field 'dom.id'`. So the portal
 *     needs acl_bypass rather than the VC's own permissions.
 *   - With NO contact (anonymous), Own_Cases is empty but the pool and every
 *     set reached from it are still returned. Callers must refuse to run
 *     these searches without an authenticated contact.
 *   - update = 'always' re-applies this file whenever the declaration changes
 *     (and in upgrade mode). A plain `cv flush` does NOT undo a Search Kit UI
 *     edit (core CRM_Core_ManagedEntities::optimizePlan), so callers should
 *     compare the stored api_params with this declaration and fail closed on
 *     drift; scripts/check-vc-scope-searches.php reports it.
 *   - Relationships are matched by name AND orientation a_b (the coordinator /
 *     employee is contact_a), so a second type whose REVERSE name collides
 *     with "Case Coordinator is" or "Employee of" cannot widen a set.
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
    ['mine.orientation', '=', '"a_b"'],
    ['mine.is_active', '=', TRUE],
  ];
};
// $alias is set when organisation $org is a domain organisation (the site's
// own organisation), NULL otherwise.
$domain = function (string $org, string $alias): array {
  return ['Domain AS ' . $alias, 'LEFT', [$org, '=', $alias . '.contact_id']];
};
// Case alias $c brings its organisation into scope: the caller coordinates
// it, or it is in the pool and the organisation is not a domain organisation
// (D25; $dom from $domain() on that organisation).
$inScope = function (string $c, string $dom): array {
  return ['OR', [
    ['mine.near_contact_id', '=', 'user_contact_id'],
    ['AND', [[$c . '.status_id:name', '=', 'Sent for Assignment'], [$dom . '.id', 'IS NULL']]],
  ]];
};

$search = function (string $name, string $label, string $entity, array $params): array {
  return [
    'name' => 'SavedSearch_' . $name, 'entity' => 'SavedSearch',
    'cleanup' => 'unused', 'update' => 'always',
    'params' => ['version' => 4, 'values' => [
      'name' => $name, 'label' => $label, 'api_entity' => $entity,
      'description' => 'VC access boundary (mascode SavedSearch_MAS_VC_Scope_Sets.mgd.php). Do not edit in the UI — an edit is not reverted until the next release that changes this file; scripts/check-vc-scope-searches.php reports the drift.',
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
      ['mine.orientation', '=', '"a_b"'],
      ['mine.is_active', '=', TRUE],
    ]],
    'where' => [['mine.near_contact_id', '=', 'user_contact_id'], ['is_deleted', '=', FALSE]],
    'groupBy' => ['id'],
  ]),

  $search('MAS_VC_Scope_Pool_Cases', 'MAS VC Scope - Pool cases', 'Case', [
    'where' => [['status_id:name', '=', 'Sent for Assignment'], ['is_deleted', '=', FALSE]],
    'groupBy' => [],
  ]),

  // Base = the organisation; c = its in-scope case; dom = set when the
  // organisation is a domain organisation.
  $search('MAS_VC_Scope_Orgs', 'MAS VC Scope - Organisations', 'Contact', [
    'join' => [
      ['Case AS c', 'INNER', 'CaseContact', ['id', '=', 'c.contact_id']],
      $mine('c'),
      $domain('id', 'dom'),
    ],
    'where' => [
      ['contact_type', '=', 'Organization'], ['is_deleted', '=', FALSE],
      ['c.is_deleted', '=', FALSE], $inScope('c', 'dom'),
    ],
    'groupBy' => ['id'],
  ]),

  // Base = any case. own = the caller's coordinator row on it; org = its
  // client organisation; c = an in-scope case of that organisation (the Orgs
  // rule, inline); dom = set when org is a domain organisation. All LEFT: a
  // case is in scope by any one of the three branches of the where clause.
  $search('MAS_VC_Scope_Cases', 'MAS VC Scope - Cases of the organisations', 'Case', [
    'join' => [
      ['RelationshipCache AS own', 'LEFT',
        ['id', '=', 'own.case_id'],
        ['own.near_relation:name', '=', '"Case Coordinator is"'],
        ['own.orientation', '=', '"a_b"'],
        ['own.is_active', '=', TRUE]],
      ['Contact AS org', 'LEFT', 'CaseContact', ['id', '=', 'org.case_id'],
        ['org.contact_type', '=', '"Organization"'], ['org.is_deleted', '=', FALSE]],
      ['Case AS c', 'LEFT', 'CaseContact', ['org.id', '=', 'c.contact_id']],
      $mine('c'),
      $domain('org.id', 'dom'),
    ],
    'where' => [['is_deleted', '=', FALSE], ['OR', [
      ['own.near_contact_id', '=', 'user_contact_id'],
      ['status_id:name', '=', 'Sent for Assignment'],
      ['AND', [['c.is_deleted', '=', FALSE], $inScope('c', 'dom')]],
    ]]],
    'groupBy' => ['id'],
  ]),

  // Base = the individual; emp = their active Employee-of row; org = the
  // employer, joined explicitly so it is a live Organization (the same set as
  // MAS_VC_Scope_Orgs); c = an in-scope case of that organisation; dom = set
  // when org is a domain organisation. anyEmp / anyDom = ANY active
  // Employee-of row of the individual whose employer is a domain
  // organisation: `having` drops the individual if one exists (D25). APIv4
  // has no NOT EXISTS; a where on anyDom.id IS NULL would keep the rows of
  // the individual's other employers.
  $search('MAS_VC_Scope_Employees', 'MAS VC Scope - Employees of the organisations', 'Contact', [
    'select' => ['id', 'COUNT(anyDom.id) AS domain_employer'],
    'join' => [
      ['RelationshipCache AS emp', 'INNER', ['id', '=', 'emp.near_contact_id'],
        ['emp.near_relation:name', '=', '"Employee of"'],
        ['emp.orientation', '=', '"a_b"'], ['emp.is_active', '=', TRUE]],
      ['Contact AS org', 'INNER', ['emp.far_contact_id', '=', 'org.id'],
        ['org.contact_type', '=', '"Organization"'], ['org.is_deleted', '=', FALSE]],
      ['Case AS c', 'INNER', 'CaseContact', ['org.id', '=', 'c.contact_id']],
      $mine('c'),
      $domain('org.id', 'dom'),
      ['RelationshipCache AS anyEmp', 'LEFT', ['id', '=', 'anyEmp.near_contact_id'],
        ['anyEmp.near_relation:name', '=', '"Employee of"'],
        ['anyEmp.orientation', '=', '"a_b"'], ['anyEmp.is_active', '=', TRUE]],
      $domain('anyEmp.far_contact_id', 'anyDom'),
    ],
    'where' => [
      ['contact_type', '=', 'Individual'], ['is_deleted', '=', FALSE],
      ['contact_sub_type:name', 'NOT CONTAINS', 'MAS_Rep'],
      ['c.is_deleted', '=', FALSE], $inScope('c', 'dom'),
    ],
    'groupBy' => ['id'],
    'having' => [['domain_employer', '=', 0]],
  ]),
];
