<?php

declare(strict_types=1);

/**
 * Project Definition authorization chase to the client (2026-06-12 PD flow).
 * Sent by the mas_lifecycle_client_pd_chase rule in propose mode at
 * 30/90/150 days while the project sits in "Awaiting Client Project
 * Definition".
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_pd_chase__client',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_pd_chase__client',
        'msg_subject' => 'Reminder: please authorize your MAS Project Definition ({case.custom_34})',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>This is just a friendly reminder to please review and authorize the Project Definition for your MAS project. You will find the form here: {form.afformMASProjectDefinitionClientLink}</p>

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
