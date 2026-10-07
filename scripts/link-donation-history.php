<?php

/**
 * R4 (docs/plans/donations-tickets.md): link historical donations to their
 * Project and VC from a reviewed map built offline from the Treasurer's
 * Quarterly Donations workbook. Custom fields only, through
 * DonationLinker::linkHistory(); no financial field is touched and nothing
 * is notified.
 *
 * The map is a CSV with a header row: contribution_id, case_id, vc_id
 * (vc_id may be blank), and optionally batch. It holds ids only, and it stays
 * OUT of this public repo. Every row is re-checked against live data; a row
 * whose donor is not a client of the project, or whose VC is not one of its
 * coordinators, is refused. Fill-empty only, so a second run changes nothing.
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
$map = [];
while (($line = fgetcsv($fh)) !== false) {
    if ($line === [null]) {
        continue;
    }
    $row = array_combine($header, array_pad($line, count($header), ''));
    if ($batch !== null && ($row['batch'] ?? '') !== $batch) {
        continue;
    }
    $map[] = $row;
}
fclose($fh);

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
