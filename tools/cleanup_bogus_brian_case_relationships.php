<?php

/**
 * One-time cleanup of bogus case relationships created by the 2024-12-30
 * legacy-conversion re-run, which wrote Brian Flett's contact into the
 * client (contact_id_b) slot of 174 case relationships (89 "Case Coordinator
 * is (MAS Rep)" + 85 "Case Client Rep is"). Every bogus row has a correct
 * twin on the same case (same type, same contact_id_a, real client org in
 * contact_id_b), so deletion loses nothing — and the script verifies that
 * twin exists per row before deleting; rows without a twin are skipped and
 * reported.
 *
 * Symptom this fixes: the "My Cases" SearchKit search showed ~124 cases
 * with Brian as Case Coordinator instead of his real ~8.
 *
 * Dry run (default, reports what would be deleted):
 *   cv scr <ext path>/tools/cleanup_bogus_brian_case_relationships.php
 *
 * Execute:
 *   EXECUTE=1 cv scr <ext path>/tools/cleanup_bogus_brian_case_relationships.php
 *
 * Idempotent: a second run finds 0 candidates.
 */

$execute = getenv('EXECUTE') === '1';

// Resolve Brian's contact by his legacy MAS-rep external id; refuse to run on
// ambiguity rather than guessing.
$brian = \Civi\Api4\Contact::get(FALSE)
  ->addSelect('id', 'display_name')
  ->addWhere('external_identifier', '=', '2000566')
  ->execute();
if ($brian->count() !== 1 || stripos($brian[0]['display_name'], 'Flett') === FALSE) {
  echo "ABORT: expected exactly one 'Flett' contact with external_identifier 2000566, got " . $brian->count() . "\n";
  exit(1);
}
$brianId = $brian[0]['id'];

// Resolve the two affected relationship types by label; refuse on mismatch.
$types = \Civi\Api4\RelationshipType::get(FALSE)
  ->addSelect('id', 'label_a_b')
  ->addWhere('label_a_b', 'IN', ['Case Coordinator is (MAS Rep)', 'Case Client Rep is'])
  ->execute();
if ($types->count() !== 2) {
  echo "ABORT: expected 2 relationship types, got " . $types->count() . "\n";
  exit(1);
}
$typeLabels = array_column((array) $types, 'label_a_b', 'id');

$candidates = \Civi\Api4\Relationship::get(FALSE)
  ->addSelect('id', 'case_id', 'contact_id_a', 'relationship_type_id')
  ->addWhere('contact_id_b', '=', $brianId)
  ->addWhere('case_id', 'IS NOT NULL')
  ->addWhere('is_active', '=', FALSE)
  ->addWhere('created_date', 'BETWEEN', ['2024-12-30 00:00:00', '2024-12-30 23:59:59'])
  ->addWhere('relationship_type_id', 'IN', array_keys($typeLabels))
  ->execute();

$deletable = [];
$skipped = [];
$byType = [];

foreach ($candidates as $rel) {
  $twin = \Civi\Api4\Relationship::get(FALSE)
    ->selectRowCount()
    ->addWhere('case_id', '=', $rel['case_id'])
    ->addWhere('relationship_type_id', '=', $rel['relationship_type_id'])
    ->addWhere('contact_id_a', '=', $rel['contact_id_a'])
    ->addWhere('contact_id_b', '!=', $brianId)
    ->execute();
  if ($twin->countMatched() > 0) {
    $deletable[] = $rel['id'];
    $label = $typeLabels[$rel['relationship_type_id']];
    $byType[$label] = ($byType[$label] ?? 0) + 1;
  }
  else {
    $skipped[] = $rel['id'];
  }
}

echo "Brian contact id: $brianId\n";
echo "Candidates (inactive, contact_id_b=Brian, created 2024-12-30): " . $candidates->count() . "\n";
foreach ($byType as $label => $n) {
  echo "  twin-verified '$label': $n\n";
}
echo "Twin-verified deletable: " . count($deletable) . "\n";
echo "Skipped (no correct twin found — left in place): " . count($skipped) . (count($skipped) ? ' ids: ' . implode(',', $skipped) : '') . "\n";

if (!$execute) {
  echo "DRY RUN — nothing deleted. Re-run with EXECUTE=1 to delete.\n";
  exit(0);
}

if ($deletable) {
  \Civi\Api4\Relationship::delete(FALSE)
    ->addWhere('id', 'IN', $deletable)
    ->execute();
}
echo "Deleted " . count($deletable) . " bogus relationships.\n";
