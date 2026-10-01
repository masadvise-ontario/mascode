<?php

namespace Civi\Mascode\Mcp\Tests\Unit;

use Civi\Mascode\Mcp\Vc\TextSanitiser;
use PHPUnit\Framework\TestCase;

/**
 * T5 sanitiser (docs/plans/vc-activity-policy.md §3). Every value below is synthetic: the tokens are
 * made-up JWT-shaped strings, the hosts are example.org / example.invalid.
 *
 * @group unit
 */
class TextSanitiserTest extends TestCase {

  /**
   * A JWT whose header and claims the step-1b gate does NOT know ({"foo":"x"}.{"bar":1}), so the
   * fragment rules (steps 2-4) are what these tests pin. AFF_JWT is Afform-shaped and gated.
   */
  private const JWT = 'eyJmb28iOiJ4In0.eyJiYXIiOjF9.c2lnbmF0dXJl';
  private const AFF_JWT = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJleHAiOjE3OTAwMDAwMDB9.Xk3Qa7Lm2c2lnbmF0dXJlaGVyZWxvbmdlcg9Z_w-8vT';

  /** Nothing credential-shaped may survive; withholding the field (NULL) is a pass. */
  private function assertNoCredential(?string $out): void {
    if ($out === NULL) {
      $this->addToAssertionCount(1);
      return;
    }
    foreach (['eyJ', '_aff', '_authx', 'Bearer', 'hunter2', '<!--mas'] as $needle) {
      $this->assertStringNotContainsStringIgnoringCase($needle, $out);
    }
  }

  /** @dataProvider credentialTexts */
  public function testCredentialsNeverSurvive(string $in): void {
    $this->assertNoCredential(TextSanitiser::sanitise($in));
  }

  /** The shapes real mail and notes carry are cleaned, not withheld. */
  public function testCommonShapesAreCleanedNotWithheld(): void {
    foreach (['token link in an href', 'token link in plain text', 'percent-encoded', 'double percent-encoded', 'entity-encoded',
      'bare JWT', 'Bearer+ as stored', 'mascode meta block', 'password in markup', 'password inside a comment',
      'password after a stray <', 'password inside an attribute', 'JWT with a zero-width space', 'JWT with a soft hyphen entity'] as $case) {
      $out = TextSanitiser::sanitise($this->credentialTexts()[$case][0]);
      $this->assertNotNull($out, $case);
      $this->assertNoCredential($out);
    }
  }

