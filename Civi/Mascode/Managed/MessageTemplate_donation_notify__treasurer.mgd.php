<?php

declare(strict_types=1);

/**
 * Donation notification to the Treasurer (donations ticket DN-3).
 *
 * Sent by Civi\Mascode\Service\DonationNotifier when a donation is entered,
 * replacing the CSM's blind copy to the Treasurer (spec BrianPKM
 * 3-Resources/mas-donation-process.md, decision D-E). Carries the accounting
 * detail: status, payment method, cheque/transaction reference, gross/fee/net.
 * It reminds him that a Pending gift is closed with Record Payment (deposit
 * date + fee), which is how received date and deposited date are both kept
 * (spec D-C, §4 Q2). Recipient: setting mascode_donation_notify_treasurer_contact_id.
 *
 * Merge tags: as donation_notify__ed.
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_donation_notify__treasurer',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_donation_notify__treasurer',
        'msg_subject' => 'Donation recorded: %%mas_donation.donor%% (%%mas_donation.amount%%, %%mas_donation.status%%)',
        'msg_html' => <<<'HTML'
<p>Hi {contact.first_name},</p>

<p>A donation has been recorded in CiviCRM:</p>

<p>Donor: %%mas_donation.donor%%<br/>
Type: %%mas_donation.type%%<br/>
Received: %%mas_donation.received%%<br/>
Payment method: %%mas_donation.method%%<br/>
Reference (cheque # / transaction): %%mas_donation.reference%%<br/>
Status: %%mas_donation.status%%</p>

<p>Gross: %%mas_donation.amount%%<br/>
Fee: %%mas_donation.fee%%<br/>
Net: %%mas_donation.net%%</p>

<p>Project: %%mas_donation.project%%<br/>
Volunteer Consultant: %%mas_donation.vc%%</p>

<p>If the status is <em>Pending</em>, use <strong>Record Payment</strong> on the contribution once it is in the bank: enter the deposit date, and the fee for CanadaHelps. CiviCRM calculates the net.</p>

<p><a href="%%mas_donation.link%%">Open the contribution in CiviCRM</a></p>

<p>&mdash;<br/>
MAS automated notification</p>
HTML
        ,
        'msg_text' => '',
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
