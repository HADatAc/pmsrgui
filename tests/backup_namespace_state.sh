#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
OUT_DIR="${BASE_DIR}/snapshots/archive"
mkdir -p "$OUT_DIR"

HASCOAPI_BASE="${PMSR_TEST_HASCOAPI_BASE:-http://127.0.0.1:9001/hascoapi/api}"
stamp="$(date +%Y%m%d_%H%M%S)"

ns_file="$OUT_DIR/namespaces_${stamp}.json"
stats_file="$OUT_DIR/statistics_${stamp}.json"

echo "Backing up namespace state from ${HASCOAPI_BASE}"

curl -s --max-time 30 "${HASCOAPI_BASE}/repo/table/namespaces" > "$ns_file"

jq -n \
  --argjson ontologies "$(curl -s --max-time 30 "${HASCOAPI_BASE}/statistics/ontologies/count" | jq '.body.total')" \
  --argjson classes "$(curl -s --max-time 30 "${HASCOAPI_BASE}/statistics/classes/count" | jq '.body.total')" \
  --argjson instances "$(curl -s --max-time 30 "${HASCOAPI_BASE}/statistics/instances/count" | jq '.body.total')" \
  --argjson instruments "$(curl -s --max-time 30 "${HASCOAPI_BASE}/statistics/instruments/count" | jq '.body.total')" \
  --argjson procedures "$(curl -s --max-time 30 "${HASCOAPI_BASE}/statistics/procedures/count" | jq '.body.total')" \
  --argjson anatomy "$(curl -s --max-time 30 "${HASCOAPI_BASE}/statistics/anatomy/count" | jq '.body.total')" \
  --argjson medical_devices "$(curl -s --max-time 30 "${HASCOAPI_BASE}/statistics/medical-devices/count" | jq '.body.total')" \
  '{
    ontologies: $ontologies,
    classes: $classes,
    instances: $instances,
    instruments: $instruments,
    procedures: $procedures,
    anatomy: $anatomy,
    medical_devices: $medical_devices
  }' > "$stats_file"

echo "Created backup files:"
echo "  $ns_file"
echo "  $stats_file"