  public function credentialTexts(): array {
    $link = 'https://example.org/civicrm/x?token=Bearer+' . self::JWT;
    $formLink = 'https://example.org/civicrm/mas-checkin?_aff=Bearer+' . self::AFF_JWT;
    return [
      'form link in an href' => ['<p><a href="' . $formLink . '">Open the form</a></p>'],
      'token link in an href' => ['<p><a href="' . $link . '">Open the form</a></p>'],
      'token link in plain text' => ["Your link: $link thanks"],
      'percent-encoded' => ['Go to ' . rawurlencode($link)],
      'double percent-encoded' => ['Go to ' . rawurlencode(rawurlencode($link))],
      'entity-encoded' => ['<a href="https&#58;//example.org/x?&#116;oken=Bearer%2B' . self::JWT . '">x</a>'],
      'bare JWT' => ['token ' . self::JWT . ' end'],
      'bare Bearer' => ['Authorization: Bearer ' . self::JWT],
      'Bearer+ as stored' => ['auth=Bearer+' . self::JWT],
      'query param without a host' => ['paste ?_authx=abc123&x=1 here'],
      'cs checksum in a relative link' => ['<a href="/civicrm/x?cid=5&cs=abcdef_123_inf">x</a>'],
      'mascode meta block' => ['Hello <!--mas-lifecycle {"template_title":"x","token":"' . self::JWT . '"}--> there'],
      'unterminated comment' => ['Hello <!--mas-digest never closed ' . self::JWT],
      'password in markup' => ['<strong>Password:</strong> hunter2'],
      'password after nbsp entity' => ['Password:&nbsp;hunter2'],
      'password as JSON' => ['{"password":"hunter2"}'],
      'passwd spaced' => ['passwd  =  hunter2'],
      // PR #11 review H1: a token split so no single fragment looks like one.
      'JWT split by <wbr> after a link' => ['<a href="#">https://example.org/f?_aff=Bearer+eyJhbGciOiJSUzI1NiJ9<wbr>.eyJzdWIiOiJ4In0hunter2x.c2lnbmF0dXJlaGVyZWxvbmdlcg</a>'],
      'JWT wrapped onto a new line' => ["https://example.org/civicrm/mas-checkin?_aff=Bearer+eyJhbGciOiJSUzI1NiJ9.eyJz\nZHViIjoieCJ9aGVsbG93b3JsZA.c2lnbmF0dXJlaGVyZWxvbmdlcg"],
      'JWT with a quoted-printable soft break' => ["https://example.org/x?_aff=3DBearer+eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ4=\r\nIn0aGVsbG93b3JsZA.c2lnbmF0dXJlaGVyZWxvbmdlcg"],
      'bare JWT split by a newline' => ["eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOi\nJ4In0.c2lnbmF0dXJl"],
      'JWT with a zero-width space' => ["tok eyJmb28i\u{200B}OiJ4In0.eyJiYXIiOjF9.c2lnbmF0dXJl"],
      'JWT with a soft hyphen entity' => ['tok eyJmb28i&shy;OiJ4In0.eyJiYXIiOjF9.c2lnbmF0dXJl'],
      'underscore aff with a zero-width space' => ["x ?_a\u{200B}ff=Bearer+tok"],
      // PR #11 review M1: a stray < must not hide a password.
      'password after a stray <' => ['Budget < 500. Password: hunter2 -> use'],
      'password between decoded &lt; &gt;' => ['a &lt; b. Password: hunter2 &gt; ok'],
      'password inside a comment' => ['<!-- Password: hunter2 -->'],
      'password inside an attribute' => ['<span title="Password: hunter2">x</span>'],
      // PR #11 review L1 (beyond the spec's list, cheap to cover).
      'PIN' => ['Door PIN: hunter2'],
      'api_key as JSON' => ['{"api_key":"hunter2hunter2"}'],
      'Basic auth' => ['Authorization: Basic aHVudGVyMjpodW50ZXIy'],
      'fullwidth colon' => ['Password：hunter2'],
      // PR #11 round 4 M1 and the inherited quoted-value finding.
      'password with a quote inside' => ["Pwd: hunter2'mP2q!x"],
      'quoted password with a quote inside' => ['Password: "hunter2"cd9ef" next'],
      'password with a < inside' => ['password=hunter2<3isgreat'],
      'quoted api_key' => ['api_key="hunter2hunter2"'],
      'quoted key param' => ["x key='hunter2hunter2' y"],
    ];
  }

  /**
   * Step 1b, the gate (PR #11 round 4): text that carries a form-login token, an authx or form
   * parameter, or a checksum is withheld whole, however it is split, quoted or wrapped.
   *
   * @dataProvider gated
   */
  public function testGateWithholdsCredentialBearingText(string $in): void {
    $this->assertTrue(TextSanitiser::carriesCredential($in));
    $this->assertNull(TextSanitiser::sanitise($in));
  }

