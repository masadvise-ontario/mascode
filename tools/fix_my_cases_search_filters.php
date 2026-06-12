<?php

/**
 * Add missing filters to the "My Cases" SearchKit search (name: My_Cases).
 *
 * The search queries RelationshipCache for "Case Coordinator is (MAS Rep)"
 * relationships near the logged-in user but filtered on neither direction
 * nor is_active, so it counted inactive rows and rows where the user sits on
 * the client side — Brian showed ~124 cases instead of his real 8.
 *
 * Adds (if absent):
 *   near_relation = 'Case Coordinator is'  — user on the coordinator side
 *   is_active = TRUE                       — current assignments only
 *
 * Idempotent. Run with:
 *   cv scr <ext path>/tools/fix_my_cases_search_filters.php
 */

$search = \Civi\Api4\SavedSearch::get(FALSE)
  ->addSelect('id', 'api_params')
  ->addWhere('name', '=', 'My_Cases')
  ->execute();
if ($search->count() !== 1) {
  echo "ABORT: expected exactly one SavedSearch named My_Cases, got " . $search->count() . "\n";
  exit(1);
}

$params = $search[0]['api_params'];
$added = [];
foreach ([['near_relation', '=', 'Case Coordinator is'], ['is_active', '=', TRUE]] as $clause) {
  $present = FALSE;
  foreach ($params['where'] as $existing) {
    if ($existing[0] === $clause[0]) {
      $present = TRUE;
      break;
    }
  }
  if (!$present) {
    $params['where'][] = $clause;
    $added[] = $clause[0];
  }
}

if (!$added) {
  echo "My_Cases (id {$search[0]['id']}): filters already present, nothing to do.\n";
  exit(0);
}

\Civi\Api4\SavedSearch::update(FALSE)
  ->addWhere('id', '=', $search[0]['id'])
  ->addValue('api_params', $params)
  ->execute();

echo "My_Cases (id {$search[0]['id']}): added filter(s) " . implode(', ', $added) . "\n";
