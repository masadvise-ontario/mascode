<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 3 VC assignment offer.
 *
 * Trigger: Service Request transitions to status "Sent for Assignment".
 * The system circulates the request to VCs (existing template 76 / "after
 * RCS" handles the client-facing notification; this template is the
 * VC-facing offer asking them to consider taking the project).
 *
 * Body placeholder — Nina/Brian fill via Civi admin UI.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — VC recipient
 *   {case.id}, {case.subject}, {case.status_id:label}
 *   {case.custom_Projects.MAS_Code}
 *   [client name / org tags TBD when wired]
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_vc_assignment_offer__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_vc_assignment_offer__vc',
        'msg_subject' => 'New MAS project available: {case.custom_Projects.MAS_Code}',
        'msg_html' => <<<'HTML'
<p>{contact.first_name},</p>

<p>[TODO: assignment offer body — fill from Nina's Outlook template. Suggested shape: brief description of the request, the client org, what's being asked, link to accept or decline.]</p>

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
