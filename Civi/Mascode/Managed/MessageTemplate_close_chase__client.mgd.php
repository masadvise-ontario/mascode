<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 2 close-form chase to client.
 *
 * Trigger: Project case has been in "Awaiting Close Form" for N days
 * without the client returning the close Afform (afformProjectCloseClientFeedback).
 * Replaces the WordPress expiring-link follow-up; chase cadence
 * configurable in Phase 2 build (Steve floated 1/3/5-month intervals).
 *
 * Body placeholder — Nina/Brian fill via Civi admin UI.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — Client recipient
 *   {case.id}, {case.subject}
 *   {case.custom_Projects.MAS_Code}
 *   {case.custom_Projects.Estimated_Completion_Date}
 *   {tokenized_close_url}                              — Phase 2 token mechanism (TBD)
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_close_chase__client',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_close_chase__client',
        'msg_subject' => 'Reminder: project close form for {case.custom_Projects.MAS_Code}',
        'msg_html' => <<<'HTML'
<p>{contact.first_name},</p>

<p>[TODO: chase body — fill from Nina's Outlook template or draft. Suggested shape: polite reminder that the close form is outstanding for {case.custom_Projects.MAS_Code}, link to {tokenized_close_url} (Phase 2 token mechanism), brief context.]</p>

<p>Project: {case.subject}<br/>
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
