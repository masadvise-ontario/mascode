<?php

/**
 * Read-only check of the VC scope searches (Civi/Mascode/Managed/
 * SavedSearch_MAS_VC_Scope_Sets.mgd.php) against a step-by-step APIv4
 * reference, for one or more contacts. Prints COUNTS, timings and
 * match/mismatch only — never names, ids or any other contact data, so the
 * output is safe to paste into a PR.
 *
 * Usage (masdemo or prod; it writes nothing):
 *   CHECK_CONTACT_IDS=3,1234 cv scr scripts/check-vc-scope-searches.php --user=<staff login>
 *
 * First checks each stored search still equals its managed declaration (a
 * Search Kit UI edit is not undone by `cv flush`). Then each listed contact is
 * made the session contact in turn, so the searches' `user_contact_id`
 * resolves to it exactly as it would for that VC signed in.
 * Exit code 1 on any drift or mismatch.
 */

use Civi\Api4\CaseContact;
use Civi\Api4\CiviCase;
use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Api4\SavedSearch;

$ids = array_filter(array_map('intval', explode(',', (string) getenv('CHECK_CONTACT_IDS'))));
if (!$ids) {
  fwrite(STDERR, "Set CHECK_CONTACT_IDS to a comma-separated list of contact ids.\n");
  exit(2);
}

$column = fn($rows, string $key): array => array_values(array_unique(array_map('intval', array_column((array) $rows, $key))));

/**
 * Step-by-step reference, one plain APIv4 call per hop. Deliberately reads
 * Relationship by type name_a_b and contact_id_a (not RelationshipCache), so it
 * does not share the searches' near/far assumptions.
 */
$reference = function (int $cid) use ($column): array {
  $coord = $column(Relationship::get(FALSE)->addSelect('case_id')
    ->addWhere('contact_id_a', '=', $cid)
    ->addWhere('relationship_type_id.name_a_b', '=', 'Case Coordinator is')
    ->addWhere('is_active', '=', TRUE)
    ->addWhere('case_id', 'IS NOT NULL')->execute()->getArrayCopy(), 'case_id');
  $own = $coord ? $column(CiviCase::get(FALSE)->addSelect('id')->addWhere('id', 'IN', $coord)
    ->addWhere('is_deleted', '=', FALSE)->execute()->getArrayCopy(), 'id') : [];
  $pool = $column(CiviCase::get(FALSE)->addSelect('id')
    ->addWhere('status_id:name', '=', 'Sent for Assignment')
    ->addWhere('is_deleted', '=', FALSE)->execute()->getArrayCopy(), 'id');
  $seed = array_values(array_unique(array_merge($own, $pool)));
  $clients = $seed ? $column(CaseContact::get(FALSE)->addSelect('contact_id')
    ->addWhere('case_id', 'IN', $seed)->execute()->getArrayCopy(), 'contact_id') : [];
  $orgs = $clients ? $column(Contact::get(FALSE)->addSelect('id')->addWhere('id', 'IN', $clients)
    ->addWhere('contact_type', '=', 'Organization')
    ->addWhere('is_deleted', '=', FALSE)->execute()->getArrayCopy(), 'id') : [];
  $orgCases = $orgs ? $column(CaseContact::get(FALSE)->addSelect('case_id')
    ->addWhere('contact_id', 'IN', $orgs)->execute()->getArrayCopy(), 'case_id') : [];
  $cases = $orgCases ? $column(CiviCase::get(FALSE)->addSelect('id')->addWhere('id', 'IN', $orgCases)
    ->addWhere('is_deleted', '=', FALSE)->execute()->getArrayCopy(), 'id') : [];
  $empIds = $orgs ? $column(Relationship::get(FALSE)->addSelect('contact_id_a')
    ->addWhere('contact_id_b', 'IN', $orgs)
    ->addWhere('relationship_type_id.name_a_b', '=', 'Employee of')
    ->addWhere('is_active', '=', TRUE)->execute()->getArrayCopy(), 'contact_id_a') : [];
  $employees = $empIds ? $column(Contact::get(FALSE)->addSelect('id')->addWhere('id', 'IN', $empIds)
    ->addWhere('contact_type', '=', 'Individual')
    ->addWhere('is_deleted', '=', FALSE)->execute()->getArrayCopy(), 'id') : [];
  return ['Own_Cases' => $own, 'Pool_Cases' => $pool, 'Orgs' => $orgs, 'Cases' => $cases, 'Employees' => $employees];
};

/** Run a stored scope search from its api_params, as its callers will. */
$runSearch = function (string $set) use ($column): array {
  $search = SavedSearch::get(FALSE)->addSelect('api_entity', 'api_params')
    ->addWhere('name', '=', 'MAS_VC_Scope_' . $set)->execute()->first();
  if (!$search) {
    return [NULL, 0];
  }
  $params = $search['api_params'];
  $params['checkPermissions'] = FALSE;
  $start = microtime(TRUE);
  $rows = civicrm_api4($search['api_entity'], 'get', $params);
  return [$column($rows->getArrayCopy(), 'id'), (int) round((microtime(TRUE) - $start) * 1000)];
};

// Drift: the stored search must equal the managed declaration (a Search Kit
// UI edit survives `cv flush`; see the declaration file's docblock).
$canon = function ($v) use (&$canon) {
  if (!is_array($v)) {
    return $v;
  }
  if (array_keys($v) !== range(0, count($v) - 1)) {
    ksort($v);
  }
  return array_map($canon, $v);
};
$failed = FALSE;
foreach (include __DIR__ . '/../Civi/Mascode/Managed/SavedSearch_MAS_VC_Scope_Sets.mgd.php' as $decl) {
  $values = $decl['params']['values'];
  $stored = SavedSearch::get(FALSE)->addSelect('api_entity', 'api_params')
    ->addWhere('name', '=', $values['name'])->execute()->first();
  $same = $stored && $stored['api_entity'] === $values['api_entity']
    && json_encode($canon($stored['api_params'])) === json_encode($canon($values['api_params']));
  $failed = $failed || !$same;
  printf("%-24s %s\n", $values['name'], $stored ? ($same ? 'matches declaration' : 'DRIFT from declaration') : 'MISSING');
}

$session = CRM_Core_Session::singleton();
$original = $session->get('userID');
try {
  foreach ($ids as $n => $cid) {
    $session->set('userID', $cid);
    if ((int) CRM_Core_Session::getLoggedInContactID() !== $cid) {
      throw new RuntimeException('user_contact_id did not resolve to the contact under test');
    }
    $ref = $reference($cid);
    printf("contact #%d\n", $n + 1);
    foreach ($ref as $set => $expected) {
      [$actual, $ms] = $runSearch($set);
      if ($actual === NULL) {
        $failed = TRUE;
        printf("  %-11s MISSING\n", $set);
        continue;
      }
      sort($expected);
      sort($actual);
      $ok = $expected === $actual;
      $failed = $failed || !$ok;
      printf("  %-11s search %5d  reference %5d  %4d ms  %s\n", $set, count($actual), count($expected), $ms,
        $ok ? 'MATCH' : sprintf('MISMATCH (%d only in search, %d only in reference)',
          count(array_diff($actual, $expected)), count(array_diff($expected, $actual))));
    }
  }
}
finally {
  $session->set('userID', $original);
}
exit($failed ? 1 : 0);
