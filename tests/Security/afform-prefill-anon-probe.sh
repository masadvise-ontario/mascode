#!/usr/bin/env bash
#
# Afform public-form prefill guard — anonymous probe.
#
# Task: #159. Guard: Civi/Mascode/Event/AfformPublicArgGuardSubscriber.php
#
# WHAT THIS IS
# The original exploit, kept runnable. An unauthenticated POST to
# civicrm/ajax/api4/Afform/prefill used to return real CiviCRM data for any
# caller-supplied case_id or contact_id, on all seven MAS public forms — no
# cookie, no session, no _aff token. Case ids are sequential integers, so
# iterating them harvested every project's client feedback; on afformMASRCSForm
# a single contact_id walked out to the employer organisation and then to that
# organisation's President and Executive Director.
#
# WHY A SHELL SCRIPT AND NOT PHPUnit
# The vulnerability is defined by the absence of a session, and `cv scr` always
# runs as some user, so the anonymous case cannot be asserted in-process at all.
# Only a real HTTP request with no cookie jar proves it. The authenticated
# half — that entitled VCs keep working — is asserted by the sibling
# AfformPublicArgGuardTest.php, and the pure filtering rules run in CI via
# tests/Unit/Security/AfformArgPolicyTest.php.
#
# SAFE AGAINST PRODUCTION
# Every request is an Afform.prefill, which only reads. Nothing is created,
# updated or deleted, and no id is written anywhere. It is intended to be run
# against production after deploying this guard, to confirm the hole is shut
# there and not only on dev.
#
# USAGE
#   tests/Security/afform-prefill-anon-probe.sh [BASE_URL] [CASE_ID] [CONTACT_ID]
#
#   # dev (self-signed cert; -k is applied automatically for *.localhost)
#   tests/Security/afform-prefill-anon-probe.sh
#
#   # production, with a case id and contact id known to exist
#   tests/Security/afform-prefill-anon-probe.sh https://www.masadvise.org 18832 2
#
# Pick ids that DO exist, or a pass proves nothing: a probe for a nonexistent
# case returns "(nothing)" whether or not the guard is in place. The script says
# so in its output rather than letting a vacuous pass look like a real one.
#
# EXIT 0 = no data leaked. EXIT 1 = at least one form returned a record.

set -uo pipefail

BASE_URL="${1:-https://masdemo.localhost}"
CASE_ID="${2:-13306}"
CONTACT_ID="${3:-2}"

ENDPOINT="${BASE_URL%/}/civicrm/ajax/api4/Afform/prefill"

# Dev runs on a self-signed certificate; do not weaken TLS for real hosts.
CURL_OPTS=(-s --max-time 30)
case "$BASE_URL" in
  *localhost*|*.local*) CURL_OPTS+=(-k) ;;
esac

FORMS=(
  afformMASProjectDefinitionClient
  afformMASProjectDefinitionVC
  afformMASRCSForm
  afformMASSASF
  afformMASSASS
  afformProjectCloseClientFeedback
  afformProjectCloseVCFeedback
)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
BODY="$TMP/body.json"

leaks=0
checks=0

# Print which entities came back carrying a real record id, or "(nothing)".
summarise() {
  python3 - "$BODY" <<'PY'
import json, sys
try:
    with open(sys.argv[1]) as fh:
        data = json.load(fh)
except Exception:
    with open(sys.argv[1]) as fh:
        print("UNPARSEABLE: " + fh.read()[:100].replace("\n", " "))
    sys.exit(0)

values = data.get("values")
if not isinstance(values, list):
    print("ERROR: " + str(data.get("error_message"))[:80])
    sys.exit(0)

loaded = []
for item in values:
    name = item.get("name")
    for row in (item.get("values") or []):
        row = row or {}
        # fillMode=form / entity: the record lands under "fields".
        fields = row.get("fields") or {}
        if fields.get("id"):
            loaded.append("%s#%s" % (name, fields["id"]))
        # fillMode=join: the record lands under "joins", NOT "fields". Missing
        # this is how a join-mode leak reads as a clean pass.
        for join_name, join_rows in (row.get("joins") or {}).items():
            for join_row in (join_rows or []):
                if (join_row or {}).get("id"):
                    loaded.append("%s.%s#%s" % (name, join_name, join_row["id"]))
print(", ".join(sorted(loaded)) if loaded else "(nothing)")
PY
}

