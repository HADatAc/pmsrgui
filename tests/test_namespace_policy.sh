#!/usr/bin/env bash
set -u

BASE_URL="${PMSR_TEST_HASCOAPI_BASE:-http://127.0.0.1:9001/hascoapi/api}"
APPROVAL_TOKEN="${PMSR_TEST_NS_APPROVAL_TOKEN:-${HASCOAPI_NAMESPACE_APPROVAL_TOKEN:-}}"

pass=0
fail=0
skip=0

ok() {
  echo "[PASS] $*"
  pass=$((pass + 1))
}

bad() {
  echo "[FAIL] $*"
  fail=$((fail + 1))
}

warn() {
  echo "[SKIP] $*"
  skip=$((skip + 1))
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1"
    exit 2
  fi
}

http_call() {
  # Usage: http_call METHOD URL BODY HEADER1 HEADER2 ...
  local method="$1"
  local url="$2"
  local body="$3"
  shift 3

  local tmp
  tmp="$(mktemp)"
  local status

  if [[ "$method" == "GET" ]]; then
    status="$(curl -s -o "$tmp" -w "%{http_code}" -X GET "$url" "$@")"
  else
    status="$(curl -s -o "$tmp" -w "%{http_code}" -X "$method" "$url" --data "$body" "$@")"
  fi

  local response
  response="$(cat "$tmp")"
  rm -f "$tmp"

  printf '%s\n' "$status"
  printf '%s' "$response"
}

require_cmd curl
require_cmd jq

echo "Namespace policy regression"
echo "BASE_URL=$BASE_URL"

# 1) Read endpoint must remain open.
read_raw="$(http_call "GET" "$BASE_URL/repo/table/namespaces" "")"
read_status="$(printf '%s' "$read_raw" | head -n 1)"
read_body="$(printf '%s' "$read_raw" | tail -n +2)"

if [[ "$read_status" == "200" ]] && printf '%s' "$read_body" | jq -e '.isSuccessful == true and (.body|type=="array")' >/dev/null 2>&1; then
  ok "Namespace read endpoint is available"
else
  bad "Namespace read endpoint failed (HTTP $read_status)"
fi

# 2) Update endpoint must be blocked without approval headers.
nohdr_raw="$(http_call "GET" "$BASE_URL/repo/namespace/default/policytest/http%3A%2F%2Fpolicy.test%2Font%2F/_/_" "")"
nohdr_status="$(printf '%s' "$nohdr_raw" | head -n 1)"
if [[ "$nohdr_status" == "403" ]]; then
  ok "Default namespace update blocked without approval headers"
else
  bad "Default namespace update should be forbidden without headers (HTTP $nohdr_status)"
fi

if [[ -z "$APPROVAL_TOKEN" ]]; then
  warn "Approval token not provided (set PMSR_TEST_NS_APPROVAL_TOKEN or HASCOAPI_NAMESPACE_APPROVAL_TOKEN)"
  warn "Skipping allowlist-specific checks"
else
  change_id="policy-test-$(date +%s)"

  # 3) Unauthorized component must be blocked.
  unauth_raw="$(http_call "GET" "$BASE_URL/repo/namespace/default/policytest/http%3A%2F%2Fpolicy.test%2Font%2F/_/_" "" -H "X-Namespace-Approval: $APPROVAL_TOKEN" -H "X-Change-Id: $change_id-unauth" -H "X-Namespace-Component: unauthorized-component")"
  unauth_status="$(printf '%s' "$unauth_raw" | head -n 1)"
  if [[ "$unauth_status" == "403" ]]; then
    ok "Unauthorized component is blocked from namespace update"
  else
    bad "Unauthorized component should be forbidden (HTTP $unauth_status)"
  fi

  # 4) Allowed component: pmsr-config-bootstrap should pass gate on ingest endpoint.
  # We intentionally send empty body to avoid any mutation; success criterion is "not forbidden".
  allow_boot_raw="$(http_call "POST" "$BASE_URL/repo/namespace/ingest/policytest/http%3A%2F%2Fpolicy.test%2Font%2F" "" -H "Content-Type: text/turtle" -H "X-Namespace-Approval: $APPROVAL_TOKEN" -H "X-Change-Id: $change_id-bootstrap" -H "X-Namespace-Component: pmsr-config-bootstrap")"
  allow_boot_status="$(printf '%s' "$allow_boot_raw" | head -n 1)"
  if [[ "$allow_boot_status" != "403" ]]; then
    ok "Allowed component pmsr-config-bootstrap passes namespace gate"
  else
    bad "Allowed component pmsr-config-bootstrap was blocked"
  fi

  # 5) Allowed component: pmsr-ingest-ontologies should pass gate.
  allow_ing_raw="$(http_call "POST" "$BASE_URL/repo/namespace/ingest/policytest/http%3A%2F%2Fpolicy.test%2Font%2F" "" -H "Content-Type: text/turtle" -H "X-Namespace-Approval: $APPROVAL_TOKEN" -H "X-Change-Id: $change_id-ingest" -H "X-Namespace-Component: pmsr-ingest-ontologies")"
  allow_ing_status="$(printf '%s' "$allow_ing_raw" | head -n 1)"
  if [[ "$allow_ing_status" != "403" ]]; then
    ok "Allowed component pmsr-ingest-ontologies passes namespace gate"
  else
    bad "Allowed component pmsr-ingest-ontologies was blocked"
  fi

  # 6) Allowed component: semantic-repository-settings should pass gate.
  # We intentionally send empty body to avoid any mutation; success criterion is "not forbidden".
  allow_sem_raw="$(http_call "POST" "$BASE_URL/repo/namespace/ingest/policytest/http%3A%2F%2Fpolicy.test%2Font%2F" "" -H "Content-Type: text/turtle" -H "X-Namespace-Approval: $APPROVAL_TOKEN" -H "X-Change-Id: $change_id-semantic" -H "X-Namespace-Component: semantic-repository-settings")"
  allow_sem_status="$(printf '%s' "$allow_sem_raw" | head -n 1)"
  if [[ "$allow_sem_status" != "403" ]]; then
    ok "Allowed component semantic-repository-settings passes namespace gate"
  else
    bad "Allowed component semantic-repository-settings was blocked"
  fi

  # 7) Allowed component: rep-map-entrypoints should pass gate for hasco namespace only.
  # Use hasco target to satisfy component-specific server-side restriction.
  allow_map_raw="$(http_call "POST" "$BASE_URL/repo/namespace/ingest/hasco/http%3A%2F%2Fhadatac.org%2Font%2Fhasco%2F" "" -H "Content-Type: text/turtle" -H "X-Namespace-Approval: $APPROVAL_TOKEN" -H "X-Change-Id: $change_id-map" -H "X-Namespace-Component: rep-map-entrypoints")"
  allow_map_status="$(printf '%s' "$allow_map_raw" | head -n 1)"
  if [[ "$allow_map_status" != "403" ]]; then
    ok "Allowed component rep-map-entrypoints passes namespace gate for hasco"
  else
    bad "Allowed component rep-map-entrypoints was blocked for hasco"
  fi
fi

echo

echo "Summary: passed=$pass failed=$fail skipped=$skip"

if (( fail > 0 )); then
  exit 1
fi

exit 0
