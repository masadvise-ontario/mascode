<?php

declare(strict_types=1);

/**
 * Close-form chase to the VC (2026-06-12, close-path rework).
 *
 * Trigger: Project case has been in "Awaiting VC Project Close Form" for N
 * days without the VC returning the close report Afform
 * (afformProjectCloseVCFeedback). Sent by the mas_lifecycle_vc_close_chase
 * CiviRule in propose mode at 30/90/150 days — sibling of
 * mas_lifecycle_close_chase__client.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — VC recipient (Case Coordinator)
 *   {case.id}, {case.subject}
 *   {case.custom_34}                                  — MAS Project Case Code (core case tokens are ID-based)
 *   {form.afformProjectCloseVCFeedbackLink}            — tokenized close-report link
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_close_chase__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_close_chase__vc',
        'msg_subject' => 'Reminder: project close report for {case.custom_34}',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>This is just a friendly reminder to please fill out the Project Close Report for your MAS project. You will find the form here: {form.afformProjectCloseVCFeedbackLink}</p>

<p>Project: {case.subject}<br/>
MAS code: {case.custom_34}</p>

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
