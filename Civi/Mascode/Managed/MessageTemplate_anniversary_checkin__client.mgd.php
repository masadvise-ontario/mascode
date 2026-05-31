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
 * Body placeholder — Nina/Brian/Steve fill via Civi admin UI.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — Client recipient
 *   {case.id}, {case.subject}, {case.start_date}
 *   {case.custom_Projects.MAS_Code}
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
<p>{contact.first_name},</p>

<p>[TODO: anniversary check-in body — fill with Nina/Steve. Suggested shape: project has reached its twelve-month mark, would you like to share feedback / make a donation / continue the work into another year?]</p>

<p>Project: {case.subject}<br/>
Started: {case.start_date}</p>

<p>—<br/>
Management Advisory Service (MAS)<br/>
<a href="https://www.masadvise.org">masadvise.org</a></p>
HTML
        ,
        'msg_text' => "Skeleton — see msg_html. Nina/Steve/Brian to fill body.",
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
