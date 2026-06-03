<?php

declare(strict_types=1);

/**
 * Phase 3 VC pickup confirmation.
 *
 * Trigger: a VC expresses interest in / picks up a circulated request
 * (case role assigned via MasAddRole). Currently Nina replies manually;
 * a CiviRule fires this template (propose-mode → draft for Nina;
 * auto-mode after sign-off). The follow-up "second email" connecting
 * VC and ED stays a manual/propose step (consultant_intro__client is
 * the client-facing sibling).
 *
 * Body from Nina's Template 1 ("VC picks up a project") —
 * BrianPKM 3-Resources/mas-engagement-lifecycle-email-templates.md (2026-06-03).
 * The Project Definition Form attachment is added at send time.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — VC recipient
 *   {case.id}, {case.subject}
 *   {case.custom_Projects.MAS_Code}
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_vc_pickup_confirm__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_vc_pickup_confirm__vc',
        'msg_subject' => 'Thank you for picking up {case.custom_Projects.MAS_Code}',
        'msg_html' => <<<'HTML'
<p>Hi {contact.first_name},</p>

<p>Thank you for your interest in this request.</p>

<p>I will connect you in a second email with the client's Executive Director for project {case.custom_Projects.MAS_Code}.</p>

<p>Please fill in the <strong>Project Definition Form</strong> (attached) at the beginning of the project, discuss it with the client, and send it back to us.</p>

<p>If you are not a user of the VC Portal and cannot access the client's details, we will forward you the request form and the self-assessment form by email.</p>

<p>Please let me know if you have any questions. I am happy to help.</p>

<p>Project: {case.subject}<br/>
MAS code: {case.custom_Projects.MAS_Code}</p>

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
