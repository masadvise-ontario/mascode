<?php

namespace Civi\Mascode\Mcp\Vc;

/**
 * The sanitiser every string `vc_query` returns passes through (docs/plans/vc-activity-policy.md §3;
 * VC access spec D20, D26, D37). Tokenised form links (`_aff=Bearer …`) and checksum links are credentials
 * to act as someone else, and staff can paste them anywhere, so no URL and no credential pattern may
 * reach a volunteer consultant from any entity. Removing them at the source (T22) was dropped (D37), so
 * this is the only control.
 *
 * Steps, in order:
 *  0. Refuse input over MAX_INPUT bytes (NULL), so no pattern below can be made slow.
 *  1. Normalise: decode HTML entities and percent-encoding until the text stops changing (at most
 *     MAX_PASSES; still changing, or not valid UTF-8, means NULL), then drop invisible characters
 *     (soft hyphen, zero-width, BOM) that could split a token. The normalised text is returned.
 *  1b. Gate: NULL if a squashed copy holds a CiviCRM credential marker (carriesCredential()) —
 *      unless every marker sits inside a link attribute, which step 2 removes whole (Brian,
 *      2026-09-30: VCs may read the full message; only the working login link must go).
 *  2. Remove every URL, whatever its host or scheme, as LINK.
 *  3. Redact credential patterns anywhere, as REDACTED.
 *  4. Fail closed: NULL if the gate or any step-3 detector still matches — on the text itself AND on a
 *     "rejoined" copy with tags, line breaks and quoted-printable soft breaks removed, so a token
 *     split by `<wbr>` or a wrapped line (whose first half step 2 took as a URL) cannot pass as
 *     harmless fragments (PR #11 review H1, M2).
 * forVc() then trims to MAX_LENGTH and runs step 4 again, because a cut can open a comment.
 *
 * Contact details in text are allowed (D21): email addresses and phone numbers are not redacted.
 * Every pattern is linear in the input (anchored or possessive; comments found with strpos).
 * Pure; unit-tested in tests/Unit/TextSanitiserTest.php, including idempotence and timing.
 */
final class TextSanitiser {

  public const LINK = '[link removed]';
  public const REDACTED = '[redacted]';
  public const MAX_PASSES = 10;
  public const MAX_LENGTH = 1000;
  /** Activity details on masdemo peak at 12 KB; anything far larger is withheld, not scanned. */
  public const MAX_INPUT = 65536;

  /**
   * A link-bearing attribute and its whole value. Step 2 removes these, and step 1b exempts a
   * credential that is a whole token inside one — the same pattern, so every attribute the
   * exemption relies on is one that step 2 removes.
   */
  private const LINK_ATTRIBUTE = '/\b(?:href|src|srcset|action|formaction|data-href|poster|background)\s*=\s*(?:"[^"]*+"|\'[^\']*+\'|[^\s>]++)/i';

  /**
   * A complete JWT: three segments, a signature of at least 40 characters (HS256 is 43), and then
   * the end of a URL value.
   */
  private const WHOLE_JWT = '/(?<![\w-])eyJ[\w-]*+\.[\w-]++\.[\w-]{40,}+(?=[&#"\'\s>]|$)/';

  /**
   * A form-login parameter carrying a whole JWT — the only JWT shape a link may hold (D28). Matched
   * on a link with its whitespace removed (so `Bearer` and the token may be joined), after the
   * normaliser has percent-decoded it (so no `%20`).
   */
  private const WHOLE_AFF = '/_aff=(?:Bearer\+?)?eyJ[\w-]*+\.[\w-]++\.[\w-]{40,}+(?=[&#"\'>]|$)/i';

  /** Anything that is not a delimiter of a URL or query-string value in text or markup. */
  private const URL = '[^\s"\'<>]';

  /** Format characters (soft hyphen, zero-width, bidi marks, BOM …) and other invisibles. */
  private const INVISIBLE = '/[\p{Cf}\x{034F}\x{115F}\x{1160}\x{180E}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}]/u';

