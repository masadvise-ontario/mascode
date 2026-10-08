<?php

/**
 * R4 (docs/plans/donations-tickets.md): link historical donations to their
 * Project and VC from a reviewed map built offline from the Treasurer's
 * Quarterly Donations workbook. Custom fields only, through
 * DonationLinker::linkHistory(); no financial field is touched and the write
 * notifies no one.
 *
 * The map is a CSV with a header row: contribution_id, case_id, vc_id
 * (vc_id may be blank), and optionally batch. One row per contribution (one
 * cheque); case_id and vc_id may each hold several ids separated by ";"
 * (R10: one cheque, several projects). It holds ids only, and it stays
 * OUT of this public repo. Every row is re-checked against live data; a row
 * whose donor is not a client of the project, or whose VC is not one of its
 * coordinators, is refused. So is a contribution named twice, and a gift inside
 * DonationNotifier's window (its next ordinary save would email the new VC the
 * amount). Fill-empty only: a row with any conflicting value writes nothing, so
 * a second run changes nothing.
 *
 * Usage (dry run, then apply):
 *   cv scr scripts/link-donation-history.php --user=<admin> -- /path/map.csv [--batch=<name>]
 *   cv scr scripts/link-donation-history.php --user=<admin> -- /path/map.csv [--batch=<name>] --apply
 */

use Civi\Mascode\Service\DonationLinker;

$args = array_slice($argv ?? [], 1);
$apply = in_array('--apply', $args, true);
$batch = null;
$path = null;
foreach ($args as $a) {
    if (strpos($a, '--batch=') === 0) {
        $batch = substr($a, 8);
    }
    elseif ($a !== '--apply' && $a !== '--' && $path === null) {
        $path = $a;
    }
}
if (!$path || !is_readable($path)) {
    fwrite(STDERR, "Usage: link-donation-history.php -- <map.csv> [--batch=<name>] [--apply]\n");
    exit(1);
}

$fh = fopen($path, 'r');
$header = fgetcsv($fh);
if (!$header) {
    fwrite(STDERR, "$path is empty\n");
    exit(1);
}
// A spreadsheet's "CSV UTF-8" starts with a byte-order mark; header cells may be padded.
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
$header = array_map(static fn($h) => trim((string) $h), $header);
foreach (['contribution_id', 'case_id', 'vc_id'] as $col) {
    if (!in_array($col, $header, true)) {
        fwrite(STDERR, "$path has no '$col' column\n");
        exit(1);
    }
}
if ($batch !== null && !in_array('batch', $header, true)) {
    fwrite(STDERR, "$path has no 'batch' column, so --batch matches nothing\n");
    exit(1);
}
$map = [];
while (($line = fgetcsv($fh)) !== false) {
    if ($line === [null]) {
        continue;
    }
    // Extra cells (a trailing comma) are dropped; missing ones are blank.
    $row = array_combine($header, array_slice(array_pad($line, count($header), ''), 0, count($header)));
    if ($batch !== null && ($row['batch'] ?? '') !== $batch) {
        continue;
    }
    $map[] = $row;
}
fclose($fh);

if (!$map) {
    fwrite(STDERR, $batch !== null ? "No map rows in batch '$batch'\n" : "No map rows\n");
    exit(1);
}
$r = DonationLinker::linkHistory($map, !$apply);
printf("%s: %d map rows%s; project links %s %d, VC credits %s %d, unchanged %d, refused %d, conflicts %d\n",
    $apply ? 'APPLIED' : 'DRY RUN', $r['rows'], $batch !== null ? " (batch $batch)" : '',
    $apply ? 'written' : 'to write', $r['project_written'],
    $apply ? 'written' : 'to write', $r['vc_written'],
    $r['unchanged'], count($r['refused']), count($r['conflicts']));
foreach ($r['refused'] as $why) {
    echo "  refused $why\n";
}
foreach ($r['conflicts'] as $why) {
    echo "  conflict $why (left alone)\n";
}
