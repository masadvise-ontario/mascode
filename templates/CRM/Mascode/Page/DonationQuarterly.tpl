{* Treasurer's quarterly donations report — donations ticket DN-4. Data: Civi\Mascode\Service\DonationReport. *}
<div class="crm-container mas-donation-quarterly">
  <form method="get" class="crm-form-block" style="margin-bottom:1em">
    <input type="hidden" name="page" value="CiviCRM">
    <input type="hidden" name="q" value="civicrm/mas/donations/quarterly">
    <label>Projects completed from <input type="date" name="from" value="{$report.from|escape}"></label>
    <label>to <input type="date" name="to" value="{$report.to|escape}"></label>
    <button type="submit" class="btn btn-primary">Show</button>
    <a href="{$csvUrl|escape}" class="btn btn-secondary">Download CSV</a>
  </form>

  <p>Donations are counted against the quarter the <strong>project was completed</strong>, not the quarter the money arrived, so this report will not reconcile with the bank or the monthly logs. A project counts as completed from the day it entered <em>Awaiting VC Project Completion Form</em>, <em>Awaiting Client Project Signoff Form</em> or <em>Completed</em>, whichever came first. Amounts are <strong>net</strong> of fees.</p>
  <p>The latest quarter is marked <strong>provisional</strong>: donations often arrive after the project is completed, so its numbers are still growing. <strong>CAF</strong> (Community Action Foundation) gifts have no project: they are counted in the quarter they were received, in the total only, and not as donations.</p>

  <table class="display">
    <thead>
      <tr>
        <th>Quarter</th><th>Completed</th><th>With donation</th><th>Donation %</th><th>Rolling 4Q %</th>
        <th>Donations (net)</th><th>CAF (net)</th><th>Total incl. CAF</th><th>Avg per donation</th><th>Avg per completed project</th><th>Rolling 4Q avg per completed</th>
      </tr>
    </thead>
    <tbody>
    {foreach from=$report.quarters item=r}
      <tr class="{cycle values="odd-row,even-row"}">
        <td>{$r.quarter}{if $r.provisional} <em>(provisional)</em>{/if}</td><td>{$r.completed}</td><td>{$r.with_donation}</td><td>{$r.pct_display}</td><td>{$r.rolling_pct_display}</td>
        <td>{$r.total_display}</td><td>{$r.caf_display}</td><td>{$r.total_with_caf_display}</td><td>{$r.avg_per_donation_display}</td><td>{$r.avg_per_completed_display}</td><td>{$r.rolling_avg_per_completed_display}</td>
      </tr>
    {/foreach}
    </tbody>
  </table>

  <h3>Donations on projects not yet completed</h3>
  {if $report.open_project_donations}
    <ul>{foreach from=$report.open_project_donations item=d}<li>{$d.project|escape} ({$d.status|escape}): {$d.net_display}, first received {$d.first_received}</li>{/foreach}</ul>
  {else}<p>None.</p>{/if}

  <h3>Donations on projects closed but not completed (or cancelled)</h3>
  {if $report.not_completed_donations}
    <ul>{foreach from=$report.not_completed_donations item=d}<li>{$d.project|escape}: {$d.net_display}, first received {$d.first_received}</li>{/foreach}</ul>
  {else}<p>None.</p>{/if}

  <h3>Completed projects with no date</h3>
  {if $report.completed_without_close_date}
    <p>No status change into a completed status is recorded and there is no end date, so these cannot be placed in a quarter and are not counted above. Set the case's end date to include them.</p>
    <ul>{foreach from=$report.completed_without_close_date item=p}<li>{$p|escape}</li>{/foreach}</ul>
  {else}<p>None.</p>{/if}

  <h3>Client donations with no project linked</h3>
  <p>{$report.unlinked_client_donations.count} donation(s), {$report.unlinked_client_donations.net_display} net, received in this range. Set <em>Project</em> on each contribution so it counts above.</p>

  <p class="description">Project links go back to 2010, loaded from the Treasurer's quarterly workbook. About 50 donations in that workbook have no matching gift in CiviCRM, so some historical quarters read slightly low.</p>
</div>
