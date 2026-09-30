<?php

/**
 * Read-only check of the VC directory (Civi/Mascode/Managed/SavedSearch_MAS_VC_Directory.mgd.php
 * and ang/afsearchMASVcDirectory): the stored search and display must equal their managed
 * declaration, and the embedding Afform must hold no af-field, embed only the directory with no
 * attribute but its names (no `filters`), and have no route or placement. Prints
 * match / drift only — never contact data, so the output is safe to paste into a PR.
 *
 * Usage (masdemo or prod; it writes nothing):
 *   cv scr scripts/check-vc-directory.php --user=<staff login>
 *
 * Why: the display has acl_bypass, so what it selects is what every VC can read and filter on. A
 * Search Kit UI edit survives `cv flush` (core CRM_Core_ManagedEntities::optimizePlan), so a widened
 * select would otherwise go unnoticed. Exit code 1 on any drift.
 *
 * Comparison: string-keyed arrays are compared key-sorted; integer-keyed arrays are compared in
 * order, and one whose keys are out of list order is drift (APIv4 reads joins and clauses by
 * position — the blind spot mas-civicrm-mcp-server PR #10 found in check-vc-scope-searches.php).
 */

use Civi\Api4\Afform;
use Civi\Api4\SavedSearch;
use Civi\Api4\SearchDisplay;

$canon = function ($v) use (&$canon) {
  if (!is_array($v)) {
    return $v;
  }
  // Only string-keyed arrays are sorted. Integer keys out of list order are kept as they are, so
  // json_encode makes an object of them and the comparison fails.
  if ($v && !array_filter(array_keys($v), 'is_int')) {
    ksort($v);
  }
  return array_map($canon, $v);
};
$same = fn($a, $b): bool => json_encode($canon($a)) === json_encode($canon($b));

$failed = FALSE;
$report = function (string $what, bool $ok, string $bad = 'DRIFT from declaration') use (&$failed) {
  $failed = $failed || !$ok;
  printf("%-28s %s\n", $what, $ok ? 'matches declaration' : $bad);
};

$decls = include __DIR__ . '/../Civi/Mascode/Managed/SavedSearch_MAS_VC_Directory.mgd.php';
$searchName = NULL;
$displayName = NULL;
foreach ($decls as $decl) {
  $values = $decl['params']['values'];
  if ($decl['entity'] === 'SavedSearch') {
    $searchName = $values['name'];
    $stored = SavedSearch::get(FALSE)->addSelect('api_entity', 'api_params')
      ->addWhere('name', '=', $values['name'])->execute()->first();
    $report($values['name'], $stored && $stored['api_entity'] === $values['api_entity'] && $same($stored['api_params'], $values['api_params']),
      $stored ? 'DRIFT from declaration' : 'MISSING');
  }
  elseif ($decl['entity'] === 'SearchDisplay') {
    $displayName = $values['name'];
    $stored = SearchDisplay::get(FALSE)->addSelect('type', 'settings', 'acl_bypass')
      ->addWhere('name', '=', $values['name'])
      ->addWhere('saved_search_id.name', '=', $values['saved_search_id.name'])->execute()->first();
    $report($values['name'], $stored && $stored['type'] === $values['type'] && (bool) $stored['acl_bypass'] === (bool) $values['acl_bypass']
      && $same($stored['settings'], $values['settings']), $stored ? 'DRIFT from declaration' : 'MISSING');
  }
}

$afform = Afform::get(FALSE)->addSelect('layout', 'permission', 'is_public', 'type', 'server_route', 'placement')
  ->addWhere('name', '=', 'afsearchMASVcDirectory')->execute()->first();
if (!$afform) {
  $report('afsearchMASVcDirectory', FALSE, 'MISSING');
}
else {
  $fields = CRM_Utils_Array::findAll($afform['layout'], ['#tag' => 'af-field']);
  $displays = CRM_Utils_Array::findAll($afform['layout'], fn($el) => is_array($el) && isset($el['search-name']));
  // The display element may carry nothing but its two names: a `filters` attribute whose value is a
  // JS variable makes that key an allowed filter on ANY field, selected or not (AbstractRunAction::
  // getAfformDirectiveFilters) — the same probe an af-field opens (T28 review M2).
  $extra = $displays ? array_diff(array_keys($displays[0]), ['#tag', 'search-name', 'display-name']) : [];
  $only = count($displays) === 1 && !$extra && $displays[0]['search-name'] === $searchName && ($displays[0]['display-name'] ?? NULL) === $displayName;
  $shape = $afform['type'] === 'search' && empty($afform['server_route']) && empty($afform['placement']);
  $report('afsearchMASVcDirectory', !$fields && $only && $shape && !$afform['is_public'] && $afform['permission'] === ['access CiviCRM'],
    sprintf('UNEXPECTED (%d af-field, %d displays, extra attributes %s, route/placement %s, public %s)', count($fields), count($displays),
      $extra ? implode(',', $extra) : 'none', $shape ? 'none' : 'SET', $afform['is_public'] ? 'yes' : 'no'));
}

exit($failed ? 1 : 0);
