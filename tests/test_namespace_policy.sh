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
fi

echo

echo "Summary: passed=$pass failed=$fail skipped=$skip"

if (( fail > 0 )); then
  exit 1
fi

exit 0
