#!/usr/bin/env bash
set -u

BASELINE_FILE="${1:-$(cd "$(dirname "$0")" && pwd)/baselines/report_minimums_baseline.json}"
HASCOAPI_BASE="${PMSR_TEST_HASCOAPI_BASE:-http://localhost:9001/hascoapi/api}"
DEBUG="${PMSR_TEST_DEBUG:-0}"

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

dbg() {
  if [[ "$DEBUG" == "1" ]]; then
    echo "[DEBUG] $*" >&2
  fi
}

http_get_retry() {
  local url="$1"
  local alt=""
  local out=""
  local candidate
  local attempt

  if [[ "$url" == *"localhost"* ]]; then
    alt="${url/localhost/127.0.0.1}"
  elif [[ "$url" == *"127.0.0.1"* ]]; then
    alt="${url/127.0.0.1/localhost}"
  fi

  local candidates=()
  candidates+=("$url")
  if [[ -n "$alt" && "$alt" != "$url" ]]; then
    candidates+=("$alt")
  fi

  for candidate in "${candidates[@]}"; do
    for attempt in 1 2 3 4 5; do
      out="$(curl -s --max-time 20 "$candidate" 2>/dev/null || true)"
      if [[ -n "$out" ]]; then
        dbg "GET OK $candidate (attempt $attempt, bytes=${#out})"
        printf '%s' "$out"
        return 0
      fi
      dbg "GET empty $candidate (attempt $attempt)"
      sleep 0.2
    done
  done

  return 1
}

if ! command -v jq >/dev/null 2>&1; then
  echo "jq is required"
  exit 2
fi

if [[ ! -f "$BASELINE_FILE" ]]; then
  echo "Baseline file not found: $BASELINE_FILE"
  exit 2
fi

ns_json="$(http_get_retry "$HASCOAPI_BASE/repo/table/namespaces" || true)"
if [[ -z "$ns_json" ]]; then
  echo "Failed to fetch namespaces from $HASCOAPI_BASE/repo/table/namespaces"
  exit 2
fi

# Policy guard: block known bad auto-generated abbreviations.
while IFS= read -r label; do
  [[ -z "$label" ]] && continue
  if [[ "$label" =~ ^ns_http___ ]]; then
    bad "Namespace abbreviation uses blocked auto-generated pattern: $label"
  fi
done < <(printf '%s' "$ns_json" | jq -r '.body[]?.label // ""')

stats_json='{}'
for key in ontologies classes instances instruments procedures anatomy medical_devices; do
  case "$key" in
    medical_devices)
      path="/statistics/medical-devices/count"
      ;;
    *)
      path="/statistics/$key/count"
      ;;
  esac

  body="$(http_get_retry "$HASCOAPI_BASE$path" || true)"
  total="$(printf '%s' "$body" | jq -r '.body.total // empty' 2>/dev/null)"
  if [[ -z "$total" ]]; then
    echo "Failed to fetch statistic '$key' from $HASCOAPI_BASE$path"
    exit 2
  fi
  stats_json="$(printf '%s' "$stats_json" | jq --arg k "$key" --argjson v "$total" '. + {($k): $v}')"
done

while IFS= read -r key; do
  [[ -z "$key" ]] && continue

  exists="$(printf '%s' "$ns_json" | jq -r --arg k "$key" 'any(.body[]; .label == $k)')"
  if [[ "$exists" != "true" ]]; then
    bad "Namespace '$key' missing"
    continue
  fi
  ok "Namespace '$key' exists"

  expected_uri="$(jq -r --arg k "$key" '.minimumNamespaces[$k].uri' "$BASELINE_FILE")"
  expected_mime="$(jq -r --arg k "$key" '.minimumNamespaces[$k].sourceMime' "$BASELINE_FILE")"
  expected_min="$(jq -r --arg k "$key" '.minimumNamespaces[$k].minTriples' "$BASELINE_FILE")"

  current_uri="$(printf '%s' "$ns_json" | jq -r --arg k "$key" '.body[] | select(.label == $k) | .uri')"
  current_mime="$(printf '%s' "$ns_json" | jq -r --arg k "$key" '.body[] | select(.label == $k) | (.sourceMime // "")')"
  current_triples="$(printf '%s' "$ns_json" | jq -r --arg k "$key" '.body[] | select(.label == $k) | (.numberOfLoadedTriples // 0)')"

  if [[ "$current_uri" == "$expected_uri" ]]; then
    ok "Namespace '$key' URI matches"
  else
    bad "Namespace '$key' URI mismatch (current: $current_uri | baseline: $expected_uri)"
  fi

  if [[ "$current_mime" == "$expected_mime" ]]; then
    ok "Namespace '$key' MIME matches"
  else
    bad "Namespace '$key' MIME mismatch (current: $current_mime | baseline: $expected_mime)"
  fi

  if (( current_triples >= expected_min )); then
    ok "Namespace '$key' triples >= minimum ($current_triples >= $expected_min)"
  else
    bad "Namespace '$key' triples below minimum ($current_triples < $expected_min)"
  fi

done < <(jq -r '.minimumNamespaces | keys[]' "$BASELINE_FILE")

while IFS= read -r key; do
  [[ -z "$key" ]] && continue
  min="$(jq -r --arg k "$key" '.minimumStatistics[$k]' "$BASELINE_FILE")"
  cur="$(printf '%s' "$stats_json" | jq -r --arg k "$key" '.[$k] // 0')"

  if (( cur >= min )); then
    ok "Statistic '$key' >= minimum ($cur >= $min)"
  else
    bad "Statistic '$key' below minimum ($cur < $min)"
  fi
done < <(jq -r '.minimumStatistics | keys[]' "$BASELINE_FILE")

echo
echo "Summary: passed=$pass failed=$fail"

if (( fail > 0 )); then
  exit 1
fi

exit 0