  public function gated(): array {
    $link = 'https://example.org/civicrm/mas-checkin?_aff=Bearer+' . self::AFF_JWT;
    $hash = '0123456789abcdef0123456789abcdef';
    return [
      'form link, percent-encoded' => ['Go to ' . rawurlencode($link)],
      // A token inside a link AND anywhere else: not the exempt shape.
      'form link also pasted as text' => ['<a href="' . $link . '">Open</a> or paste ' . $link],
      // A decoded quote ends the attribute early, so the token's tail is outside it.
      'encoded quote breaks the attribute' => ['<a href="https://example.org/x?q=&quot;' . $link . '">x</a>'],
      // The link holds the header; the payload is outside it.
      'token split out of the link' => ['<a href="https://example.org/x?_aff=Bearer+eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9">x</a>.eyJleHAiOjE3OTAwMDAwMDB9.c2ln'],
      // PR #14 review M1: header and payload in the link, the signature (no marker) outside it.
      'signature in the link text' => ['<a href="https://example.org/x?_aff=Bearer+eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJleHAiOjE3OTAwMDAwMDB9.">Xk3Qa7Lm2c2lnb mF0dXJlaGVyZW xvbmdlcg9Z_w-8vT</a>'],
      // PR #14 round 2 M-A: a link holding only the parameter, the secret outside it.
      'authx key outside the link' => ['<a href="https://example.org/x?_authx=">Open</a> zq9Kp3Lm8Vx2Rt7Wn4Bc6Yh1Jd5Fs0Ag'],
      'aff parameter without its token' => ['<a href="https://example.org/x?_aff=Bearer+">Open</a> zq9Kp3Lm8Vx2Rt7Wn4Bc6Yh1Jd5Fs0Ag'],
      'authx inside a link even whole' => ['<a href="https://example.org/x?_authx=Bearer+' . self::AFF_JWT . '">Open</a>'],
      'signature after the link' => ['<a href="https://example.org/x?_aff=Bearer+eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJleHAiOjE3OTAwMDAwMDB9.Xk3Qa">x</a><br>7Lm2c2lnbmF0<br>dXJlaGVyZWxvbmdlcg9Z'],
      'Afform JWT alone' => ['token ' . self::AFF_JWT],
      'header split by a newline' => ["x eyJ0eX\nAiOiJKV1Qi rest"],
      'header split by a quote prefix' => ["x eyJ0e\n> XAiOiJKV1Qi rest"],
      'claims split by a QP soft break' => ["x eyJleH=\r\nAiOjE3 rest"],
      'claims split by a tag' => ['x eyJle<wbr>HAiOjE3 rest'],
      'claims split by a space' => ['x eyJle HAiOjE3 rest'],
      'authx parameter in prose' => ['use _authx=zq8zq8zq8 now'],
      'aff parameter wrapped' => ["https://example.org/x?a=1&\n_aff=zq9zq9zq9zq9 thanks"],
      'checksum on its own line' => ["https://example.org/civicrm/profile/edit?reset=1&id=5&\ncs={$hash}_1700000000_168"],
      'checksum split by a tag' => ['code 0123456789abcdef<wbr>0123456789abcdef_1700000000_168 end'],
      'bare checksum' => ["checksum {$hash}_1700000000_inf please"],
      // PR #11 round 5 M1: splits the first two copies keep (the third copy's job).
      'header split by comments' => ['x eyJ0<!-- a -->eXAi<!-- b -->OiJK rest'],
      'header split by a tag with > in an attribute' => ['x eyJ0<span title="a>b">eXAiOiJK</span> rest'],
      'header split by pipes' => ['x eyJ0 | eXAi | OiJK rest'],
      'aff split by commas' => ['x _a,ff,=,zq9zq9 rest'],
      // PR #14 round 3 L: whitespace inside a quoted attribute splits a parameter name for the link check.
      'authx split by a space inside the link' => ['<a href="https://example.org/x?_au thx=Bearer+' . self::AFF_JWT . '">Open</a>'],
      'authx split by a newline inside the link' => ["<a href=\"https://example.org/x?_auth\nx=zq9Kp3Lm8Vx2Rt7Wn4Bc6Yh1\">Open</a>"],
    ];
  }

  /** The exempt shape survives the whitespace squash: `Bearer`, `Bearer+` and a decoded `%20`. */
  public function testFormLinkSeparatorsStayExempt(): void {
    foreach (['Bearer+', 'Bearer%20', 'Bearer', ''] as $sep) {
      $in = '<p>Hi. <a href="https://example.org/civicrm/mas-checkin?_aff=' . $sep . self::AFF_JWT . '&amp;cid=5">Open</a></p>';
      $out = TextSanitiser::sanitise($in);
      $this->assertNotNull($out, "separator '$sep'");
      $this->assertStringContainsString(TextSanitiser::LINK, $out);
      $this->assertNoCredential($out);
    }
  }

  /**
   * Brian, 2026-09-30: VCs may read the full message; only the working login link goes. A token
   * found only inside link attributes is removed with them, and the text comes back.
   */
  public function testFormLinkInsideAnHrefIsRemovedAndTheMessageKept(): void {
    $link = 'https://example.org/civicrm/mas-checkin?_aff=Bearer+' . self::AFF_JWT;
    foreach (['<p>Hi Pat. Please complete the form: <a href="' . $link . '">Open the form</a>. Thanks!</p>',
      "<p>Hi Pat.</p><p><a href='" . $link . "'>Open</a></p><p>See <a href=\"" . $link . "\">again</a></p>",
      '<a href=' . $link . '>Open</a> thanks'] as $in) {
      $out = TextSanitiser::sanitise($in);
      $this->assertNotNull($out, $in);
      $this->assertStringContainsString(TextSanitiser::LINK, $out);
      $this->assertFalse(TextSanitiser::carriesCredential($out));
      $this->assertNoCredential($out);
    }
    $this->assertSame('<p>Hi Pat. Please complete the form: <a ' . TextSanitiser::LINK . '>Open the form</a>. Thanks!</p>',
      TextSanitiser::sanitise('<p>Hi Pat. Please complete the form: <a href="' . $link . '">Open the form</a>. Thanks!</p>'));
  }

