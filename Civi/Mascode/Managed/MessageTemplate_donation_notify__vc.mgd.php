<?php

declare(strict_types=1);

/**
 * Donation notification to the project's VC (donations ticket DN-3).
 *
 * Sent by Civi\Mascode\Service\DonationNotifier to the contribution's Linked
 * VC (filled from the project's Case Coordinator by DonationLinker).
 *
 * ⚠ NO AMOUNT, in the subject or the body (Brian 2026-10-03, spec §4 Q4,
 * TBC with the Treasurer). This activity is filed on the Project case, and
 * the VC Portal lists every case activity subject. Do not add
 * %%mas_donation.amount%%, fee or net here without that decision changing.
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
