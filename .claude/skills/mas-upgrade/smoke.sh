#!/usr/bin/env bash
# Post-upgrade smoke test for the MAS WordPress + CiviCRM stack.
#
# USAGE
#   smoke.sh dev|prod mark     # record log offsets + version snapshot (run BEFORE upgrading)
#   smoke.sh dev|prod check    # run every check, print PASS/FAIL summary + new log errors
#
# Read-only on prod: every prod check is either a read or a script whose docblock says
# it is safe against production (rolled-back transaction, no mail). The dev-only checks
# (CaseDetailAccess, VcCheckinPage, CheckinEntitlement, ClientRepChange, RcsChaseArming,
# test-vc-scope-searches, unit tests) write inside rolled-back transactions and may fire
# CiviRules mail, which on dev lands in MailHog.
#
# Exit 0 = all PASS; 1 = at least one FAIL. A FAIL that was also present in the pre-upgrade run
# (same STATE dir, run `check` once right after `mark`) is a pre-existing condition, not a regression.
#
# Prod needs PROD_STAFF_LOGIN (a prod admin user_login with a uf_match row). Set CHECK_CONTACT_IDS
# to run the VC scope-search check on prod (dev defaults to test.vc, contact 3).

set -uo pipefail
TARGET=${1:?dev|prod}; MODE=${2:-check}
# SMOKE_STATE, if set, is the state dir itself; otherwise one per target under the job/tmp dir.
STATE=${SMOKE_STATE:-${CLAUDE_JOB_DIR:+$CLAUDE_JOB_DIR/tmp}}
[[ -z ${SMOKE_STATE:-} ]] && STATE=${STATE:-${TMPDIR:-/tmp}}/mas-upgrade-smoke-$TARGET
# Plugins deliberately held back this round (space-separated slugs) do not count as pending.
ALLOW_PENDING=${SMOKE_ALLOW_PENDING:-gravityforms}
mkdir -p "$STATE"

if [[ $TARGET == dev ]]; then
  ROOT=/home/brian/buildkit/build/masdemo/web
  BASE=https://masdemo.localhost
  CV="/home/brian/buildkit/bin/cv"
  STAFF=brian.flett@masadvise.org
  run() { (cd "$ROOT" && bash -c "$1"); }
else
  ROOT=/home/mas/web/masadvise.org/public_html
  BASE=https://www.masadvise.org
  CV="HOME=/home/mas/tmp $ROOT/bin/cv"
  STAFF=${PROD_STAFF_LOGIN:?set PROD_STAFF_LOGIN to a prod admin user_login with a uf_match row}
  run() { ssh mas-prod "cd $ROOT && export HOME=/home/mas/tmp && $1"; }
fi
EXT=wp-content/uploads/civicrm/ext/mascode
WPLOG=wp-content/log/wp-debug.log
CIVILOG_GLOB='wp-content/uploads/civicrm/ConfigAndLog/CiviCRM.*.log'

logsize() { run "for f in $WPLOG \$(ls -t $CIVILOG_GLOB 2>/dev/null | head -1); do [ -f \$f ] && echo \"\$f \$(wc -c < \$f)\"; done"; }

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
chk() { # name, command (run on target), [expect-regex that must NOT appear in output]
  local name=$1 cmd=$2 bad=${3:-} out rc
  out=$(run "$cmd" 2>&1); rc=$?
  echo "$out" > "$STATE/out-${name//[^A-Za-z0-9]/_}.txt"
  if [[ $rc -ne 0 ]] || { [[ -n $bad ]] && grep -qiE "$bad" <<<"$out"; }; then
    record FAIL "$name (rc=$rc) — see $STATE/out-${name//[^A-Za-z0-9]/_}.txt"
  else record PASS "$name"; fi
}

# 1. Versions after vs before
snapshot > "$STATE/versions-after.txt"
diff "$STATE/versions-before.txt" "$STATE/versions-after.txt" > "$STATE/versions.diff"
record INFO "version changes: $(grep -c '^>' "$STATE/versions.diff" || true) lines (see $STATE/versions.diff)"

# 2. Core health
chk "civicrm version matches DB" "$CV ev 'echo CRM_Utils_System::version(), \" db=\", CRM_Core_BAO_Domain::version();'"
chk "cv upgrade:db nothing pending" "$CV upgrade:db --dry-run 2>&1" "(pending|Upgrade.*step|will run)"
chk "System.check (no error/critical)" "$CV api4 System.check '{\"where\":[[\"severity_id\",\">=\",4]]}' --out=json" '"name"'
# A removed readme.html (security plugins do that) or an extra file is not a modified core file;
# only a checksum mismatch is.
chk "wp core verify-checksums (no modified core files)" "wp core verify-checksums 2>&1; true" "does not match"
chk "wp plugin verify-checksums (wp.org)" "wp plugin verify-checksums --all --strict 2>&1 | grep -v 'could not be retrieved' | grep -iE 'error|mismatch|should not exist' ; true" "(mismatch|should not exist)"
chk "no pending plugin/theme updates (except: $ALLOW_PENDING)" "{ wp plugin list --update=available --field=name; wp theme list --update=available --field=name; } 2>/dev/null | grep -vxE '${ALLOW_PENDING// /|}'; true" "[a-z]"
chk "API4 smoke read (Case + Contact)" "$CV api4 CiviCase.get '{\"select\":[\"id\"],\"limit\":1}' --user=$STAFF && $CV api4 Contact.get '{\"select\":[\"id\"],\"limit\":1}' --user=$STAFF"
chk "scheduled jobs recent (Job.get)" "$CV api4 Job.get '{\"select\":[\"name\",\"last_run\"],\"where\":[[\"is_active\",\"=\",true]]}' --out=table"
chk "CiviRules rules loaded" "$CV api4 CiviRulesRule.get '{\"select\":[\"name\"],\"where\":[[\"is_active\",\"=\",true]]}' --out=list | wc -l"

