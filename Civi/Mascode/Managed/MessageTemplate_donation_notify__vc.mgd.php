<?php

declare(strict_types=1);

/**
 * Donation notification to the project's VC (donations ticket DN-3).
 *
 * Sent by Civi\Mascode\Service\DonationNotifier to the contribution's Linked
 * VC (filled from the project's Case Coordinator by DonationLinker).
 *
 * The BODY shows the amount, and for a split gift the whole gift (R2,
 * Treasurer demo 2026-10-06; this reverses the 2026-10-03 "no amount").
 * ⚠ NEVER the SUBJECT: it becomes the subject of an activity on the Project
 * case, and the VC Portal lists every case activity subject. The notifier
 * refuses to send a subject with an amount, and never fills fee or net.
 *
 * Merge tags: as donation_notify__ed.
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_donation_notify__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_donation_notify__vc',
        'msg_subject' => 'A donation was received for your project %%mas_donation.project_code%%',
        'msg_html' => <<<'HTML'
<p>Hi {contact.first_name},</p>

<p>Good news &mdash; %%mas_donation.donor%% has made a donation to MAS for the project you led:</p>

<p>%%mas_donation.project%%</p>

<p>Amount: %%mas_donation.amount%%%%mas_donation.split%%</p>

<p>Thank you for the work that made this possible. Donations like this are what keep MAS running.</p>

<p>&mdash;<br/>
Management Advisory Service (MAS)</p>
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
