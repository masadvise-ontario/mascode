<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 4 donation notification to ED.
 *
 * Trigger: Contribution.create event. Symfony subscriber in mascode fans
 * out three internal notifications (ED, Treasurer, originating VC) in
 * parallel with the CiviCRM core donor receipt.
 *
 * Body placeholder — Nina/Brian/Steve fill via Civi admin UI.
 *
 * Available merge tags:
 *   {contribution.total_amount}, {contribution.receive_date}
 *   {contribution.contact_id.display_name}             — Donor
 *   {contribution.custom_donation.Linked_Project:label} — Linked Project (Phase 4 field)
 *   {contribution.custom_donation.Linked_VC.display_name} — Originating VC
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
        'msg_subject' => 'Donation received: {contribution.total_amount} from {contribution.contact_id.display_name}',
        'msg_html' => <<<'HTML'
<p>[TODO: ED notification body — fill from existing process. Suggested shape: heads-up that a donation was received, donor name, amount, attributed project / VC.]</p>

<p>Donor: {contribution.contact_id.display_name}<br/>
Amount: {contribution.total_amount}<br/>
Date: {contribution.receive_date}<br/>
Project: [linked project merge tag — Phase 4 structured Source field]<br/>
Originating VC: [linked VC merge tag — Phase 4 structured Source field]</p>

<p>—<br/>
Management Advisory Service (MAS)</p>
HTML
        ,
        'msg_text' => "Skeleton — see msg_html. Nina/Brian/Steve to fill body.",
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
