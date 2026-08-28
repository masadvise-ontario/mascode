<?php

declare(strict_types=1);

/**
 * Forwards the client's project-close feedback to the VC who did the work
 * (MAS call request, 2026-08-28: "if 'Could we share your comments with the
 * Volunteer Consultant who worked with you?' is answered yes, then include the
 * full feedback in the VC email").
 *
 * Sent by AfformSubmitSubscriber::sendVcClientFeedback() when the client
 * submits the project-close feedback form (civicrm/mas-pclose-client) AND
 * Project_Close_Client.share_with_vc is "Yes". The client's consent is the
 * gate: no answer, or "No", means the VC is not told. That field was collected
 * from clients for a long time without anything acting on it.
 *
 * The feedback is appended below this shell as the "mas:record-close-client-for-vc"
 * record composition — project header plus the client's answers, minus the two
 * consent questions (share_with_vc, use_in_marketing), which are permissions
 * paperwork rather than feedback about the work. Complete enough that the VC
 * can print the email as their record of how the project landed.
 *
 * Naming: deliberately NOT the mas_lifecycle_* prefix. That prefix marks the
 * templates LifecycleMailer sends on behalf of a CiviRules rule, and
 * CRM/Mascode/CiviRules/Form/LifecycleEmail.php groups its template picker by
 * it; this one is sent directly by an afform submit handler with no rule
 * behind it.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}   — the VC (Case Coordinator)
 *   {case.id}, {case.subject}
 *
 * Deliberately avoids {case.custom_N} tokens: those are id-based and do not
 * port dev -> prod. The MAS project code reaches the VC in the header.
 */
return [
  [
    'name' => 'MessageTemplate_mas_close_feedback_share__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_close_feedback_share__vc',
        'msg_subject' => 'Client feedback on your MAS project: {case.subject}',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>The client has completed their feedback form for the MAS project you worked on, and agreed to have their comments shared with you. Their feedback is below.</p>

<p>Project: {case.subject}</p>

<p>Thank you for the time and expertise you gave this project.</p>

<p>&mdash;<br/>
Management Advisory Service (MAS)<br/>
<a href="https://www.masadvise.org">masadvise.org</a></p>
HTML
        ,
        'msg_text' => <<<'TEXT'
Dear {contact.first_name},

The client has completed their feedback form for the MAS project you worked on, and agreed to have their comments shared with you. Their feedback is below.

Project: {case.subject}

Thank you for the time and expertise you gave this project.

--
Management Advisory Service (MAS)
masadvise.org
TEXT
        ,
        'is_active' => TRUE,
        'is_default' => TRUE,
      ],
      'match' => ['msg_title'],
    ],
  ],
];
