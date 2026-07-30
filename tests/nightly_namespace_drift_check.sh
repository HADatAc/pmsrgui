#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG_DIR="${BASE_DIR}/logs"
mkdir -p "$LOG_DIR"

stamp="$(date +%Y%m%d_%H%M%S)"
log_file="$LOG_DIR/namespace_drift_${stamp}.log"

echo "Nightly namespace drift check started: $(date -u +%Y-%m-%dT%H:%M:%SZ)" | tee "$log_file"

echo "Running strict safety gate..." | tee -a "$log_file"
if "$BASE_DIR/run-tests.sh" safety-gate 2>&1 | tee -a "$log_file"; then
  echo "Namespace drift check passed." | tee -a "$log_file"
  exit 0
fi

echo "Namespace drift check FAILED." | tee -a "$log_file"
echo "Review log: $log_file" | tee -a "$log_file"
exit 1