  /** The gate does not fire on prose that merely squashes into a marker-like run. */
  public function testGateSparesProse(): void {
    foreach (['Hey John, they just met.', 'Thank you — see pat@example.invalid', 'The affiliate = partner', 'Bearer bonds 2024',
      'StrategicPlan2026FinalDraftV2.docx', '3f2504e0-4f89-11d3-9a0c-0305e82c3301'] as $in) {
      $this->assertFalse(TextSanitiser::carriesCredential($in), $in);
    }
  }

  /** Each of these is caught by exactly one rule, so each rule is pinned by a test. */
  public function testRulesThatOtherRulesWouldMask(): void {
    // Only the step-3 detectors run on the rejoined copy see an (ungated) JWT split by <wbr>.
    $out = TextSanitiser::sanitise('tok eyJmb28iOiJ4<wbr>In0.eyJiYXIiOjF9.c2lnbmF0dXJl end');
    $this->assertTrue($out === NULL || !str_contains($out, 'c2lnbmF0dXJl'), 'split JWT withheld or removed');
    // A credential parameter wrapped onto its own line, with a value no other rule knows.
    $out = TextSanitiser::sanitise("https://example.org/x?a=1&\ncs=zq9zq9zq9zq9 thanks");
    $this->assertNotNull($out);
    $this->assertStringNotContainsString('zq9zq9zq9zq9', $out);
  }

  private static function b64(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
  }

  /** A realistic JWT the gate does not know: long, random-looking payload and signature. */
  private static function realisticJwt(string $sig = 'synthetic signature'): string {
    return self::b64('{"foo":"RS256","k":1}') . '.'
      . self::b64('{"afformArgs":{"case_id":678},"x":"https://example.org","y":"cid:12345","z":1790000000}')
      . '.' . self::b64(hash('sha512', $sig, TRUE) . hash('sha256', 'more', TRUE));
  }

  /** PR #11 round 2 H2: a bare Bearer token hard-wrapped at 76, joined by any separator. */
  public function testHardWrappedTokenLeavesNoUsablePiece(): void {
    $jwt = self::realisticJwt();
    $pieces = str_split('Authorization: Bearer ' . $jwt, 76);
    foreach (["\n", ' ', '<br>', '</p><p>', "\t", '<o:p></o:p>', '<ins>', "\u{2063}"] as $sep) {
      $out = TextSanitiser::sanitise(implode($sep, $pieces));
      // Space- and block-tag-joined pieces are the random-token rule's: redacted, not withheld.
      if (in_array($sep, [' ', '<br>', '</p><p>', "\t"], TRUE)) {
        $this->assertNotNull($out, 'separator ' . json_encode($sep));
      }
      if ($out === NULL) {
        $this->addToAssertionCount(1);
        continue;
      }
      foreach (str_split(str_replace('.', '', $jwt), 24) as $chunk) {
        if (strlen($chunk) === 24) {
          $this->assertStringNotContainsString($chunk, $out, 'separator ' . json_encode($sep));
        }
      }
    }
  }

  /**
   * PR #11 rounds 3-4 H1/H2: hard wraps at common widths, many offsets, every separator a mail
   * client or a quoted reply produces, with many random 43-character HS256 signatures.
   * Afform-shaped tokens are withheld by the gate; tokens the gate does not know must still leave
   * no 12 signature characters together (the fragment rules).
   */
  public function testWrapSweepLeavesNoSignaturePiece(): void {
    $separators = [' ', "\t", "\n", "\r\n", "<br>\n", '<br>', '</p><p>', "\u{00A0}", "\n> ", "\n> > ", "=\r\n", "\u{2028}"];
    $afformPayload = self::b64('{"exp":1790000000,"sub":"cid:12345","scope":"afform","afform":"afformMASVcCheckin","afformArgs":{"case_id":678}}');
    $otherPayload = self::b64('{"afformArgs":{"case_id":678},"x":"cid:12345","y":1790000000}');
    $leaks = 0;
    $runs = 0;
    for ($k = 0; $k < 40; $k++) {
      $sig = self::b64(hash_hmac('sha256', "synthetic $k", 'key', TRUE));
      $tokens = [
        'gated' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.' . $afformPayload . '.' . $sig,
        'other' => self::b64('{"foo":"HS256"}') . '.' . $otherPayload . '.' . $sig,
      ];
      foreach ($tokens as $kind => $jwt) {
        foreach (['Authorization: Bearer ', 'Open https://example.org/civicrm/x?token=Bearer+'] as $lead) {
          foreach ([64, 72, 76, 78] as $width) {
            for ($pad = $k % 5; $pad < $width; $pad += 5) {
              $pieces = str_split(str_repeat('x', $pad) . $lead . $jwt, $width);
              foreach ($separators as $sep) {
                $runs++;
                $out = TextSanitiser::sanitise(implode($sep, $pieces));
                if ($kind === 'gated') {
                  $this->assertNull($out, 'the gate withholds an Afform token in any wrap');
                  continue;
                }
                if ($out === NULL) {
                  continue;
                }
                for ($i = 0; $i + 12 <= 43; $i++) {
                  if (str_contains($out, substr($sig, $i, 12))) {
                    $leaks++;
                    break;
                  }
                }
              }
            }
          }
        }
      }
    }
    $this->assertGreaterThan(50000, $runs);
    $this->assertSame(0, $leaks, "signature pieces survived in $leaks of $runs wraps");
  }

