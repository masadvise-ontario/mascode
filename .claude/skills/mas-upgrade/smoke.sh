#!/usr/bin/env bash
# Before/after smoke test for the MAS WordPress + CiviCRM stack (see SKILL.md).
#
# USAGE
#   smoke.sh dev|prod mark     # record log offsets + version snapshot (run BEFORE upgrading)
#   smoke.sh dev|prod check    # run every check, print PASS/FAIL summary + new log errors
#
# What it does to prod: reads, plus writes that are rolled back or are cache refreshes —
#   - System.check stores its result; `wp plugin/theme list --update=available` refreshes the
#     update transients.
#   - AfformPublicArgGuardTest, LifecycleTransitionTemplatesTest, check-vc-scope-searches are
#     read-only; VcDigestIdempotencyTest writes an activity inside a transaction it always rolls
#     back and sends no mail (see each file's docblock).
#   - It never runs `cv upgrade:db` (even --dry-run rebuilds triggers/managed entities and resets
#     the upgrade queue) and never `cv flush`.
# The dev-only checks (CaseDetailAccess, VcCheckinPage, CheckinEntitlement, ClientRepChange,
# RcsChaseArming, test-vc-scope-searches, unit tests) write inside rolled-back transactions and
# may fire CiviRules mail, which on dev lands in MailHog.
#
# Exit 0 = all PASS; 1 = at least one FAIL. A FAIL that was also present in the pre-upgrade run
# (same state dir, run `check` once right after `mark`) is a pre-existing condition, not a regression.
#
# ENV
#   PROD_STAFF_LOGIN     prod only, required: an admin user_login with a civicrm_uf_match row
#   SMOKE_ALLOW_PENDING  space-separated plugin/theme slugs deliberately held back this round
#   CHECK_CONTACT_IDS    run check-vc-scope-searches with these ids (dev defaults to 3 = test.vc)
#   PROBE_CASE / PROBE_CONTACT  ids that EXIST on the target, for the anon Afform probe
#   SMOKE_STATE          state dir (default: $CLAUDE_JOB_DIR/tmp or $TMPDIR, one per target)

set -uo pipefail
TARGET=${1:?dev|prod}; MODE=${2:-check}
if [[ -n ${SMOKE_STATE:-} ]]; then STATE=$SMOKE_STATE
else STATE=${CLAUDE_JOB_DIR:+$CLAUDE_JOB_DIR/tmp}; STATE=${STATE:-${TMPDIR:-/tmp}}/mas-upgrade-smoke-$TARGET; fi
ALLOW_PENDING=${SMOKE_ALLOW_PENDING-}
mkdir -p "$STATE"

if [[ $TARGET == dev ]]; then
  ROOT=/home/brian/buildkit/build/masdemo/web
  BASE=https://masdemo.localhost
  CV="/home/brian/buildkit/bin/cv"
  STAFF=brian.flett@masadvise.org
  run() { (cd "$ROOT" && bash -c "set -o pipefail; $1"); }
else
  ROOT=/home/mas/web/masadvise.org/public_html
  BASE=https://www.masadvise.org
  CV="$ROOT/bin/cv"
  STAFF=${PROD_STAFF_LOGIN:?set PROD_STAFF_LOGIN to a prod admin user_login with a uf_match row}
  run() { ssh mas-prod "cd $ROOT && export HOME=/home/mas/tmp && set -o pipefail && $1"; }
fi
EXT=wp-content/uploads/civicrm/ext/mascode
WPLOG=wp-content/log/wp-debug.log
CIVILOG_GLOB='wp-content/uploads/civicrm/ConfigAndLog/CiviCRM.*.log'

logsize() { run "for f in $WPLOG \$(ls -t $CIVILOG_GLOB 2>/dev/null | head -1); do [ -f \$f ] && echo \"\$f \$(wc -c < \$f)\"; done; true"; }

