#!/usr/bin/env bash
set -euo pipefail

# Deterministic baseline comparator.
# No network calls are performed in this script.
#
# Usage:
#   ./compare_minimums_from_snapshots.sh [baseline.json] [namespaces.json] [statistics.json]
#
# Defaults:
#   baseline.json   = ./baselines/report_minimums_baseline.json
#   namespaces.json = ./snapshots/namespaces_current.json
#   statistics.json = ./snapshots/statistics_current.json

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
BASELINE_FILE="${1:-$BASE_DIR/baselines/report_minimums_baseline.json}"
NAMESPACES_FILE="${2:-$BASE_DIR/snapshots/namespaces_current.json}"
STATISTICS_FILE="${3:-$BASE_DIR/snapshots/statistics_current.json}"

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

require_file() {
  local f="$1"
  if [[ ! -f "$f" ]]; then
    echo "Missing file: $f"
    exit 2
  fi
}

if ! command -v jq >/dev/null 2>&1; then
  echo "jq is required"
  exit 2
fi

require_file "$BASELINE_FILE"
require_file "$NAMESPACES_FILE"
require_file "$STATISTICS_FILE"

# Validate namespace snapshot has expected shape.
if ! jq -e '.body and (.body | type == "array")' "$NAMESPACES_FILE" >/dev/null 2>&1; then
  echo "Invalid namespaces snapshot format: expected hascoapi /repo/table/namespaces JSON"
  exit 2
fi

# Policy guard: block known bad auto-generated abbreviations.
while IFS= read -r label; do
  [[ -z "$label" ]] && continue
  if [[ "$label" =~ ^ns_http___ ]]; then
    bad "namespace abbreviation uses blocked auto-generated pattern: $label"
  fi
done < <(jq -r '.body[]?.label // ""' "$NAMESPACES_FILE")

# Validate statistics snapshot has expected flat keys.
if ! jq -e '.ontologies and .classes and .instances and .instruments and .procedures and .anatomy and .medical_devices' "$STATISTICS_FILE" >/dev/null 2>&1; then
  echo "Invalid statistics snapshot format: expected keys ontologies/classes/instances/instruments/procedures/anatomy/medical_devices"
  exit 2
fi

while IFS= read -r key; do
  [[ -z "$key" ]] && continue

  exists="$(jq -r --arg k "$key" 'any(.body[]; .label == $k)' "$NAMESPACES_FILE")"
  if [[ "$exists" != "true" ]]; then
    bad "namespace missing: $key"
    continue
  fi
  ok "namespace exists: $key"

  expected_uri="$(jq -r --arg k "$key" '.minimumNamespaces[$k].uri' "$BASELINE_FILE")"
  expected_mime="$(jq -r --arg k "$key" '.minimumNamespaces[$k].sourceMime' "$BASELINE_FILE")"
  expected_min="$(jq -r --arg k "$key" '.minimumNamespaces[$k].minTriples' "$BASELINE_FILE")"

  current_uri="$(jq -r --arg k "$key" '.body[] | select(.label == $k) | .uri' "$NAMESPACES_FILE")"
  current_mime="$(jq -r --arg k "$key" '.body[] | select(.label == $k) | (.sourceMime // "")' "$NAMESPACES_FILE")"
  current_triples="$(jq -r --arg k "$key" '.body[] | select(.label == $k) | (.numberOfLoadedTriples // 0)' "$NAMESPACES_FILE")"

  if [[ "$current_uri" == "$expected_uri" ]]; then
    ok "namespace uri matches: $key"
  else
    bad "namespace uri mismatch: $key (current=$current_uri baseline=$expected_uri)"
  fi

  if [[ "$current_mime" == "$expected_mime" ]]; then
    ok "namespace mime matches: $key"
  else
    bad "namespace mime mismatch: $key (current=$current_mime baseline=$expected_mime)"
  fi

  if (( current_triples >= expected_min )); then
    ok "namespace triples >= minimum: $key ($current_triples >= $expected_min)"
  else
    bad "namespace triples below minimum: $key ($current_triples < $expected_min)"
  fi
done < <(jq -r '.minimumNamespaces | keys[]' "$BASELINE_FILE")

while IFS= read -r key; do
  [[ -z "$key" ]] && continue

  min_val="$(jq -r --arg k "$key" '.minimumStatistics[$k]' "$BASELINE_FILE")"
  cur_val="$(jq -r --arg k "$key" '.[$k]' "$STATISTICS_FILE")"

  if (( cur_val >= min_val )); then
    ok "statistic >= minimum: $key ($cur_val >= $min_val)"
  else
    bad "statistic below minimum: $key ($cur_val < $min_val)"
  fi
done < <(jq -r '.minimumStatistics | keys[]' "$BASELINE_FILE")

echo

echo "Summary: passed=$pass failed=$fail"

if (( fail > 0 )); then
  exit 1
fi

exit 0