  /** PR #11 round 4 M1: a password value keeps going past an inner quote or a non-tag `<`. */
  public function testPasswordValueWithQuotesOrLessThan(): void {
    foreach (["Pwd: Xk9'mP2q!x next" => 'mP2q', 'Password: "ab"cd9ef" next' => 'cd9ef', 'password=hunter2<3isgreat next' => '3isgreat',
      // PR #11 round 5 M2.
      'Password: ab>cd9 next' => 'cd9', 'Password: "correct horse battery" next' => 'horse', 'Password: ab&gt;cd9 next' => 'cd9',
      // PR #11 round 6 M-B.
      'Password: "correct horse battery". next' => 'horse', 'Password: "correct horse battery")! next' => 'horse',
      'Password: “correct horse battery” next' => 'horse', 'Password: ‘correct horse’ next' => 'horse', 'Password: «correct horse» next' => 'horse',
      '<i title="Password: \'correct horse\'">next</i>' => 'horse', '<x password="correct horse"/>next' => 'horse'] as $in => $rest) {
      $out = TextSanitiser::sanitise($in);
      $this->assertNotNull($out, $in);
      $this->assertStringNotContainsString($rest, $out, $in);
      $this->assertStringContainsString('next', $out, $in);
    }
  }

  /**
   * T10 (mascode PR #70 round 2 M-a): markdown emphasis, backticks or curly quotes around the
   * keyword, or emphasis after the separator, do not hide the value. Values carry no digit, so the
   * sticky rule cannot be what removes them.
   */
  public function testPasswordAfterMarkdownOrCurlyQuotes(): void {
    foreach (['**Password**: tiger ok', '__Password__: tiger ok', '*Password*: tiger ok', '`password`: tiger ok', '“Password”: tiger ok',
      '‘pwd’ = tiger ok', '«passcode»: tiger ok', '**Password:** tiger ok', '__Password:__ tiger ok', '**PIN**: tiger ok', '~~pw~~: tiger ok',
      // PR #73 round 1 M1, M2.
      '__PIN__: tiger ok', '_pin_ = tiger ok', '__PW__: tiger ok', '“Password:” tiger ok', 'Password:” tiger ok', '**“Password:”** tiger ok',
      '"Password:" tiger ok', "'Password:' tiger ok"] as $in) {
      $out = TextSanitiser::sanitise($in);
      $this->assertNotNull($out, $in);
      $this->assertStringNotContainsString('tiger', $out, $in);
      $this->assertStringContainsString('ok', $out, $in);
    }
    // Prose around the words is unchanged.
    foreach (['Reset your **password** today', 'The *pin* on the map', 'password_hint: the dog', 'Spin: fast', '| Password | secret |'] as $in) {
      $this->assertSame($in, TextSanitiser::sanitise($in), $in);
    }
    // A quoted value is still taken whole, not cut at the space.
    // PR #73 round 2 M-1, L-a: also with a space just inside the opening quote.
    foreach (['Password: "tiger lily" ok', 'Password: " tiger lily " ok', 'Password:" tiger lily" ok', "Password: ' tiger lily' ok", '"Password:" "tiger lily" ok'] as $in) {
      $out = TextSanitiser::sanitise($in);
      $this->assertNotNull($out, $in);
      $this->assertStringNotContainsString('lily', $out, $in);
      $this->assertStringContainsString('ok', $out, $in);
    }
    // Documented over-reach (review L4): a label with emphasis before a colon redacts the next word.
    $this->assertSame('the *' . TextSanitiser::REDACTED, TextSanitiser::sanitise('the *pin*: note'));
  }

