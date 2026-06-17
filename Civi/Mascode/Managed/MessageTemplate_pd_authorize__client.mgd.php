<?php

declare(strict_types=1);

/**
 * Project Definition authorization request to the client (2026-06-12 PD
 * flow). Proposed automatically by the mas_lifecycle_pd_client_propose rule
 * when the VC submits the Project Definition form; click-sending it advances
 * the project to "Awaiting Client Project Definition"
 * (ProjectLifecycleStatusSubscriber).
 *
 * Renders the VC's definition inline via %%mas_case.*%% placeholders —
 * resolved by NAME against the project CASE by LifecycleMailer (the VC's
 * definition now lives in case custom fields; core {case.custom_N} tokens are
 * id-based and don't port dev→prod, and name-based case tokens don't resolve
 * in this CiviCRM version, so %%-delimited name placeholders are used).
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}      — client rep recipient
 *   {case.id}, {case.subject}, {case.custom_34}
 *   %%mas_case.Project_Definition.estimated_duration%%
 *   %%mas_case.Project_Definition.assistance_provided%%
 *   %%mas_case.Project_Definition.project_completion%%
 *   {form.afformMASProjectDefinitionClientLink}        — tokenized authorization form link
 */
return [
  [
    'name' => 'MessageTemplate_mas_lifecycle_pd_authorize__client',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_lifecycle_pd_authorize__client',
        'msg_subject' => 'Please review and authorize your MAS Project Definition ({case.custom_34})',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>Your Volunteer Consultant has completed the Project Definition for your MAS project. Please review it below and authorize it using this form: {form.afformMASProjectDefinitionClientLink}</p>

<p>Project: {case.subject}<br/>
MAS code: {case.custom_34}</p>

<p><strong>Estimated duration:</strong> %%mas_case.Project_Definition.estimated_duration%%</p>

<p><strong>Assistance the Volunteer Consultant has agreed to provide:</strong><br/>
%%mas_case.Project_Definition.assistance_provided%%</p>

<p><strong>What defines project completion?</strong><br/>
%%mas_case.Project_Definition.project_completion%%</p>

<p>Once you authorize the definition, the project becomes active and work can begin.</p>

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
