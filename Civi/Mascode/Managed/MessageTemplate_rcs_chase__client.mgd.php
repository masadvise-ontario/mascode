<?php

declare(strict_types=1);

/**
 * Skeleton — Phase 3 RCS chase to client.
 *
 * Trigger: Service Request has been in status "Request RCS" for N days
 * without the RCS/SAS forms being returned. CiviRule fires this template
 * in propose-mode (draft activity for Nina) or auto-mode after graduation.
 *
 * Body drafted 2026-06-03 (no direct Nina source — this chase is a new
 * automation). Propose-mode review by Nina before first send. update='unmodified'
 * preserves UI edits across mascode reconciles.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}
 *   {case.id}, {case.subject}, {case.status_id:label}
 *   {case.custom_32}
 *   {form.afformMASRCSFormLink}  — tokenized RCS form link
 *   {form.afformMASSASSLink}     — tokenized Short Self-Assessment link
 *   {form.afformMASSASFLink}     — tokenized Full Self-Assessment link
 * (same form-link tokens as the original "MAS RCS Template" request email,
 * so the chase re-sends the personalized links rather than telling the
 * client to dig out the earlier email.)
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
<p>Dear {contact.first_name},</p>

<p>This is a friendly reminder from MAS. We received your request for consulting services, and we are holding it while we wait for your completed Request for Consulting Services (RCS) and Self-Assessment forms. We need both before we can circulate your request to our Volunteer Consultants.</p>

<p>Request: {case.subject}</p>

<p>For your convenience, here are your personalized form links again:</p>

<p><b>Request for Consulting Services (RCS) form:</b><br/>
{form.afformMASRCSFormLink}</p>

<p><b>Organization Self-Assessment Survey (SAS)</b> &mdash; please complete whichever fits your organization:</p>

<p>&rarr; Short Form (no paid staff): {form.afformMASSASSLink}<br/>
&rarr; Full Form (have paid staff): {form.afformMASSASFLink}</p>

<p>If anything is unclear or you would like help completing the forms, please reach out. We are happy to assist.</p>

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
