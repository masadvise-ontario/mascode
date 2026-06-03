<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 4 donation notification to originating VC.
 *
 * Trigger: Contribution.create event. Sibling to donation_notify__ed —
 * the VC credited for the donation (via the Linked VC structured Source
 * field) gets a "your project generated this donation" notice.
 *
 * Body drafted 2026-06-03 (new automation — no Nina source). Propose-mode
 * review before first send; Phase 4 wires the structured-Source merge tags.
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
<p>Hi {contact.first_name},</p>

<p>Good news &mdash; a donation linked to a project you led has been received:</p>

<p>Donor: {contribution.contact_id.display_name}<br/>
Amount: {contribution.total_amount}<br/>
Project: [linked project merge tag — Phase 4 structured Source field]</p>

<p>Thank you for the work that made this possible. Donations like this are what keep MAS running.</p>

<p>—<br/>
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
