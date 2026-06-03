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
 * Body from Nina's Template 4 (close REMINDER) —
 * BrianPKM 3-Resources/mas-engagement-lifecycle-email-templates.md (2026-06-03).
 * The old Gravity Forms expiring link is replaced by the tokenized Afform link.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — Client recipient
 *   {case.id}, {case.subject}
 *   {case.custom_Projects.MAS_Code}
 *   {case.custom_Projects.Estimated_Completion_Date}
 *   {form.afformProjectCloseClientFeedbackLink}        — tokenized close-form link
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
<p>Dear {contact.first_name},</p>

<p>This is just a friendly reminder to please fill out the Project Closing Form for your MAS project. You will find the form here: {form.afformProjectCloseClientFeedbackLink}</p>

<p>Project: {case.subject}<br/>
MAS code: {case.custom_Projects.MAS_Code}</p>

<p>Thank you so much and all the best,</p>

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
