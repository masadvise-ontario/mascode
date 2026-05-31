<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 4 donation notification to originating VC.
 *
 * Trigger: Contribution.create event. Sibling to donation_notify__ed —
 * the VC credited for the donation (via the Linked VC structured Source
 * field) gets a "your project generated this donation" notice.
 *
 * Body placeholder — Nina/Brian fill via Civi admin UI.
 *
 * Available merge tags: same as donation_notify__ed.
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
        'msg_subject' => 'Your project generated a donation: {contribution.total_amount}',
        'msg_html' => <<<'HTML'
<p>{contact.first_name},</p>

<p>[TODO: VC notification body — fill from Nina's Outlook template. Suggested shape: heads-up that a donation tied to a project you led was received, donor name, amount, thank-you-for-the-work tone.]</p>

<p>Donor: {contribution.contact_id.display_name}<br/>
Amount: {contribution.total_amount}<br/>
Project: [linked project merge tag — Phase 4 structured Source field]</p>

<p>—<br/>
Management Advisory Service (MAS)</p>
HTML
        ,
        'msg_text' => "Skeleton — see msg_html. Nina/Brian to fill body.",
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
