<?php

declare(strict_types=1);

/**
 * The monthly VC donation digest (spec D3, D9; ticket P1-4).
 *
 * One email per VC per month listing their open projects, each with its own
 * tokenised check-in link. Sent by Civi\Mascode\Service\VcDigestMailer, not by
 * a CiviRule — hence no `mas_lifecycle_` prefix, matching the convention the
 * other mailer-sent templates use.
 *
 * Merge tags:
 *   {contact.first_name}, {contact.display_name}  — the VC
 *   {digest.month}          — `YYYY-MM` of the round
 *   {digest.project_count}  — how many projects are listed
 *   {digest.project_rows}   — the rendered rows, one per project, each
 *                             carrying its own minted check-in link
 *
 * The `{digest.*}` tags come from Civi\Mascode\Event\VcDigestTokenSubscriber.
 * They exist so the body stays editable here rather than being assembled as
 * PHP strings — Nina and Steve own the wording (the copy is theirs to sign off,
 * spec §Open Questions) and nobody should need a deploy to change a sentence.
 *
 * ⚠ THE SUBJECT MUST NOT CONTAIN ANY LIFECYCLE TRANSITION PREFIX, and this is
 * not a style note. ProjectLifecycleStatusSubscriber::matchTransition() does
 * `str_contains($activitySubject, $prefix)` against the static part of each
 * transition template's subject — today the bare strings **"Project
 * Completion"** and **"MAS Project Signoff"**. This mailer writes a
 * "Sent Automated Email" activity per project, and that subscriber fires on
 * exactly those. A digest subject containing either string would therefore
 * advance EVERY listed project to an awaiting-form status the moment the
 * digest went out — 132 projects on the 2026-09-21 clone, silently, with no
 * error and a perfectly normal-looking email.
 *
 * `VcDigestMailer::assertSubjectCannotTriggerATransition()` enforces this at
 * send time, and tests/Unit/Service/VcDigestSubjectSafetyTest.php enforces it
 * in CI against the declared subjects. Change the wording freely; keep those
 * two strings out of it.
 */
return [
  [
    'name' => 'MessageTemplate_mas_vc_monthly_digest__vc',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    // 'unmodified' like every other MAS template: mascode plants the structure
    // and the merge tags, and the office owns the wording thereafter.
    // ⚠ A UI edit to the copy does NOT survive reliably: MessageTemplate is not
    // an APIv4 ManagedEntity, so this policy degrades to always-update and the
    // next deploy changing this declaration overwrites production. Content-diff
    // production before editing. See Civi/Mascode/Managed/README.md.
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => 'mas_vc_monthly_digest__vc',
        'msg_subject' => 'Your MAS projects: a quick monthly check-in ({digest.month})',
        'msg_html' => <<<'HTML'
<p>Dear {contact.first_name},</p>

<p>Thank you for the work you do for MAS and for the organizations we serve.</p>

<p>Our records show <strong>{digest.project_count}</strong> project(s) still open with you. So that we can keep our records current — and so that clients are thanked while the work is still fresh — could you let us know whether each one is finished?</p>

<p>Each project below has its own link. It takes about a minute per project, and you do not need to log in.</p>

{digest.project_rows}

<p>If a project is finished, we will email you the short Project Completion form to record your hours and what you delivered. That form is what lets us ask the client to sign off.</p>

<p><strong>About the second question.</strong> MAS is funded entirely by donations from the organizations it helps, and a request from the consultant the client actually worked with lands very differently from a paragraph on a form. If you would rather not ask, that is completely fine — just tell us, and the office will follow up instead.</p>

<p>If something in this list looks wrong, please reply to this email and we will fix it.</p>

<p>With thanks,</p>

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
