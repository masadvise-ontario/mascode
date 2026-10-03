<?php

declare(strict_types=1);

/**
 * Donation notification to the ED (donations ticket DN-3).
 *
 * Sent by Civi\Mascode\Service\DonationNotifier when a donation is entered,
 * replacing the CSM's hand-copied email to the ED (spec BrianPKM
 * 3-Resources/mas-donation-process.md, decision D-E). Recipient: setting
 * mascode_donation_notify_ed_contact_id.
 *
 * Merge tags: {contact.*} for the recipient, and DonationNotifier's
 * %%mas_donation.<x>%% placeholders: donor, type, amount, fee, net, received,
 * method, status, reference, source, project, project_code, vc, link.
 * Core {contribution.custom_N} tokens are id-based and do not port dev → prod,
 * hence the placeholders.
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_donation_notify__ed',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_donation_notify__ed',
        // No amount in the SUBJECT: core's activity ACL shows an activity to anyone
        // who can view one of its contacts, and the subject is what lists show.
        'msg_subject' => 'Donation received: %%mas_donation.donor%%',
        'msg_html' => <<<'HTML'
<p>Hi {contact.first_name},</p>

<p>A donation has been recorded in CiviCRM:</p>

<p>Donor: %%mas_donation.donor%%<br/>
Type: %%mas_donation.type%%<br/>
Amount: %%mas_donation.amount%%<br/>
Received: %%mas_donation.received%% (%%mas_donation.method%%, %%mas_donation.status%%)<br/>
Project: %%mas_donation.project%%<br/>
Volunteer Consultant: %%mas_donation.vc%%</p>

<p><a href="%%mas_donation.link%%">View the contribution in CiviCRM</a></p>

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
