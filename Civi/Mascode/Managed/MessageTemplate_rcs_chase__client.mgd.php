<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 3 RCS chase to client.
 *
 * Trigger: Service Request has been in status "Request RCS" for N days
 * without the RCS/SAS forms being returned. CiviRule fires this template
 * in propose-mode (draft activity for Nina) or auto-mode after graduation.
 *
 * Body placeholder — Nina/Brian fill via Civi admin UI. update='unmodified'
 * preserves UI edits across mascode reconciles.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}
 *   {case.id}, {case.subject}, {case.status_id:label}
 *   {case.custom_Projects.MAS_Code}
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_rcs_chase__client',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_rcs_chase__client',
        'msg_subject' => 'Following up: please complete your RCS and SAS forms',
        'msg_html' => <<<'HTML'
<p>{contact.first_name},</p>

<p>[TODO: chase body — fill from Nina's Outlook template. Suggested shape: polite reminder that we're holding the request ({case.custom_Projects.MAS_Code}) until the RCS and SAS forms are returned, with the original form link.]</p>

<p>Request: {case.subject}<br/>
Status: {case.status_id:label}</p>

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