  /**
   * The gate (step 1b): the start of a JWT header or claims object — base64url of `{"typ"`, `{"alg"`,
   * `{"exp"`, `{"sub"`, `{"iss"`, `{"scope"`, `{"kid"`, `{"nbf"`, `{"iat"`, `{"jti"`, `{"aud"` — a
   * form-login or authx parameter, or a CiviCRM checksum. Matched on squashed copies of the text,
   * so no wrap, quote prefix, soft break or tag can split it. On masdemo it matches exactly the 292
   * fields that hold a real form-login token, and nothing else.
   */
  private const CREDENTIAL = '/eyJ(?:0eXAi|hbGci|leHAi|zdWIi|pc3Mi|zY29wZSI|raWQi|uYmYi|pYXQi|qdGki|hdWQi)|_aff=|_authx=|[0-9a-f]{32}_\d{6,}_(?:\d+|inf)/i';

  /** Tokens for the sticky walk: a marker, a base64url run, separators, or any other character. */
  private const STICKY_TOKENS = '/(\[redacted\]|\[link removed\])|([\w-]++)|((?:[\s\p{Z}.>=]|<[^<>]*+>)++)|(.)/su';

  /** Tags that sit inside a word or a token (so removing them rejoins it). */
  private const INLINE_TAG = '/<\/?(?:wbr|span|a|b|i|u|s|em|strong|font|code|small|sup|sub|mark|ins|del|abbr|label|[a-z]+:[a-z0-9]+)\b[^<>]*>/i';

  /** A base64url run long enough to be a token piece (see randomTokens()). */
  private const TOKEN_RUN = '/(?<![\w-])[\w-]{24,}+(?![\w-])/';

  /** Real tags only; a stray `<` (`a < b`) is text (review M1). */
  private const TAG = '/<\/?[a-zA-Z][^<>]*>/';

  /**
   * Plaintext passwords and similar secrets. Run on the text AND on a tag-blanked copy, so
   * `<strong>Password:</strong> x` and `<span title="Password: x">` are both found. Markdown
   * emphasis, backticks and curly quotes may sit around the keyword and after the separator
   * (`**Password**: x`, `“Password”: x`, `**Password:** x`, `__PIN__: x`; T10, mascode PR #70 round 2
   * M-a), and a quote closing just after the separator (`“Password:” x`) when a space follows it (past any emphasis), so
   * a quoted value (`"tiger lily"`) is still taken whole. `pin` / `pw` need no letter or digit
   * before them, so `_PIN_` counts and "spin" does not.
   */
  private const PASSWORD = '/(?:password|passwd|pwd|passcode|(?<![\p{L}\p{N}])(?:pin|pw)|api_?key|access_token)(?:["\'“”‘’«»*_`~]|[\s\p{Z}])*+[:=：](?:[\s\p{Z}*_`~]|["\'”’»](?=[*_`~]*+[\s\p{Z}]))*+(?:(?:"[^"\n]++"|\'[^\'\n]++\'|“[^”\n]++”|‘[^’\n]++’|«[^»\n]++»)(?!\w)|["\']?(?:[^\s\p{Z}<"\']|["\'](?![\s\p{Z}>]|$)|<(?![a-zA-Z\/!][^\s<>]*+>))+)/iu';

  /** @return string[] step-3 detectors that redact in place; also part of the step-4 check */
  private static function detectors(): array {
    return [
      // Bearer / Basic credentials, first so the whole pair goes. A token-shaped value only, so
      // "the bearer of this letter" is prose.
      // The value must carry a digit or be token-length (review round 2 M3); spec §3 says so.
      '/Bearer[\s+:]*+(?=\S*\d|\S{16})\S++/i',
      // base64 of "user:pass" carries a digit, + or / ("Basic Understanding of budgets" does not).
      '/\bBasic[\s+]++(?=[A-Za-z0-9+\/]*[0-9+\/]|[A-Za-z0-9+\/]{16})[A-Za-z0-9+\/]{12,}+={0,2}/',
      // JWTs (the `_aff` form-login tokens are JWTs). Anchored at the start of a run: linear.
      '/(?<![\w-])eyJ[\w-]++\.[\w-]++\.[\w-]++/',
      // Credential parameters in a query-string position or at the start of a line (a wrapped
      // URL, review M2): the whole name=value pair.
      '/(?:(?<=[?&;\s])|^)(?:_aff|_authx|cs|h|key|api_key)=(?:"[^"]*+"|\'[^\']*+\'|[^&#\s"\'<]*+)/mi',
      // A CiviCRM contact checksum, wherever it appears: hash_timestamp_(lifetime|inf).
      '/\b[0-9a-f]{32}_\d{6,}_(?:\d+|inf)\b/i',
    ];
  }