  /** PR #11 round 4 H1: a short payload tail before the signature's dot does not shield the signature. */
  public function testShortPayloadTailIsBridged(): void {
    $out = TextSanitiser::sanitise(TextSanitiser::REDACTED . "\nAb3.c2lnbmF0dXJl9Xk3Q end");
    $this->assertNotNull($out);
    $this->assertStringNotContainsString('c2lnbmF0dXJl', $out);
  }

  /** PR #11 rounds 4-5: the sticky rule keeps email addresses and a word ending a sentence. */
  public function testStickyRuleKeepsEmailAddresses(): void {
    $this->assertSame(TextSanitiser::LINK . ' jsmith2024@example.invalid', TextSanitiser::sanitise('https://example.org/a jsmith2024@example.invalid'));
    $this->assertSame('see ' . TextSanitiser::LINK . ' Jonathan2.Smithers2026@example.invalid', TextSanitiser::sanitise('see https://example.org/x Jonathan2.Smithers2026@example.invalid'));
    $this->assertSame('Register at ' . TextSanitiser::LINK . ' here.', TextSanitiser::sanitise('Register at https://example.org/x here.'));
  }

  /** PR #11 round 4 L3: a letter-only Basic credential of token length is still redacted. */
  public function testLetterOnlyBasicCredential(): void {
    $out = TextSanitiser::sanitise('Authorization: Basic QWxhZGRpbkBvcGVuc2VzYW1l');
    $this->assertNotNull($out);
    $this->assertStringNotContainsString('QWxhZGRpbkBvcGVuc2VzYW1l', $out);
  }

  /** The sticky rule leaves prose after a removed link alone. */
  public function testStickyRuleSparesProse(): void {
    $this->assertSame('See ' . TextSanitiser::LINK . ' Thanks everyone, regards', TextSanitiser::sanitise('See https://example.org/x Thanks everyone, regards'));
    $this->assertSame('Basic Understanding of budgets', TextSanitiser::sanitise('Basic Understanding of budgets'));
  }

  /** PR #11 round 3 L2: a password in an attribute does not eat the visible text after it. */
  public function testPasswordInAttributeKeepsVisibleText(): void {
    $out = TextSanitiser::sanitise('<span title="pwd=abc123">visible</span>');
    $this->assertNotNull($out);
    $this->assertStringNotContainsString('abc123', $out);
    $this->assertStringContainsString('visible', $out);
  }

  /** PR #11 round 2 M1: a password split by markup is redacted whole. */
  public function testPasswordSplitByMarkupIsRedactedWhole(): void {
    foreach (['Password: hun<span class=SpellE>ter2</span> ok', 'Password: hun<wbr>ter2 ok', 'Password: hun<!-- -->ter2 ok', 'Password: <b>hun</b>ter2 ok'] as $in) {
      $out = TextSanitiser::sanitise($in);
      $this->assertNotNull($out, $in);
      $this->assertStringNotContainsString('ter2', $out, $in);
      $this->assertStringContainsString('ok', $out, $in);
    }
  }

  /** PR #11 round 2 M3 / L1: Bearer as the spec now states it, and _authx outside a URL. */
  public function testBearerAndAuthxOutsideAUrl(): void {
    foreach (['Authorization: Bearer abc123def456', 'Bearer: 0123456789abcdef0123', 'use key=Basic+aHVudGVyMjpodW50ZXIy now', 'use h=zq8zq8zq8 now'] as $in) {
      $out = TextSanitiser::sanitise($in);
      $this->assertNotNull($out, $in);
      foreach (['abc123def456', '0123456789abcdef0123', 'aHVudGVyMjpodW50ZXIy', 'zq8zq8zq8'] as $secret) {
        $this->assertStringNotContainsString($secret, $out, $in);
      }
    }
  }

  /** PR #11 round 2 H1: invisible characters of every kind are dropped before detection. */
  public function testEveryInvisibleIsDropped(): void {
    foreach (["\u{2063}", "\u{180E}", "\u{034F}", "\u{FE0F}", "\u{200E}", "\u{202A}", "\u{00AD}", "\u{2060}"] as $ch) {
      $in = 'tok eyJmb28i' . $ch . 'OiJ4In0.eyJiYXIiOjF9.c2lnbmF0dXJl end';
      // Dropped in step 1, the token rejoins and is redacted: cleaned, not withheld.
      $out = TextSanitiser::sanitise($in);
      $this->assertNotNull($out, json_encode($ch));
      $this->assertNoCredential($out);
    }
  }

