<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 4 donation notification to Treasurer.
 *
 * Trigger: Contribution.create event. Sibling to donation_notify__ed —
 * Treasurer gets a parallel notification (likely with bank/accounting
 * detail Steve cares about).
 *
 * Body drafted 2026-06-03 (new automation). Steve to review/extend with the
 * accounting fields he needs; Phase 4 wires the structured-Source merge tags.
 *
 * Available merge tags: same as donation_notify__ed.
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
        'msg_subject' => 'Donation received: {contribution.total_amount} from {contribution.contact_id.display_name}',
        'msg_html' => <<<'HTML'
<p>A donation has been received:</p>

<p>Donor: {contribution.contact_id.display_name}<br/>
Amount: {contribution.total_amount}<br/>
Date received: {contribution.receive_date}<br/>
Payment method: {contribution.payment_instrument_id:label}<br/>
Transaction ID: {contribution.trxn_id}<br/>
Project: [linked project merge tag — Phase 4 structured Source field]<br/>
Originating VC: [linked VC merge tag — Phase 4 structured Source field]</p>

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
