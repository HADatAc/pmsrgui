#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-verify}"
BASELINE_FILE="${2:-$(cd "$(dirname "$0")" && pwd)/baselines/kgr_people_statistics_minimum_baseline.json}"

DRUPAL_BASE="${PMSR_TEST_DRUPAL_BASE:-http://127.0.0.1:8080}"
HASCOAPI_BASE="${PMSR_TEST_HASCOAPI_BASE:-http://127.0.0.1:9001/hascoapi/api}"

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

summary_and_exit() {
  echo
  echo "Summary: passed=$pass failed=$fail"
  if (( fail > 0 )); then
    exit 1
  fi
  exit 0
}

if ! command -v curl >/dev/null 2>&1; then
  echo "curl is required"
  exit 2
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "jq is required"
  exit 2
fi

curl_json() {
  local url="$1"
  local out
  local status

  set +e
  out="$(curl -s --max-time 20 "$url" 2>/dev/null)"
  status=$?
  set -e

  if (( status != 0 )); then
    return 1
  fi

  if [[ -n "$out" ]]; then
    printf '%s' "$out"
    return 0
  fi

  return 1
}

build_snapshot() {
  local snapshot

  # Prefer consolidated snapshot endpoint when available.
  snapshot="$(curl_json "$DRUPAL_BASE/pmsr/api/statistics/snapshot" || true)"
  if [[ -n "$snapshot" ]] && jq -e '.success == true and (.data | type == "object")' >/dev/null 2>&1 <<<"$snapshot"; then
    jq -c '.data' <<<"$snapshot"
    return 0
  fi

  # Fallback to hascoapi card metrics and zeroed member totals.
  local ontologies classes instances simulators procedures anatomy devices
  ontologies="$(curl_json "$HASCOAPI_BASE/statistics/ontologies/count" | jq -r '.body.total // empty')"
  classes="$(curl_json "$HASCOAPI_BASE/statistics/classes/count" | jq -r '.body.total // empty')"
  instances="$(curl_json "$HASCOAPI_BASE/statistics/instances/count" | jq -r '.body.total // empty')"
  simulators="$(curl_json "$HASCOAPI_BASE/statistics/instruments/count" | jq -r '.body.total // empty')"
  procedures="$(curl_json "$HASCOAPI_BASE/statistics/procedures/count" | jq -r '.body.total // empty')"
  anatomy="$(curl_json "$HASCOAPI_BASE/statistics/anatomy/count" | jq -r '.body.total // empty')"
  devices="$(curl_json "$HASCOAPI_BASE/statistics/medical-devices/count" | jq -r '.body.total // empty')"

  if [[ -z "$ontologies" || -z "$classes" || -z "$instances" || -z "$simulators" || -z "$procedures" || -z "$anatomy" || -z "$devices" ]]; then
    return 1
  fi

  jq -n \
    --argjson ontologies "$ontologies" \
    --argjson classes "$classes" \
    --argjson instances "$instances" \
    --argjson simulator_models "$simulators" \
    --argjson clinical_procedures "$procedures" \
    --argjson anatomical_structures "$anatomy" \
    --argjson medical_devices "$devices" \
    '{
      ontologies: $ontologies,
      classes: $classes,
      instances: $instances,
      simulator_models: $simulator_models,
      clinical_procedures: $clinical_procedures,
      anatomical_structures: $anatomical_structures,
      medical_devices: $medical_devices,
      registered_pmsr_users_total: 0,
      people_in_knowledge_graph_total: 0,
      registered_scenarios_total: 0,
      registered_processes_total: 0,
      registered_tasks_subtasks_total: 0,
      registered_simulators_total: 0,
      registered_simulation_laboratories_total: 0
    }'
}

if [[ "$MODE" == "record" ]]; then
  snapshot="$(build_snapshot || true)"
  if [[ -z "$snapshot" ]]; then
    bad "Could not fetch Statistics snapshot for baseline recording"
    summary_and_exit
  fi

  mkdir -p "$(dirname "$BASELINE_FILE")"
  jq -n \
    --arg recordedAt "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --arg source "$DRUPAL_BASE/pmsr/statistics" \
    --argjson minimumStatistics "$snapshot" \
    '{
      recordedAt: $recordedAt,
      source: $source,
      minimumStatistics: $minimumStatistics
    }' > "$BASELINE_FILE"

  ok "Recorded baseline: $BASELINE_FILE"
  summary_and_exit
fi

if [[ "$MODE" != "verify" ]]; then
  echo "Unknown mode: $MODE"
  echo "Usage: $0 record|verify [baseline.json]"
  exit 2
fi

if [[ ! -f "$BASELINE_FILE" ]]; then
  bad "Baseline file not found: $BASELINE_FILE"
  summary_and_exit
fi

if ! jq -e '.minimumStatistics and (.minimumStatistics | type == "object")' "$BASELINE_FILE" >/dev/null 2>&1; then
  bad "Baseline file is invalid: expected minimumStatistics object"
  summary_and_exit
fi

snapshot="$(build_snapshot || true)"
if [[ -z "$snapshot" ]]; then
  bad "Could not fetch current Statistics snapshot"
  summary_and_exit
fi

while IFS= read -r key; do
  [[ -z "$key" ]] && continue
  min_val="$(jq -r --arg k "$key" '.minimumStatistics[$k]' "$BASELINE_FILE")"
  cur_val="$(jq -r --arg k "$key" '.[$k] // 0' <<<"$snapshot")"

  if (( cur_val >= min_val )); then
    ok "$key: $cur_val >= $min_val"
  else
    bad "$key: $cur_val < $min_val"
  fi
done < <(jq -r '.minimumStatistics | keys[]' "$BASELINE_FILE")

summary_and_exit