snapshot() {
  run "wp core version; wp plugin list --fields=name,status,version --format=csv; wp theme list --fields=name,status,version --format=csv; $CV ext:list -Li --columns=key,version 2>/dev/null"
}

if [[ $MODE == mark ]]; then
  logsize > "$STATE/logoffsets"
  snapshot > "$STATE/versions-before.txt"
  echo "marked: $(wc -l < "$STATE/versions-before.txt") version lines, offsets in $STATE/logoffsets"
  exit 0
fi

declare -a RESULTS; FAILS=0
record() { RESULTS+=("$(printf '%-4s %s' "$1" "$2")"); [[ $1 == FAIL ]] && FAILS=$((FAILS+1)); }
# chk NAME COMMAND [BAD-REGEX that must NOT appear] [GOOD-REGEX that MUST appear]
chk() {
  local name=$1 cmd=$2 bad=${3:-} good=${4:-} out rc f
  f="$STATE/out-${name//[^A-Za-z0-9]/_}.txt"
  out=$(run "$cmd" 2>&1); rc=$?
  echo "$out" > "$f"
  # Under WordPress maintenance mode cv prints the "Briefly unavailable" page and exits 0, so
  # that page fails every check, whatever its own patterns say.
  if [[ $rc -ne 0 ]] || grep -q 'Briefly unavailable' <<<"$out" || { [[ -n $bad ]] && grep -qiE "$bad" <<<"$out"; } \
     || { [[ -n $good ]] && ! grep -qE "$good" <<<"$out"; }; then
    record FAIL "$name (rc=$rc) — see $f"
  else record PASS "$name"; fi
}

# 1. Versions after vs before
if [[ -f $STATE/versions-before.txt ]]; then
  snapshot > "$STATE/versions-after.txt"
  diff "$STATE/versions-before.txt" "$STATE/versions-after.txt" > "$STATE/versions.diff"
  record INFO "version changes: $(grep -c '^>' "$STATE/versions.diff" || true) lines (see $STATE/versions.diff)"
else
  record FAIL "no 'mark' state in $STATE — run '$0 $TARGET mark' before upgrading"
fi

# 2. Core health
# Code version = DB version and no extension upgrade pending. This is the check that catches new
# code on an un-upgraded DB (the 2026-09-29 incident). It must print OK: under WordPress
# maintenance mode cv prints the "Briefly unavailable" page and exits 0, which must not pass.
chk "CiviCRM code = DB, no extension upgrade pending" \
  "$CV ev 'if (CRM_Utils_System::version() !== CRM_Core_BAO_Domain::version() || CRM_Extension_Upgrades::hasPending()) { echo \"PENDING code=\", CRM_Utils_System::version(), \" db=\", CRM_Core_BAO_Domain::version(), \" ext=\", var_export(CRM_Extension_Upgrades::hasPending(), true); exit(1); } echo \"OK \", CRM_Utils_System::version();'" \
  "PENDING|Briefly unavailable" "^OK [0-9]"
chk "System.check (no error/critical)" "$CV api4 System.check '{\"select\":[\"name\",\"severity_id\"],\"where\":[[\"severity_id\",\">=\",4]]}' --out=json" '"name"' '^\['
# A removed readme.html or an extra file in the web root is not a modified core file; a checksum
# mismatch is, and so is an added file under wp-admin/ or wp-includes/ (the usual webshell spot).
chk "wp core verify-checksums (no modified core files, nothing added in wp-admin/wp-includes)" "wp core verify-checksums 2>&1; true" "File doesn't verify against checksum|File should not exist: (wp-admin|wp-includes)/" "[Vv]erif"
# Premium/in-house plugins have no wp.org checksums (skipped). "File is missing" is upstream
# packaging noise (W3TC ships without its CI files); a mismatch or an added file is not.
chk "wp plugin verify-checksums (no modified/added plugin files)" \
  "wp plugin verify-checksums --all --strict 2>&1; true" "Checksum does not match|File was added" "[Vv]erified"