# 3. HTTP — public pages and forms must render without PHP errors
for p in / /vcportal/ /wp-login.php /civicrm/mas-rcs-form/ /civicrm/mas-sasf-form/ /civicrm/mas-sass-form/ /civicrm/mas-checkin-all/ /civicrm/mas-pdef-client/; do
  body=$(curl -sk -L --max-time 60 -w '\n%{http_code}' "$BASE$p"); code=${body##*$'\n'}
  if [[ $code != 200 ]] || grep -qiE 'critical error|Fatal error|Uncaught|There has been a critical' <<<"$body"; then
    record FAIL "HTTP $p -> $code"; else record PASS "HTTP $p -> 200"; fi
done

# 4. mascode security + live tests
VC=$(run "$CV api4 UFMatch.get '{\"select\":[\"uf_name\"],\"join\":[[\"RelationshipCache AS rc\",\"INNER\",[\"rc.near_contact_id\",\"=\",\"contact_id\"]]],\"where\":[[\"rc.near_relation:name\",\"=\",\"Case Coordinator is\"],[\"rc.is_current\",\"=\",true],[\"rc.case_id\",\"IS NOT NULL\"]],\"groupBy\":[\"uf_name\"],\"limit\":1}' --out=list" 2>/dev/null | head -1)
record INFO "VC login for security tests: ${VC:-<none found>}"
chk "anon Afform.prefill probe (no leak)" "bash $EXT/tests/Security/afform-prefill-anon-probe.sh $BASE ${PROBE_CASE:-} ${PROBE_CONTACT:-}"
chk "AfformPublicArgGuardTest" "$CV scr $EXT/tests/Security/AfformPublicArgGuardTest.php --user=$VC"
chk "LifecycleTransitionTemplatesTest" "$CV scr $EXT/tests/Live/LifecycleTransitionTemplatesTest.php --user=$STAFF"
chk "VcDigestIdempotencyTest" "$CV scr $EXT/tests/Live/VcDigestIdempotencyTest.php --user=$STAFF"
if [[ -n ${CHECK_CONTACT_IDS:-} || $TARGET == dev ]]; then
  chk "check-vc-scope-searches" "CHECK_CONTACT_IDS=${CHECK_CONTACT_IDS:-3} $CV scr $EXT/scripts/check-vc-scope-searches.php --user=$STAFF"
else record INFO "check-vc-scope-searches skipped (set CHECK_CONTACT_IDS)"; fi
if [[ $TARGET == dev ]]; then
  chk "CheckinEntitlementTest" "$CV scr $EXT/tests/Security/CheckinEntitlementTest.php --user=$VC"
  chk "VcCheckinPageTest" "$CV scr $EXT/tests/Security/VcCheckinPageTest.php --user=$VC"
  chk "CaseDetailAccessTest" "$CV scr $EXT/tests/Security/CaseDetailAccessTest.php"
  chk "ClientRepChangeTest" "cd $EXT && $CV scr tests/Live/ClientRepChangeTest.php --user=$STAFF"
  chk "RcsChaseArmingTest" "cd $EXT && $CV scr tests/Live/RcsChaseArmingTest.php --user=$STAFF"
  chk "test-vc-scope-searches (rolled back)" "cd $EXT && $CV scr scripts/test-vc-scope-searches.php --user=$STAFF"
  chk "mascode unit tests" "cd $EXT && ./vendor/bin/phpunit --testsuite Unit 2>&1 | tail -5" "(FAILURES|ERRORS!)"
fi

# 5. New PHP / CiviCRM errors since mark. Fatal/uncaught = FAIL; warnings/[error] = INFO,
# because the negative-path tests above deliberately log [error] lines, and Elementor logs
# background_image warnings on every page view (pre-existing, 2026-09-29).
if [[ -f $STATE/logoffsets ]]; then
  : > "$STATE/new-log-errors.txt"
  while read -r f off; do
    run "tail -c +$((off+1)) $f 2>/dev/null | grep -E 'PHP Fatal|Uncaught|PHP Warning|PHP Deprecated|\\[error\\]|\\[critical\\]|\\[alert\\]' | grep -v 'auto_detect_line_endings'" >> "$STATE/new-log-errors.txt"
  done < "$STATE/logoffsets"
  nf=$(grep -cE 'PHP Fatal|Uncaught|\[critical\]|\[alert\]' "$STATE/new-log-errors.txt" || true)
  nw=$(grep -c . "$STATE/new-log-errors.txt" || true)
  if [[ $nf -gt 0 ]]; then record FAIL "fatal/critical log lines since mark: $nf"; else record PASS "no fatal/critical log lines since mark"; fi
  record INFO "all warning/error log lines since mark: $nw — distinct: $(sed -E 's/^\[[^]]*\] //; s/^[0-9-]+ [0-9:+-]+ +//' "$STATE/new-log-errors.txt" | sort -u | wc -l) (see $STATE/new-log-errors.txt)"
fi

echo; echo "=== SMOKE $TARGET $(date '+%F %T') ==="; printf '%s\n' "${RESULTS[@]}"
echo "FAILS=$FAILS"; exit $(( FAILS > 0 ))
