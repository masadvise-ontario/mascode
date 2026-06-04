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
 * Body drafted 2026-06-03 (no direct Nina source — the weekly Tuesday call-out
 * was never a saved template). Propose-mode review by Nina before first send.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — VC recipient
 *   {case.id}, {case.subject}, {case.status_id:label}
 *   {case.custom_32}
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
        'msg_subject' => 'New MAS project available: {case.custom_32}',
        'msg_html' => <<<'HTML'
<p>Hi {contact.first_name},</p>

<p>A new MAS client request is available and looking for a Volunteer Consultant:</p>

<p>Request: {case.subject}<br/>
MAS code: {case.custom_32}</p>

<p>You can review the full request details, including the client's self-assessment, in the <a href="https://www.masadvise.org/vcportal/">VC Portal</a>. If you are interested in taking on this project, please reply to this email and we will connect you with the client's Executive Director.</p>

<p>Thank you for everything you do for MAS clients.</p>

<p>—<br/>
Management Advisory Service (MAS)<br/>
<a href="https://www.masadvise.org">masadvise.org</a></p>
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