chk "no pending plugin/theme updates${ALLOW_PENDING:+ (except: $ALLOW_PENDING)}" \
  "p=\$(wp plugin list --update=available --field=name 2>/dev/null) && t=\$(wp theme list --update=available --field=name 2>/dev/null) || { echo 'wp failed'; exit 3; }; for s in \$p \$t; do case ' $ALLOW_PENDING ' in *\" \$s \"*) echo \"held back: \$s\";; *) echo \"PENDING: \$s\";; esac; done; echo checked" \
  "^PENDING:" "^checked$"
chk "API4 smoke read (Case + Contact)" "$CV api4 CiviCase.get '{\"select\":[\"id\"],\"limit\":1}' --user=$STAFF --out=list && $CV api4 Contact.get '{\"select\":[\"id\"],\"limit\":1}' --user=$STAFF --out=list" "" "^[0-9]+$"
chk "scheduled jobs listed (Job.get)" "$CV api4 Job.get '{\"select\":[\"name\",\"last_run\"],\"where\":[[\"is_active\",\"=\",true]]}' --out=table" "" "last_run"
chk "CiviRules active rules > 0" "$CV api4 CiviRulesRule.get '{\"select\":[\"id\"],\"where\":[[\"is_active\",\"=\",true]]}' --out=list" "" "^[0-9]+$"

