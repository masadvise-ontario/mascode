<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 3 VC no-pickup chase.
 *
 * Trigger: Service Request has been in "Sent for Assignment" for N days
 * without a VC accepting. CiviRule fires this to the circulated VC group
 * (or to specific VCs) as a follow-up.
 *
 * Body placeholder — Nina/Brian fill via Civi admin UI.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — VC recipient
 *   {case.id}, {case.subject}, {case.status_id:label}
 *   {case.custom_Projects.MAS_Code}
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_vc_no_pickup_chase__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_vc_no_pickup_chase__vc',
        'msg_subject' => 'Still open: {case.custom_Projects.MAS_Code}',
        'msg_html' => <<<'HTML'
<p>{contact.first_name},</p>

<p>[TODO: chase body — fill from Nina's Outlook template. Suggested shape: this request is still circulating, would you reconsider, here's why it's a good fit.]</p>

<p>Request: {case.subject}<br/>
MAS code: {case.custom_Projects.MAS_Code}</p>

<p>—<br/>
Management Advisory Service (MAS)<br/>
<a href="https://www.masadvise.org">masadvise.org</a></p>
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
