<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 3 consultant-to-client intro.
 *
 * Trigger: A Project case is created (Service Request closes with status
 * "Project Created"). Currently Nina copy/pastes this email manually;
 * this template moves the content into Civi so a CiviRule fires it
 * automatically (propose-mode → draft for Nina; auto-mode after sign-off).
 *
 * Body from Nina's Template 2 ("introducing VC to client") —
 * BrianPKM 3-Resources/mas-engagement-lifecycle-email-templates.md (2026-06-03).
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — Client recipient
 *   {case.id}, {case.subject}
 *   {case.custom_34}                   — Project MAS code
 *   {vc.display_name}                                  — Assigned VC (via Case role)
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_consultant_intro__client',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_consultant_intro__client',
        'msg_subject' => 'Your MAS consultant: introducing {vc.display_name}',
        'msg_html' => <<<'HTML'
<p>Greetings {contact.first_name},</p>

<p>I am very pleased to let you know that we have connected on your behalf with one of our Volunteer Consultants who is willing and available to work with you on your project.</p>

<p>The consultant is {vc.display_name}, who will be in touch with you to discuss the next steps in preparing for your project.</p>

<p>Project: {case.subject}<br/>
MAS code: {case.custom_34}<br/>
Consultant: {vc.display_name}</p>

<p>Best wishes to you and your organization,</p>

<p>PS: attached is a short bio from {vc.display_name}.</p>

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