  /**
   * Extra step-4 checks on the rejoined copy only: the remains of a split token. A JWT payload
   * and signature are each dozens of base64url characters; prose words are not.
   *
   * @return string[]
   */
  private static function residual(): array {
    return [
      '/eyJ[\w-]{8,}/',
      '/(?<![\w-])[\w-]{20,}+\.[\w-]{20,}/',
    ];
  }

  /** Sanitise for a VC and trim to MAX_LENGTH; NULL means the field is withheld. */
  public static function forVc(string $text): ?string {
    $clean = self::sanitise($text);
    if ($clean === NULL || mb_strlen($clean) <= self::MAX_LENGTH) {
      return $clean;
    }
    $cut = mb_substr($clean, 0, self::MAX_LENGTH);
    return self::isClean($cut) ? $cut : NULL;
  }

  /** Steps 0-4, no trimming. */
  public static function sanitise(string $text): ?string {
    if (strlen($text) > self::MAX_INPUT) {
      return NULL;
    }
    $text = self::normalise($text);
    // Step 1b: text that carries a credential anywhere, in any split, is withheld whole — the
    // fragment rules below are the second layer, for tokens the gate does not know.
    if ($text === NULL || (self::carriesCredential($text) && !self::credentialsOnlyInLinks($text))) {
      return NULL;
    }
    $text = self::removeUrls($text);
    $text = $text === NULL ? NULL : self::redact($text);
    return $text !== NULL && self::isClean($text) ? $text : NULL;
  }