# probe FORM LABEL FILLMODE ARGS_JSON
probe() {
  local form="$1" label="$2" fill_mode="$3" args_json="$4"
  local params="{\"name\":\"$form\",\"fillMode\":\"$fill_mode\",\"args\":$args_json}"

  # No -b/-c: no cookie jar, so the request carries no session whatsoever.
  local code
  code="$(curl "${CURL_OPTS[@]}" -o "$BODY" -w '%{http_code}' \
    -X POST "$ENDPOINT" \
    -H 'X-Requested-With: XMLHttpRequest' \
    --data-urlencode "params=$params")"

  local result verdict
  result="$(summarise)"
  checks=$((checks + 1))

  if [ "$result" = "(nothing)" ]; then
    verdict="pass"
  elif [ "${result:0:5}" = "ERROR" ] || [ "${result:0:12}" = "UNPARSEABLE" ]; then
    # A refusal is not a leak. Report it plainly rather than scoring it.
    verdict="info"
  else
    verdict="LEAK"
    leaks=$((leaks + 1))
  fi

  printf '  %-4s %-34s %-22s HTTP %-3s %s\n' \
    "$verdict" "$form" "$label" "$code" "$result"
}

echo "Anonymous Afform.prefill probe — no cookie, no session, no _aff token"
echo "  endpoint:   $ENDPOINT"
echo "  case_id:    $CASE_ID"
echo "  contact_id: $CONTACT_ID"
echo
echo "NOTE: a pass only means something if these ids exist. Verify first, e.g."
echo "      cv api4 CiviCase.get '{\"where\":[[\"id\",\"=\",$CASE_ID]]}'"
echo

for form in "${FORMS[@]}"; do
  # --- fillMode=form: the five autofill id args. ---------------------------
  probe "$form" "case_id"     form "{\"case_id\":$CASE_ID}"
  probe "$form" "contact_id"  form "{\"contact_id\":$CONTACT_ID}"
  # Inert on today's forms (no MAS entity declares an Activity autofill mode),
  # probed so that adding one and reopening the hole shows up here.
  probe "$form" "activity_id" form '{"activity_id":1}'

  # --- Entity-named args. --------------------------------------------------
  # Inert because core only honours them when the matched field carries an
  # `autofill` input attribute and no MAS form declares an `id` field. That is
  # a property of the FORMS, not of the guard, so a form edit could reinstate
  # it — and the guard does not cover these names.
  probe "$form" "Case1 (entity-named)" form "{\"Case1\":$CASE_ID}"

  # --- fillMode=join / entity: the SECOND disclosure. ----------------------
  # These load a record from arbitrary caller-supplied field values with no id
  # and no scoping to a parent record, so none of the five names appears and key
  # filtering cannot see them. Anonymously, before they were blocked, the join
  # probe below returned a real client street address; the same shape returned
  # Email (an email-existence oracle) and Phone.
  probe "$form" "join Address.city" join \
    '{"Organization1":[{"joins":{"Address":[{"city":"Toronto"}]}}]}'
  probe "$form" "join Email.email" join \
    '{"Individual1":[{"joins":{"Email":[{"is_primary":true}]}}]}'
  probe "$form" "entity Case1.id" entity "{\"Case1\":{\"0\":{\"id\":$CASE_ID}}}"
done

echo
if [ "$leaks" -gt 0 ]; then
  echo "FAILED — $leaks of $checks probes returned data anonymously."
  exit 1
fi
echo "OK — $checks probes, no data returned anonymously."
exit 0
