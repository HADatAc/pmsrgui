#!/usr/bin/env bash
set -u

DRUPAL_BASE="${PMSR_TEST_DRUPAL_BASE:-http://127.0.0.1:8080}"
FUSEKI_QUERY="${PMSR_TEST_FUSEKI_QUERY:-http://127.0.0.1:3030/store/query}"

pass=0
fail=0

ok() {
  echo "[PASS] $*"
  pass=$((pass + 1))
}

bad() {
  echo "[FAIL] $*"
  fail=$((fail + 1))
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1"
    exit 2
  fi
}

http_get_retry() {
  local url="$1"
  local out=""
  local alt=""
  local attempt

  if [[ "$url" == *"localhost"* ]]; then
    alt="${url/localhost/127.0.0.1}"
  elif [[ "$url" == *"127.0.0.1"* ]]; then
    alt="${url/127.0.0.1/localhost}"
  fi

  for attempt in 1 2 3 4 5; do
    out="$(curl -s --max-time 20 "$url" 2>/dev/null || true)"
    if [[ -n "$out" ]]; then
      printf '%s' "$out"
      return 0
    fi
    if [[ -n "$alt" ]]; then
      out="$(curl -s --max-time 20 "$alt" 2>/dev/null || true)"
      if [[ -n "$out" ]]; then
        printf '%s' "$out"
        return 0
      fi
    fi
    sleep 0.2
  done

  return 1
}

sparql_ask() {
  local query="$1"
  local out=""

  out="$(curl -s --max-time 20 -X POST "$FUSEKI_QUERY" --data-urlencode "query=$query" -H "Accept: application/sparql-results+json" 2>/dev/null || true)"
  if [[ -z "$out" ]]; then
    return 2
  fi

  printf '%s' "$out" | jq -r '.boolean // empty'
  return 0
}

require_cmd curl
require_cmd jq

echo "Entry-point soundness regression"
echo "DRUPAL_BASE=$DRUPAL_BASE"
echo "FUSEKI_QUERY=$FUSEKI_QUERY"

# 1) Bound entry points endpoint should respond and include key ontology entry points.
bound_json="$(http_get_retry "$DRUPAL_BASE/rep/bound-entry-points?_format=json" || true)"
if [[ -z "$bound_json" ]]; then
  bad "bound-entry-points endpoint unavailable"
else
  if printf '%s' "$bound_json" | jq -e . >/dev/null 2>&1; then
    ok "bound-entry-points endpoint returns JSON"
  else
    bad "bound-entry-points endpoint did not return valid JSON"
  fi

  if printf '%s' "$bound_json" | jq -r '.. | strings' | grep -q 'UBERON_0001062'; then
    ok "UBERON entry point binding is exposed by API"
  else
    bad "UBERON_0001062 not found in bound-entry-points response"
  fi

  if printf '%s' "$bound_json" | jq -r '.. | strings' | grep -q 'NCIT_C97325'; then
    ok "NCIT entry point binding is exposed by API"
  else
    bad "NCIT_C97325 not found in bound-entry-points response"
  fi
fi

# 2) Class entry-point tree should load.
topclass_url="$DRUPAL_BASE/rep/gettopclass?_format=json&nodeUri=http%3A%2F%2Fhadatac.org%2Font%2Fhasco%2FClassEntryPoint"
topclass_json="$(http_get_retry "$topclass_url" || true)"
if [[ -z "$topclass_json" ]]; then
  bad "ClassEntryPoint tree endpoint unavailable"
else
  if printf '%s' "$topclass_json" | jq -e . >/dev/null 2>&1; then
    if printf '%s' "$topclass_json" | jq -e 'if type=="array" then (length > 0) else true end' >/dev/null 2>&1; then
      ok "ClassEntryPoint tree endpoint returns data"
    else
      bad "ClassEntryPoint tree endpoint returned empty array"
    fi
  else
    bad "ClassEntryPoint tree endpoint did not return valid JSON"
  fi
fi

# 3) Fuseki semantic checks for expected mappings.
query_uberon='ASK { <http://purl.obolibrary.org/obo/UBERON_0001062> <http://www.w3.org/2000/01/rdf-schema#subClassOf> <http://hadatac.org/ont/hasco/AnatomicalPartEntryPoint> . }'
query_ncit='ASK { <http://purl.obolibrary.org/obo/NCIT_C97325> <http://www.w3.org/2000/01/rdf-schema#subClassOf> <http://hadatac.org/ont/hasco/MedicalDeviceEntryPoint> . }'
query_wf='ASK { <https://pmsr.net/ont/pmsr#MedicalSimulationProcessStem> <http://www.w3.org/2000/01/rdf-schema#subClassOf> <http://hadatac.org/ont/hasco/WorkflowStemEntryPoint> . }'
query_old='ASK { <https://pmsr.net/ont/pmsr#MedicalSimulationProcessStem> <http://www.w3.org/2000/01/rdf-schema#subClassOf> <http://hadatac.org/ont/hasco/ProcessEntryPoint> . }'

ans="$(sparql_ask "$query_uberon" || true)"
if [[ "$ans" == "true" ]]; then
  ok "UBERON -> AnatomicalPartEntryPoint mapping exists"
else
  bad "UBERON -> AnatomicalPartEntryPoint mapping missing"
fi

ans="$(sparql_ask "$query_ncit" || true)"
if [[ "$ans" == "true" ]]; then
  ok "NCIT -> MedicalDeviceEntryPoint mapping exists"
else
  bad "NCIT -> MedicalDeviceEntryPoint mapping missing"
fi

ans="$(sparql_ask "$query_wf" || true)"
if [[ "$ans" == "true" ]]; then
  ok "PMSR stem is bound to WorkflowStemEntryPoint"
else
  bad "PMSR stem is not bound to WorkflowStemEntryPoint"
fi

ans="$(sparql_ask "$query_old" || true)"
if [[ "$ans" == "false" ]]; then
  ok "PMSR stem is not bound to deprecated ProcessEntryPoint"
else
  bad "Deprecated ProcessEntryPoint binding detected for PMSR stem"
fi

echo
echo "Summary: passed=$pass failed=$fail"

if (( fail > 0 )); then
  exit 1
fi

exit 0
