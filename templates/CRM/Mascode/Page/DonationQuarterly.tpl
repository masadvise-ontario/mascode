{* Treasurer's quarterly donations report — donations ticket DN-4. Data: Civi\Mascode\Service\DonationReport. *}
<div class="crm-container mas-donation-quarterly">
  <form method="get" class="crm-form-block" style="margin-bottom:1em">
    <input type="hidden" name="page" value="CiviCRM">
    <input type="hidden" name="q" value="civicrm/mas/donations/quarterly">
    <label>Projects closed from <input type="date" name="from" value="{$report.from|escape}"></label>
    <label>to <input type="date" name="to" value="{$report.to|escape}"></label>
    <button type="submit" class="btn btn-primary">Show</button>
    <a href="{$csvUrl|escape}" class="btn btn-secondary">Download CSV</a>
  </form>

  <p>Donations are counted against the quarter the <strong>project closed</strong>, not the quarter the money arrived, so this report will not reconcile with the bank or the monthly logs. Amounts are <strong>net</strong> of fees. The most recent quarter always looks low, because donations often arrive after the project closes.</p>

  <table class="display">
    <thead>
      <tr>
        <th>Quarter</th><th>Completed</th><th>With donation</th><th>Donation %</th><th>Rolling 4Q %</th>
        <th>Total (net)</th><th>Avg per donation</th><th>Avg per completed project</th><th>Rolling 4Q avg per completed</th>
      </tr>
    </thead>
    <tbody>
    {foreach from=$report.quarters item=r}
      <tr class="{cycle values="odd-row,even-row"}">
        <td>{$r.quarter}</td><td>{$r.completed}</td><td>{$r.with_donation}</td><td>{$r.pct_display}</td><td>{$r.rolling_pct_display}</td>
        <td>{$r.total_display}</td><td>{$r.avg_per_donation_display}</td><td>{$r.avg_per_completed_display}</td><td>{$r.rolling_avg_per_completed_display}</td>
      </tr>
    {/foreach}
    </tbody>
  </table>

  <h3>Donations on projects not yet closed</h3>
  {if $report.open_project_donations}
    <ul>{foreach from=$report.open_project_donations item=d}<li>{$d.project|escape} ({$d.status|escape}): {$d.net_display}, first received {$d.first_received}</li>{/foreach}</ul>
  {else}<p>None.</p>{/if}

  <h3>Donations on projects closed but not completed (or cancelled)</h3>
  {if $report.not_completed_donations}
    <ul>{foreach from=$report.not_completed_donations item=d}<li>{$d.project|escape}: {$d.net_display}, first received {$d.first_received}</li>{/foreach}</ul>
  {else}<p>None.</p>{/if}

  <h3>Completed projects with no close date</h3>
  {if $report.completed_without_close_date}
    <p>These cannot be placed in a quarter, so they are not counted above. Set the case's end date to include them.</p>
    <ul>{foreach from=$report.completed_without_close_date item=p}<li>{$p|escape}</li>{/foreach}</ul>
  {else}<p>None.</p>{/if}

  <h3>Client donations with no project linked</h3>
  <p>{$report.unlinked_client_donations.count} donation(s), {$report.unlinked_client_donations.net_display} net, received in this range. Set <em>Project</em> on each contribution so it counts above.</p>

  <p class="description">Project links exist only from {$linkedFrom} (backfilled from the project codes typed into Source). Earlier quarters understate donations until the Treasurer's historical workbook is loaded.</p>
</div>