  /** Step 4 alone refuses a random-looking piece (pins the check separately from the redaction). */
  public function testStepFourRefusesARandomPiece(): void {
    $this->assertFalse(TextSanitiser::isClean('piece c2lnbmF0dXJlaGVyZWxvbmdlcg9Xk3Qa7Lm2 end'));
    $this->assertTrue(TextSanitiser::isClean('StrategicPlan2026FinalDraftV2 end'));
    // … and a token-like piece next to a redaction (the sticky rule's step-4 check).
    $this->assertFalse(TextSanitiser::isClean(TextSanitiser::REDACTED . ' Ab3dE5fG7h end'));
    $this->assertTrue(TextSanitiser::isClean(TextSanitiser::REDACTED . ' Thanks everyone'));
  }

  /** The random-token rule leaves ordinary identifiers alone. */
  public function testIdentifiersAreNotRandomTokens(): void {
    foreach (['StrategicPlan2026FinalDraftV2.docx', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'MAS-SR-2026-0045 and MAS-P-2026-0112',
      'Board_Minutes_2026_09_Approved_Final'] as $in) {
      $this->assertSame($in, TextSanitiser::sanitise($in), $in);
    }
  }

  /** PR #11 review L3: prose around the redaction words stays readable. */
  public function testProseIsNotOverRedacted(): void {
    foreach (['the bearer of this letter', 'Pinned the notes', 'a spinning wheel: yes', 'Version 1.2.3.4 shipped', 'Budget < 500 and > 100'] as $in) {
      $this->assertSame($in, TextSanitiser::sanitise($in), $in);
    }
  }

  /** PR #11 review M3: every pathological input finishes quickly (linear patterns, input cap). */
  public function testPathologicalInputIsFast(): void {
    $half = intdiv(TextSanitiser::MAX_INPUT, 2);
    $inputs = [
      str_repeat('pwd:x<i></i> ', intdiv($half, 13)),
      str_repeat('<!--x', intdiv($half, 5)) . '-->',
      str_repeat('<!--mas-', intdiv($half, 8)),
      str_repeat('eyJ', intdiv($half, 3)),
      str_repeat('a', $half - 1) . '.',
      str_repeat('?a=', intdiv($half, 3)),
      str_repeat('Password: ', intdiv($half, 10)),
      str_repeat('%25', intdiv($half, 3)),
      // PR #11 round 2, item 5.
      str_repeat('url(', intdiv($half, 4)),
      str_repeat('](', intdiv($half, 2)),
      str_repeat('a+', intdiv($half, 2)) . ':',
      str_repeat('<!--mas-', intdiv($half, 8)) . '-->',
      '-->' . str_repeat('<!--', intdiv($half, 4)),
      str_repeat('a.', intdiv($half, 2)),
      'password' . str_repeat(' ', $half - 9),
      // PR #73 round 1 L1: the widened password gaps.
      'password' . str_repeat('*_', intdiv($half, 2)),
      str_repeat('pwd**', intdiv($half, 5)),
      'pin:' . str_repeat('*', $half - 5),
      'password:' . str_repeat('” ', intdiv($half, 4)),
      str_repeat('pin: “', intdiv($half, 8)),
      str_repeat('<b>', intdiv($half, 3)),
      str_repeat('Ab1', intdiv($half, 3)),
      // PR #11 round 6 M-A: a long dot-joined chain after a removed link.
      'https://example.org/x ' . str_repeat('Abcdefg1.', intdiv(TextSanitiser::MAX_INPUT - 100, 9)),
      'https://example.org/x ' . str_repeat('a.Abcdefg1.', intdiv(TextSanitiser::MAX_INPUT - 100, 11)),
    ];
    foreach ($inputs as $i => $in) {
      $t = microtime(TRUE);
      TextSanitiser::sanitise($in);
      $this->assertLessThan(1.0, microtime(TRUE) - $t, "input #$i");
    }
  }

  public function testOversizedInputIsWithheld(): void {
    $this->assertNull(TextSanitiser::sanitise(str_repeat('a', TextSanitiser::MAX_INPUT + 1)));
    $this->assertNotNull(TextSanitiser::sanitise(str_repeat('a ', intdiv(TextSanitiser::MAX_INPUT, 2))));
  }

  public function testContactDetailsInTextAreKept(): void {
    // D21: email addresses and phone numbers written into case history are allowed.
    $in = 'Call Pat on 416-555-0100 or write to pat@example.invalid about the 1.5/2 split.';
    $this->assertSame($in, TextSanitiser::sanitise($in));
  }

  /** @dataProvider urls */
  public function testEveryUrlIsRemoved(string $in, string $expected): void {
    $this->assertSame($expected, TextSanitiser::sanitise($in));
  }

