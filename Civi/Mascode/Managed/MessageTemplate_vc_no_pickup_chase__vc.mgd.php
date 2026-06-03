<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 3 VC no-pickup chase.
 *
 * Trigger: Service Request has been in "Sent for Assignment" for N days
 * without a VC accepting. CiviRule fires this to the circulated VC group
 * (or to specific VCs) as a follow-up.
 *
 * Body drafted 2026-06-03 (no direct Nina source — this chase is a new
 * automation). Propose-mode review by Nina before first send.
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
<p>Hi {contact.first_name},</p>

<p>The client request below is still open and looking for a Volunteer Consultant:</p>

<p>Request: {case.subject}<br/>
MAS code: {case.custom_Projects.MAS_Code}</p>

<p>If you have some capacity, would you take another look? Sometimes a project that doesn't seem like an exact fit can still benefit greatly from your experience. If you would like more background before deciding, just reply and we will share the details.</p>

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
