<?php

namespace Civi\Mascode\Test\Unit\Service;

use Civi\Mascode\Digest\DigestRowRenderer;
use Civi\Mascode\Test\TestCase;

/**
 * P1-9: the digest carries ONE link, to the per-VC check-in page.
 *
 * @coversNothing
 */
class VcDigestOneLinkTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const MAILER = self::ROOT . '/Civi/Mascode/Service/VcDigestMailer.php';
    private const TEMPLATE = self::ROOT . '/Civi/Mascode/Managed/MessageTemplate_mas_vc_monthly_digest__vc.mgd.php';

    /**
     * Rows are a list: no link when none is supplied — the P1-9 row shape.
     */
    public function testRowsWithoutAUrlCarryNoLink(): void
    {
        $html = DigestRowRenderer::renderRows([
            ['case_id' => 1, 'mas_code' => 'P2501', 'client_name' => 'Example Food Bank', 'subject' => 'Plan', 'start_date' => null],
        ]);
        $this->assertStringContainsString('Example Food Bank', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('Answer for this project', $html);
    }

    /**
     * The button escapes its URL, and an empty URL renders nothing (a button
     * to nowhere is worse than none). Input that trips it: a `"` in the query.
     */
    public function testTheButton(): void
    {
        $this->assertSame('', DigestRowRenderer::renderButton(''));

        $html = DigestRowRenderer::renderButton('https://example.org/a?x=1&y="2"');
        $this->assertStringContainsString('href="https://example.org/a?x=1&amp;y=&quot;2&quot;"', $html);
        $this->assertStringNotContainsString('y="2"', $html);
        $this->assertStringContainsString('bgcolor="#1a4971"', $html, 'The Outlook-safe table-cell button.');
    }

    /**
     * The template carries the one button (and a plain-link fallback), and no
     * longer promises a link per project.
     */
    public function testTheTemplateCarriesTheOneButton(): void
    {
        $decl = require self::TEMPLATE;
        $html = $decl[0]['params']['values']['msg_html'];
        $this->assertStringContainsString('{digest.checkin_button}', $html);
        $this->assertStringContainsString('{digest.checkin_url}', $html);
        $this->assertStringContainsString('{digest.project_rows}', $html);
        $this->assertStringNotContainsString('its own link', $html);
    }

    /**
     * ⚠ The one link is minted with NO afformArgs: token args are merged after
     * `civi.api.prepare` and bypass the public-form guard. Inputs that trip it:
     * `['case_id' => …]` (or any non-empty array) in `checkinUrl()`, a per-row
     * `createUrl` returning to `buildProjectRows()`, or the digest pointing
     * back at the per-project form.
     */
    public function testTheOneLinkCarriesNoArgs(): void
    {
        $code = $this->codeOnly((string) file_get_contents(self::MAILER));

        $this->assertMatchesRegularExpression(
            '/function checkinUrl\(int \$vcContactId\): string\s*\{\s*return \\\\Civi\\\\Afform\\\\Tokens::createUrl\(self::loadCheckinForm\(\), \$vcContactId, \[\]\);\s*\}/',
            $code
        );
        $this->assertSame(1, substr_count($code, 'Tokens::createUrl('), 'createUrl is called once, in checkinUrl() only.');
        $this->assertStringContainsString(
            'public const CHECKIN_FORM = \Civi\Mascode\Event\VcCheckinPageSubscriber::FORM_NAME;',
            $code
        );
        $this->assertStringContainsString('VcDigestTokenSubscriber::CHECKIN_URL_CONTEXT_KEY => $checkinUrl,', $code);
    }

    /**
     * The tokens are actually SET. Input that trips it: dropping either
     * `tokens()` line, which would send an email with no link and CI green.
     */
    public function testTheLinkTokensAreEvaluated(): void
    {
        $code = $this->codeOnly((string) file_get_contents(self::ROOT . '/Civi/Mascode/Event/VcDigestTokenSubscriber.php'));
        $this->assertStringContainsString("\$row->tokens('digest', 'checkin_button', \\Civi\\Mascode\\Digest\\DigestRowRenderer::renderButton(\$url));", $code);
        $this->assertStringContainsString("\$row->tokens('digest', 'checkin_url', htmlspecialchars(\$url, ENT_QUOTES, 'UTF-8'));", $code);
        $this->assertStringContainsString('$url = (string) ($row->context[self::CHECKIN_URL_CONTEXT_KEY] ?? \'\');', $code);
    }

    private function codeOnly(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }
}