  /**
   * TRUE when every credential is a whole token inside a link attribute: removing the attributes
   * leaves no marker, and no attribute holds only part of a token. That is the mascode emails'
   * shape (every one on masdemo). A token anywhere else — pasted as text, wrapped, or partly
   * outside its link — is not exempt, and the field is withheld.
   */
  private static function credentialsOnlyInLinks(string $text): bool {
    $withoutLinks = preg_replace(self::LINK_ATTRIBUTE, '', $text);
    if ($withoutLinks === NULL || self::carriesCredential($withoutLinks)
      || preg_match_all(self::LINK_ATTRIBUTE, $text, $links) === FALSE) {
      return FALSE;
    }
    // Each credential must be WHOLE inside its link: a link holding only a token's start, or only a
    // parameter name, with the secret outside it, is not this shape (PR #14 review M1, round 2 M-A).
    // So every `_aff=` must carry a whole JWT, no other JWT may be partial, and `_authx=` (whose
    // value may be an API key or Basic credentials) is never exempt.
    foreach ($links[0] as $link) {
      // As the gate sees it: whitespace inside a quoted attribute must not split a parameter name
      // (`_au thx=`) past these checks (PR #14 round 3, L).
      $link = preg_replace('/[\s\p{Z}]++/u', '', $link);
      $affs = preg_match_all('/_aff=/i', $link);
      $whole = preg_match_all(self::WHOLE_AFF, $link);
      if ($link === NULL || stripos($link, '_authx=') !== FALSE || $affs === FALSE || $whole === FALSE || $affs !== $whole) {
        return FALSE;
      }
      $rest = preg_replace([self::WHOLE_AFF, self::WHOLE_JWT], '', $link);
      if ($rest === NULL || str_contains($rest, 'eyJ')) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /** Step 1b: TRUE when a squashed copy of the text holds a known credential marker. */
  public static function carriesCredential(string $text): bool {
    // Three squashed copies; any failed replacement is NULL, which counts as carrying a credential.
    $squash = fn(?string $t): ?string => $t === NULL ? NULL : preg_replace(['/=\r?\n/', '/[\s\p{Z}>]++/u'], '', $t);
    $squash3 = fn(?string $t): ?string => $t === NULL ? NULL : preg_replace('/[^\w=]++/u', '', $t);
    $copies = [
      // Tags kept: a token inside an href.
      $squash($text),
      // Tags removed (before squashing, which removes the `>` that closes them): a token split by one.
      $squash(preg_replace('/<\/?[a-zA-Z][^<>]*+>/', '', $text)),
      // Comments and quote-aware tags removed, then every character but word characters and `=`:
      // a token split by anything else — `<!-- -->`, `<span title="a>b">`, pipes, commas (round 5).
      $squash3(preg_replace(['/<!--[\s\S]*?-->/', '/<\/?[a-zA-Z](?:"[^"]*+"|\'[^\']*+\'|[^<>"\'])*+>/', '/=\r?\n/'], '', $text)),
    ];
    foreach ($copies as $copy) {
      if ($copy === NULL || preg_match(self::CREDENTIAL, $copy) !== 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /** Step 4: TRUE when no detector matches the text or its rejoined copy. */
  public static function isClean(string $text): bool {
    $rejoined = self::rejoin($text);
    if ($rejoined === NULL || self::carriesCredential($text)) {
      return FALSE;
    }
    foreach ([$text, $rejoined] as $copy) {
      foreach (self::detectors() as $re) {
        if (preg_match($re, $copy) !== 0) {
          return FALSE;
        }
      }
      if (stripos($copy, '<!--mas-') !== FALSE || self::unterminatedComment($copy) !== FALSE) {
        return FALSE;
      }
      $view = self::passwordView($copy);
      if ($view === NULL || preg_match(self::PASSWORD, $copy) !== 0 || preg_match(self::PASSWORD, $view[0]) !== 0
        || self::randomTokens($copy) !== $copy
        // Sticky on the text only: rejoining lines glues ordinary words together ("Ontario" +
        // "Board2026"), and the rejoined copy has the random-token and residual checks.
        || ($copy === $text && self::sticky($copy) !== $copy)) {
        return FALSE;
      }
    }
    foreach (self::residual() as $re) {
      if (preg_match($re, $rejoined) !== 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private static function normalise(string $text): ?string {
    for ($i = 0; $i < self::MAX_PASSES; $i++) {
      $next = rawurldecode(html_entity_decode($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8'));
      if ($next === $text) {
        return mb_check_encoding($text, 'UTF-8') ? preg_replace(self::INVISIBLE, '', $text) : NULL;
      }
      $text = $next;
    }
    return NULL;
  }

  /** NULL when any pattern fails (a PCRE error must never pass text through or blank it). */
  private static function removeUrls(string $text): ?string {
    $u = self::URL;
    $patterns = [
      // Link-bearing attributes, quoted or not, and CSS url(…).
      self::LINK_ATTRIBUTE,
      '/\burl\(\s*[^()]*+\)/i',
      // scheme://… , also JSON-escaped (https:\/\/…)
      "/(?<![a-z0-9+.-])[a-z][a-z0-9+.-]*+:(?:\\\\?\\/){2}$u++/i",
      // protocol-relative //host/…
      "/(?<![\\w:\\/])\\/\\/$u++/",
      // www.…
      "/\\bwww\\.$u++/i",
      // localhost, or an IP address with a port or path
      "/\\blocalhost(?::\\d+)?(?:\\/$u*+)?/i",
      "/\\b\\d{1,3}(?:\\.\\d{1,3}){3}(?::\\d+|\\/)$u*+/",
      // domain.tld/… (the last label has letters, so "1.5/2" is not a link)
      "/(?<![\\w.-])[a-z0-9-]+(?:\\.[a-z0-9-]+)*\\.[a-z]{2,}\\/$u*+/i",
      // markdown link targets ](…)
      '/\]\(\s*[^()\s]++\s*\)/',
      // any other whitespace-delimited token carrying a query string
      '/(?<!\S)\S*?\?[\w-]+=\S*+/',
    ];
    foreach ($patterns as $re) {
      $text = preg_replace($re, self::LINK, $text);
      if ($text === NULL) {
        return NULL;
      }
    }
    return $text;
  }

  private static function redact(string $text): ?string {
    foreach (self::detectors() as $re) {
      $text = preg_replace($re, self::REDACTED, $text);
      if ($text === NULL) {
        return NULL;
      }
    }
    $text = self::redactComments($text);
    $text = self::randomTokens($text);
    // Passwords split by markup (`<b>Password:</b> x`, `Password: hun<span>ter2</span>`), found on
    // a view with tags and comments removed, whose byte map points back into the text; spliced
    // from the end, in one pass …
    $view = self::passwordView($text);
    if ($view === NULL || preg_match_all(self::PASSWORD, $view[0], $m, PREG_OFFSET_CAPTURE) === FALSE) {
      return NULL;
    }
    [, $map] = $view;
    foreach (array_reverse($m[0]) as [$match, $offset]) {
      $start = $map[$offset];
      $end = $map[$offset + strlen($match) - 1] + 1;
      $text = substr($text, 0, $start) . self::REDACTED . substr($text, $end);
    }
    // … then those written inside a comment or an attribute, which blanking hides from nothing
    // but which the text itself shows.
    $text = preg_replace(self::PASSWORD, self::REDACTED, $text);
    // Last, so it follows every redaction made above.
    $text = $text === NULL ? NULL : self::sticky($text);
    return $text !== NULL && mb_check_encoding($text, 'UTF-8') ? $text : NULL;
  }

  /** `<!--mas-…-->` blocks, then any comment left open to the end; strpos only, so linear. */
  private static function redactComments(string $text): string {
    $out = '';
    $at = 0;
    while (($open = stripos($text, '<!--mas-', $at)) !== FALSE) {
      $close = strpos($text, '-->', $open + 8);
      if ($close === FALSE) {
        break;
      }
      $out .= substr($text, $at, $open - $at) . self::REDACTED;
      $at = $close + 3;
    }
    $text = $out . substr($text, $at);
    $open = self::unterminatedComment($text);
    return $open === FALSE ? $text : substr($text, 0, $open) . self::REDACTED;
  }

  /** Offset of the first `<!--` with no `-->` after it, or FALSE. */
  private static function unterminatedComment(string $text): int|false {
    $close = strrpos($text, '-->');
    return strpos($text, '<!--', $close === FALSE ? 0 : $close + 3);
  }

  /**
   * The next (or previous) piece of a token that was cut: a token-like run of 8+ characters next to
   * a redaction or a removed link, with only whitespace, dots, `>` quote marks, `=` soft breaks
   * or tags between, is redacted too. A hard wrap can split a 43-character signature into two pieces under
   * the random-token length (review round 3 H1). Token-like = has a digit and a letter, or an
   * upper-case letter after its first character and a lower-case one ("Thanks" is not; "Meeting2026"
   * is, which after a removed link is an acceptable loss). Linear: one walk each way. NULL on a
   * PCRE error.
   */
  private static function sticky(string $text): ?string {
    if (preg_match_all(self::STICKY_TOKENS, $text, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === FALSE) {
      return NULL;
    }
    // [kind, text]: 1 marker, 2 run, 3 separators, 4 other.
    $toks = [];
    foreach ($m as $t) {
      $kind = isset($t[4]) ? 4 : (isset($t[3]) ? 3 : (isset($t[2]) ? 2 : 1));
      $toks[] = [$kind, $t[0]];
    }
    $tokenish = fn(string $run): bool => strlen($run) >= 8 && ((preg_match('/\d/', $run) && preg_match('/[A-Za-z]/', $run))
      || (preg_match('/^.+[A-Z]/', $run) && preg_match('/[a-z]/', $run)));
    $n = count($toks);
    // Which runs belong to an email address (the run, any `.run` pieces, then `@`), found in one
    // backward pass so the walk stays linear (round 6 M-A).
    $email = array_fill(0, $n, FALSE);
    for ($i = $n - 1; $i >= 0; $i--) {
      if ($toks[$i][0] !== 2) {
        continue;
      }
      $after = $toks[$i + 1][1] ?? '';
      $email[$i] = str_starts_with($after, '@')
        || ($after === '.' && ($toks[$i + 2][0] ?? 0) === 2 && $email[$i + 2]);
    }
    // Forward, then backward: each pass carries "next to a removed piece" across separators. A
    // run is taken if it is token-like, or (forward only) if a dot and a segment-length run follow
    // it: the tail of a payload wrapped onto the signature's line. Email address parts are kept.
    foreach ([[0, $n, 1], [$n - 1, -1, -1]] as [$from, $to, $step]) {
      $near = FALSE;
      for ($i = $from; $i !== $to; $i += $step) {
        [$kind, $tok] = $toks[$i];
        if ($kind === 1) {
          $near = TRUE;
        }
        elseif ($kind === 2) {
          // A short run bridges only when a dot and a segment-length run follow (a payload tail on
          // the signature's line), so "here." at a sentence end is prose.
          $tail = $step === 1 && ($toks[$i + 1][1] ?? '') === '.' && ($toks[$i + 2][0] ?? 0) === 2 && strlen($toks[$i + 2][1]) >= 8;
          $take = $near && !$email[$i] && ($tokenish($tok) || $tail);
          if ($take) {
            $toks[$i] = [1, self::REDACTED];
          }
          $near = $take;
        }
        elseif ($kind === 4) {
          $near = FALSE;
        }
      }
    }
    return implode('', array_column($toks, 1));
  }

  /**
   * The text as a reader sees it, for finding passwords: inline tags and whole comments removed,
   * other tags turned into one space. Returns [view, map] where map[i] is the text's byte offset
   * of view byte i; NULL on a PCRE error.
   *
   * @return array{0: string, 1: int[]}|null
   */
  private static function passwordView(string $text): ?array {
    // Every `<!--` must have a `-->` after it (redactComments() guarantees it), so the lazy match
    // below always ends at the next `-->` and the scan stays linear.
    if (self::unterminatedComment($text) !== FALSE || preg_match_all('/<!--[\s\S]*?-->|<\/?[a-zA-Z][^<>]*>/', $text, $tags, PREG_OFFSET_CAPTURE) === FALSE) {
      return NULL;
    }
    $view = '';
    $map = [];
    $at = 0;
    foreach ($tags[0] as [$tag, $offset]) {
      for ($i = $at; $i < $offset; $i++) {
        $view .= $text[$i];
        $map[] = $i;
      }
      if (!str_starts_with($tag, '<!--') && preg_match(self::INLINE_TAG, $tag) !== 1) {
        $view .= ' ';
        $map[] = $offset;
      }
      $at = $offset + strlen($tag);
    }
    for ($i = $at, $n = strlen($text); $i < $n; $i++) {
      $view .= $text[$i];
      $map[] = $i;
    }
    return [$view, $map];
  }

  /**
   * Redact every base64url run of 24+ characters that looks random: upper case, lower case and
   * digits, switching class often (on at least 40% of its characters, so 10+ times for the shortest;
   * random base64url
   * switches on ~60%, and 98% of random 24-60 character runs pass). That is a token piece whatever
   * split it (a hard wrap, a tag, a space), while CamelCase file names (~34%) and case codes switch
   * too rarely (review round 2 H1, H2).
   */
  private static function randomTokens(string $text): string {
    return preg_replace_callback(self::TOKEN_RUN, function (array $m): string {
      $tok = $m[0];
      if (!preg_match('/[A-Z]/', $tok) || !preg_match('/[a-z]/', $tok) || !preg_match('/\d/', $tok)) {
        return $tok;
      }
      $classes = (string) preg_replace(['/[A-Z]/', '/[a-z]/', '/\d/', '/[_-]/'], ['U', 'L', 'D', ''], $tok);
      $switches = 0;
      for ($i = 1, $n = strlen($classes); $i < $n; $i++) {
        $switches += (int) ($classes[$i] !== $classes[$i - 1]);
      }
      return $switches >= (int) ceil(strlen($tok) * 0.4) ? self::REDACTED : $tok;
    }, $text) ?? self::REDACTED;
  }

  /**
   * Line breaks, quoted-printable soft breaks, INLINE tags and comment markers removed, so a split
   * token rejoins; any other tag (`<br>`, `<p>`, `<td>`) becomes a space, so separate lines of an
   * address block do not run together into one long "token". Splits by a block tag or a space are
   * the random-token rule's to catch.
   */
  private static function rejoin(string $text): ?string {
    return preg_replace(['/=\r?\n/', '/[\r\n\x{2028}\x{2029}\x{0B}\x{0C}]/u', self::INLINE_TAG, '/<!--|-->/', self::TAG], ['', '', '', '', ' '], $text);
  }

}