  public function urls(): array {
    $l = TextSanitiser::LINK;
    return [
      'https' => ['see https://example.org/a/b.', "see $l"],
      'other scheme' => ['ftp://files.example.org/x', $l],
      'www' => ['visit www.example.org today', "visit $l today"],
      'domain with path' => ['at example.org/page now', "at $l now"],
      'img src' => ['<img src="https://example.org/p.png">', "<img $l>"],
      'query token' => ['open index.php?id=4 please', "open $l please"],
      // PR #11 review L2.
      'localhost' => ['dev at localhost:8080/x/y ok', "dev at $l ok"],
      'IP with path' => ['at 10.0.0.5/civicrm/x ok', "at $l ok"],
      'protocol-relative' => ['see //example.org/x now', "see $l now"],
      'srcset' => ['<img srcset="/a.png 2x">', "<img $l>"],
      'css url' => ['<div style="background:url(/a.png)">', '<div style="background:' . $l . '">'],
      'markdown' => ['[form](/civicrm/x)', '[form' . $l],
      'JSON-escaped scheme' => ['{"u":"https:\\/\\/example.org\\/x"}', '{"u":"' . $l . '"}'],
    ];
  }

  public function testReplacementTokensAreClean(): void {
    foreach ([TextSanitiser::LINK, TextSanitiser::REDACTED] as $token) {
      $this->assertTrue(TextSanitiser::isClean($token));
      $this->assertSame($token, TextSanitiser::sanitise($token));
    }
  }

  public function testNormalisedTextIsReturned(): void {
    $this->assertSame('Tom & Jerry say "hi"', TextSanitiser::sanitise('Tom &amp; Jerry say &quot;hi&quot;'));
    $this->assertSame('50% off', TextSanitiser::sanitise('50%25 off'));
  }

  public function testEncodingThatNeverSettlesIsWithheld(): void {
    $in = 'x';
    for ($i = 0; $i < TextSanitiser::MAX_PASSES + 1; $i++) {
      $in = rawurlencode($in) . '%25';
    }
    $this->assertNull(TextSanitiser::sanitise(str_repeat('%25', 1) . $in));
  }

  public function testInvalidUtf8IsWithheld(): void {
    $this->assertNull(TextSanitiser::sanitise('bad %C3%28 byte'));
    $this->assertNull(TextSanitiser::sanitise("bad \xC3\x28 byte"));
  }

  public function testTrimmedToMaxLength(): void {
    $out = TextSanitiser::forVc(str_repeat('a', 1500));
    $this->assertSame(TextSanitiser::MAX_LENGTH, mb_strlen($out));
    $this->assertSame('short', TextSanitiser::forVc('short'));
  }

  public function testACutThatOpensACommentIsWithheld(): void {
    // A closed, non-mascode comment is allowed; cutting inside it leaves one unterminated.
    $in = str_repeat('a', TextSanitiser::MAX_LENGTH - 5) . '<!-- a staff note -->';
    $this->assertSame($in, TextSanitiser::sanitise($in));
    $this->assertNull(TextSanitiser::forVc($in));
  }

  /** Sanitising sanitised output changes nothing, and never withholds it (§3). */
  public function testIdempotent(): void {
    $parts = ['text ', self::JWT, ' https://example.org/p?token=Bearer+', '&amp;', '%2B', '%25', '<b>', '</b>',
      'Password: ', 'x ', '<!--mas-lifecycle {}-->', '<!-- note -->', 'www.example.org', '?cs=1', ' Bearer ',
      '&nbsp;', "é ", 'pat@example.invalid ', '416-555-0100 ', '<a href="x">', '"password":"y"', 'a/b ',
      // PR #73 round 1 L2.
      '**', '__', '`', '“', '”', 'PIN', '~~'];
    mt_srand(20260929);
    $checked = 0;
    for ($n = 0; $n < 500; $n++) {
      $in = '';
      for ($k = mt_rand(1, 12); $k > 0; $k--) {
        $in .= $parts[mt_rand(0, count($parts) - 1)];
      }
      $once = TextSanitiser::sanitise($in);
      if ($once === NULL) {
        continue;
      }
      $this->assertTrue(TextSanitiser::isClean($once), "clean after one pass: $in");
      $this->assertSame($once, TextSanitiser::sanitise($once), "idempotent: $in");
      $checked++;
    }
    // PR #11 review L5: the property must actually be exercised, not skipped on NULL.
    $this->assertGreaterThan(400, $checked);
  }

}
