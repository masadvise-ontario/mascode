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
 * Body placeholder — Nina/Brian fill via Civi admin UI.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — Client recipient
 *   {case.id}, {case.subject}
 *   {case.custom_Projects.MAS_Code}                   — Project MAS code
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
<p>{contact.first_name},</p>

<p>[TODO: introduction body — fill from Nina's copy/paste template. Suggested shape: introduce {vc.display_name} as the assigned consultant for {case.custom_Projects.MAS_Code}, share their background/contact, next steps.]</p>

<p>Project: {case.subject}<br/>
Consultant: {vc.display_name}</p>

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
