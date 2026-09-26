<?php

declare(strict_types=1);

// file: Civi/Mascode/Digest/DigestRowRenderer.php

namespace Civi\Mascode\Digest;

/**
 * Renders the monthly digest's per-project rows.
 *
 * DELIBERATELY FREE OF EVERY CiviCRM DEPENDENCY, for the same reason
 * Civi\Mascode\Security\AfformArgPolicy is: so it can be unit tested without a
 * bootstrapped Civi. CI has no CiviCRM (docs/TESTING.md), and
 * VcDigestTokenSubscriber extends AutoSubscriber, so anything left inside that
 * class is untestable in CI by construction.
 *
 * What is at stake is not cosmetic. These rows carry the escaping of
 * client-entered case subjects into an email, and the one minted link per
 * project that the whole feature turns on. A row that renders the wrong link,
 * or lets a case subject become markup, is a real defect in something 61
 * volunteers receive.
 *
 * The subscriber keeps the token wiring; this keeps the HTML.
 */
final class DigestRowRenderer
{
    /**
     * Render the project rows.
     *
     * @param array $rows Each: case_id, mas_code, client_name, subject, start_date, checkin_url.
     */
    public static function renderRows(array $rows): string
    {
        if (!$rows) {
            // A VC with no eligible projects is never mailed at all (Goal 5),
            // so this should be unreachable. If it ever is reached, an honest
            // sentence beats an empty gap that reads as a broken email.
            return '<p><em>No open projects are currently recorded against you.</em></p>';
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::renderRow($row);
        }

        return "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" width=\"100%\">\n"
            . implode("\n", $out)
            . "\n</table>";
    }

    /**
     * One project.
     *
     * A table rather than styled `<div>`s, and inline attributes rather than
     * CSS, for the reason CHANGELOG 1.1.18 records about the donate button:
     * Outlook on Windows renders through the Word engine, which ignores
     * `display:inline-block`, padding on inline elements and `border-radius`.
     * MAS's sector runs on Microsoft 365, so a layout that only works in
     * Gmail is a layout that fails for most of the recipients.
     */
    private static function renderRow(array $row): string
    {
        $code = self::esc($row['mas_code'] ?? '');
        $client = self::esc($row['client_name'] ?? '');
        $subject = self::esc($row['subject'] ?? '');
        $started = self::esc(self::formatDate($row['start_date'] ?? null));
        // The URL is minted by us and goes in an href, so it needs attribute
        // escaping — not the "it's our own data" shortcut. A `"` in a query
        // string would otherwise break out of the attribute.
        $url = htmlspecialchars((string) ($row['checkin_url'] ?? ''), ENT_QUOTES, 'UTF-8');

        // The client leads: it is what a VC recognises a project by. The code
        // moves to the detail line so the office can still match the row.
        $heading = $client !== '' ? $client : ($code !== '' ? $code : 'Project');
        $meta = [];
        if ($client !== '' && $code !== '') {
            $meta[] = $code;
        }
        if ($subject !== '') {
            $meta[] = $subject;
        }
        if ($started !== '') {
            $meta[] = "started {$started}";
        }
        $metaLine = $meta ? '<div style="color:#555;font-size:14px;">' . implode(' &middot; ', $meta) . '</div>' : '';

        return <<<HTML
  <tr>
    <td style="padding:12px 0;border-bottom:1px solid #e2e2e2;">
      <div style="font-weight:bold;font-size:16px;">{$heading}</div>
      {$metaLine}
      <div style="padding-top:8px;"><a href="{$url}">Answer for this project &rarr;</a></div>
    </td>
  </tr>
HTML;
    }

    /**
     * A date a volunteer can read, or '' when there is nothing to show.
     *
     * Empty rather than a placeholder: `start_date` is nullable, and "started
     * —" in an email about *your* project reads as a system fault. The row
     * simply omits the clause.
     */
    private static function formatDate($value): string
    {
        if (empty($value)) {
            return '';
        }
        $time = strtotime((string) $value);
        return $time === false ? '' : date('j F Y', $time);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
