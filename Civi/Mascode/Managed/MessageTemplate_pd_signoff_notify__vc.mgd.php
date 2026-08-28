<?php

declare(strict_types=1);

/**
 * Notifies the assigned VC that the client has authorized the Project
 * Definition (MAS call request: "copy the VC on the email confirming client
 * project-definition signoff").
 *
 * Sent by AfformSubmitSubscriber::sendVcSignoffCopy() when the client submits
 * the PD authorization form (civicrm/mas-pdef-client), alongside the existing
 * client and info@masadvise.org confirmations. It is a separate message rather
 * than a cc on the client's confirmation: that confirmation opens "Dear
 * <client>, thank you for submitting your MAS form" and is signed by Client
 * Services, so forwarding it verbatim would reach the VC addressed to someone
 * else. Sending separately also keeps the client's and the VC's addresses off
 * each other's copies.
 *
 * The submitted definition is appended below this shell as the standard
 * submission-summary block (SubmissionSummaryService), so the VC sees exactly
 * what was authorized — including the client's typed signature and title,
 * which are the authorization itself.
 *
 * Available merge tags:
 *   {contact.first_name}, {contact.display_name}   — the VC (Case Coordinator)
 *   {case.id}, {case.subject}
 *
 * Deliberately avoids {case.custom_N} tokens: those are id-based and do not
 * port dev -> prod. The MAS project code reaches the VC in the summary block.
 */
return [
  [
    'name' => 'MessageTemplate_mas_pd_signoff_notify__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_pd_signoff_notify__vc',
        'msg_subject' => 'Project Definition authorized: {case.subject}',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>Good news — the client has reviewed and authorized the Project Definition for your MAS project. The project is now active and work can begin.</p>

<p>Project: {case.subject}</p>

<p>What the client authorized is set out below for your records. If anything does not match your understanding of the project, please let us know before you start.</p>

<p>Thank you for volunteering with MAS.</p>

<p>&mdash;<br/>
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