# 3. HTTP — public pages and forms must render without PHP errors
for p in / /vcportal/ /wp-login.php /civicrm/mas-rcs-form/ /civicrm/mas-sasf-form/ /civicrm/mas-sass-form/ /civicrm/mas-checkin-all/ /civicrm/mas-pdef-client/; do
  body=$(curl -sk -L --max-time 60 -w '\n%{http_code}' "$BASE$p"); code=${body##*$'\n'}
  if [[ $code != 200 ]] || grep -qiE 'critical error|Fatal error|Uncaught|Briefly unavailable' <<<"$body"; then
    record FAIL "HTTP $p -> $code"; else record PASS "HTTP $p -> 200"; fi
done

# 4. mascode security + live tests
VC=$(run "$CV api4 UFMatch.get '{\"select\":[\"uf_name\"],\"join\":[[\"RelationshipCache AS rc\",\"INNER\",[\"rc.near_contact_id\",\"=\",\"contact_id\"]]],\"where\":[[\"rc.near_relation:name\",\"=\",\"Case Coordinator is\"],[\"rc.is_current\",\"=\",true],[\"rc.case_id\",\"IS NOT NULL\"]],\"groupBy\":[\"uf_name\"],\"limit\":1}' --out=list" 2>/dev/null | grep -m1 '@')
if [[ $TARGET == prod && -z ${PROBE_CASE:-} ]]; then
  record INFO "anon probe uses its default ids — a pass is only meaningful if they exist on prod (set PROBE_CASE/PROBE_CONTACT)"
fi
chk "anon Afform.prefill probe (no leak)" "bash $EXT/tests/Security/afform-prefill-anon-probe.sh $BASE ${PROBE_CASE:-} ${PROBE_CONTACT:-}"
chk "LifecycleTransitionTemplatesTest" "$CV scr $EXT/tests/Live/LifecycleTransitionTemplatesTest.php --user=$STAFF"
chk "VcDigestIdempotencyTest" "$CV scr $EXT/tests/Live/VcDigestIdempotencyTest.php --user=$STAFF"
if [[ -n ${CHECK_CONTACT_IDS:-} || $TARGET == dev ]]; then
  chk "check-vc-scope-searches" "CHECK_CONTACT_IDS=${CHECK_CONTACT_IDS:-3} $CV scr $EXT/scripts/check-vc-scope-searches.php --user=$STAFF"
else record INFO "check-vc-scope-searches skipped (set CHECK_CONTACT_IDS)"; fi
if [[ -z $VC ]]; then
  record FAIL "no current VC login found — VC-scoped security tests not run"
else
  record INFO "VC login for security tests: $VC"
  chk "AfformPublicArgGuardTest" "$CV scr $EXT/tests/Security/AfformPublicArgGuardTest.php --user=$VC"
  if [[ $TARGET == dev ]]; then
    chk "CheckinEntitlementTest" "$CV scr $EXT/tests/Security/CheckinEntitlementTest.php --user=$VC"
    chk "VcCheckinPageTest" "$CV scr $EXT/tests/Security/VcCheckinPageTest.php --user=$VC"
  fi
fi
if [[ $TARGET == dev ]]; then
  chk "CaseDetailAccessTest" "$CV scr $EXT/tests/Security/CaseDetailAccessTest.php"
  chk "ClientRepChangeTest" "cd $EXT && $CV scr tests/Live/ClientRepChangeTest.php --user=$STAFF"
  chk "RcsChaseArmingTest" "cd $EXT && $CV scr tests/Live/RcsChaseArmingTest.php --user=$STAFF"
  chk "test-vc-scope-searches (rolled back)" "cd $EXT && $CV scr scripts/test-vc-scope-searches.php --user=$STAFF"
  chk "mascode unit tests" "cd $EXT && ./vendor/bin/phpunit --testsuite=unit 2>&1 | tail -5" "(FAILURES|ERRORS!)" "^OK[ ,]"
fi

# 5. New PHP / CiviCRM errors since mark. Fatal/uncaught = FAIL; warnings/[error] = INFO,
# because the negative-path tests above deliberately log [error] lines, and Elementor logs
# background_image warnings on every page view (pre-existing, 2026-09-29).
if [[ -s $STATE/logoffsets ]]; then
  : > "$STATE/new-log-errors.txt"
  cp "$STATE/logoffsets" "$STATE/logscan"
  newest=$(run "ls -t $CIVILOG_GLOB 2>/dev/null | head -1; true" | tail -1)
  if [[ -n $newest ]] && ! grep -q "^$newest " "$STATE/logscan"; then
    echo "$newest 0" >> "$STATE/logscan"
    record INFO "CiviCRM log rotated since mark — also scanning $newest from the start"
  fi
  while read -r f off; do
    now=$(run "wc -c < $f 2>/dev/null || echo -1" | tail -1)
    if [[ $now -lt $off ]]; then record FAIL "log $f shrank or vanished since mark (rotated?) — read it by hand"; continue; fi
    run "tail -c +$((off+1)) $f | grep -E 'PHP Fatal|Uncaught|PHP Warning|PHP Deprecated|\\[error\\]|\\[critical\\]|\\[alert\\]' | grep -v 'auto_detect_line_endings'; true" >> "$STATE/new-log-errors.txt"
  done < "$STATE/logscan"
  nf=$(grep -cE 'PHP Fatal|Uncaught|\[critical\]|\[alert\]' "$STATE/new-log-errors.txt" || true)
  nw=$(grep -c . "$STATE/new-log-errors.txt" || true)
  if [[ $nf -gt 0 ]]; then record FAIL "fatal/critical log lines since mark: $nf"; else record PASS "no fatal/critical log lines since mark ($(wc -l < "$STATE/logoffsets") log file(s) scanned)"; fi
  record INFO "all warning/error log lines since mark: $nw — distinct: $(sed -E 's/^\[[^]]*\] //; s/^[0-9-]+ [0-9:+-]+ +//' "$STATE/new-log-errors.txt" | sort -u | wc -l) (see $STATE/new-log-errors.txt)"
else
  record FAIL "no log offsets in $STATE (no 'mark', or no log files found) — log check not run"
fi

echo; echo "=== SMOKE $TARGET $(date '+%F %T') ==="; printf '%s\n' "${RESULTS[@]}"
echo "FAILS=$FAILS"; exit $(( FAILS > 0 ))
