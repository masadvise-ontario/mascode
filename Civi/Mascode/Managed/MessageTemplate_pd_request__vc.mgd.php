<?php

declare(strict_types=1);

/**
 * Project Definition request to the VC (2026-06-12 PD flow).
 *
 * Proposed (propose-mode draft) by ServiceRequestToProject right after the
 * project is created and the case roles are in place; the project sits at
 * "Awaiting VC Project Definition" until the VC submits the form.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — VC recipient (Case Coordinator)
 *   {case.id}, {case.subject}
 *   {case.custom_34}                                  — MAS Project Case Code
 *   {form.afformMASProjectDefinitionVCLink}            — tokenized PD form link
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_pd_request__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_pd_request__vc',
        'msg_subject' => 'Action needed: Project Definition for {case.custom_34}',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>Thank you for taking on this MAS project. To get started, please complete the Project Definition form — it captures what you and the client have agreed the project will deliver. You will find the form here: {form.afformMASProjectDefinitionVCLink}</p>

<p>Project: {case.subject}<br/>
MAS code: {case.custom_34}</p>

<p>Once you submit the form, the client will be asked to review and authorize the definition, and the project will become active.</p>

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
