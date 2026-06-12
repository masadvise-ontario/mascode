<?php

declare(strict_types=1);

/**
 * Project Definition chase to the VC (2026-06-12 PD flow). Sent by the
 * mas_lifecycle_vc_pd_chase rule in propose mode at 30/90/150 days while the
 * project sits in "Awaiting VC Project Definition".
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_pd_chase__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_pd_chase__vc',
        'msg_subject' => 'Reminder: Project Definition form for {case.custom_34}',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>This is just a friendly reminder to please fill out the Project Definition form for your MAS project. You will find the form here: {form.afformMASProjectDefinitionVCLink}</p>

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
