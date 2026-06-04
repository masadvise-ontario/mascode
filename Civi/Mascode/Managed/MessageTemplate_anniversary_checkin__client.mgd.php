<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 4 twelve-month anniversary check-in.
 *
 * Trigger: Project case reaches twelve months from start_date. CiviRule
 * fires this template (propose-mode → draft for Nina) and triggers the
 * rollover sequence (close current Project, spawn fresh Project with same
 * Originating Service Request reference).
 *
 * Body drafted 2026-06-03 (new automation — no Nina source). Propose-mode
 * review by Nina/Steve before first send.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — Client recipient
 *   {case.id}, {case.subject}, {case.start_date}
 *   {case.custom_34}                                  — MAS Project Case Code (core case tokens are ID-based)
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_anniversary_checkin__client',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_anniversary_checkin__client',
        'msg_subject' => 'One year on: how is {case.subject} going?',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>Your MAS project has reached its twelve-month mark:</p>

<p>Project: {case.subject}<br/>
MAS code: {case.custom_34}<br/>
Started: {case.start_date}</p>

<p>MAS projects run for a maximum of twelve months. If the work with your consultant is continuing, we will simply roll it over into a fresh project so our records stay current &mdash; nothing is needed from you beyond a quick confirmation. If the project has wrapped up, we will send you our short project-close form.</p>

<p>Either way, we would love to hear how things are going &mdash; just reply to this email.</p>

<p>And if MAS has been helpful to your organization, please consider making a donation. We receive no external funding and are funded entirely by contributions from the organizations we have helped.</p>

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
