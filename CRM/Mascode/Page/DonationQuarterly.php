<?php

declare(strict_types=1);

use Civi\Mascode\Service\DonationReport;

/**
 * Admin page for the Treasurer's quarterly donations report (donations
 * ticket DN-4). Route civicrm/mas/donations/quarterly; `&export=csv` downloads
 * the quarter table. All computation lives in DonationReport; this only
 * renders it.
 */
class CRM_Mascode_Page_DonationQuarterly extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(ts('MAS Donations — Quarterly report (by quarter the project closed)'));

    $from = CRM_Utils_Request::retrieveValue('from', 'String') ?: DonationReport::DEFAULT_FROM;
    $to = CRM_Utils_Request::retrieveValue('to', 'String') ?: date('Y-m-d');
    try {
      $report = DonationReport::quarterly($from, $to);
    }
    catch (\InvalidArgumentException $e) {
      CRM_Core_Session::setStatus($e->getMessage(), ts('Invalid date'), 'error');
      $report = DonationReport::quarterly();
    }

    if (CRM_Utils_Request::retrieveValue('export', 'String') === 'csv') {
      $this->exportCsv($report);
    }

    // Format here, not in Smarty: the template stays a plain loop.
    $pct = static fn($v) => $v === NULL ? '—' : number_format($v * 100, 1) . '%';
    $money = static fn($v) => $v === NULL ? '—' : Civi::format()->money((float) $v, 'CAD');
    foreach ($report['quarters'] as &$r) {
      foreach (['pct', 'rolling_pct'] as $k) {
        $r[$k . '_display'] = $pct($r[$k]);
      }
      foreach (['total', 'avg_per_donation', 'avg_per_completed', 'rolling_avg_per_completed'] as $k) {
        $r[$k . '_display'] = $money($r[$k]);
      }
    }
    unset($r);
    foreach (['open_project_donations', 'not_completed_donations'] as $list) {
      foreach ($report[$list] as &$r) {
        $r['net_display'] = $money($r['net']);
      }
      unset($r);
    }
    $report['unlinked_client_donations']['net_display'] = $money($report['unlinked_client_donations']['net']);

    $this->assign('report', $report);
    $this->assign('csvUrl', CRM_Utils_System::url('civicrm/mas/donations/quarterly',
      ['from' => $report['from'], 'to' => $report['to'], 'export' => 'csv'], FALSE, NULL, FALSE, FALSE, TRUE));
    $this->assign('linkedFrom', DonationReport::DEFAULT_FROM);
    return parent::run();
  }

  private function exportCsv(array $report): void {
    CRM_Utils_System::setHttpHeader('Content-Type', 'text/csv; charset=utf-8');
    CRM_Utils_System::setHttpHeader('Content-Disposition',
      'attachment; filename="mas-donations-quarterly-' . $report['from'] . '-to-' . $report['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Quarter', 'Completed', 'With donation', 'Donation %', 'Rolling 4Q %',
      'Total (net)', 'Average per donation', 'Average per completed project', 'Rolling 4Q average per completed']);
    $pct = static fn($v) => $v === NULL ? '' : round($v * 100, 1);
    $money = static fn($v) => $v === NULL ? '' : round((float) $v, 2);
    foreach ($report['quarters'] as $r) {
      fputcsv($out, [$r['quarter'], $r['completed'], $r['with_donation'], $pct($r['pct']), $pct($r['rolling_pct']),
        $money($r['total']), $money($r['avg_per_donation']), $money($r['avg_per_completed']), $money($r['rolling_avg_per_completed'])]);
    }
    fclose($out);
    CRM_Utils_System::civiExit();
  }

}
