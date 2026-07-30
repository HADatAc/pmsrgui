# Snapshot Inputs For Minimum Baseline Comparator

This folder stores the second-run snapshots consumed by:
- ./compare_minimums_from_snapshots.sh

Create snapshot files with one-shot commands:

```bash
# 1) Namespaces snapshot (raw hascoapi response)
curl -s http://localhost:9001/hascoapi/api/repo/table/namespaces \
  > snapshots/namespaces_current.json

# 2) Statistics snapshot (flattened values)
jq -n \
  --argjson ontologies "$(curl -s http://localhost:9001/hascoapi/api/statistics/ontologies/count | jq '.body.total')" \
  --argjson classes "$(curl -s http://localhost:9001/hascoapi/api/statistics/classes/count | jq '.body.total')" \
  --argjson instances "$(curl -s http://localhost:9001/hascoapi/api/statistics/instances/count | jq '.body.total')" \
  --argjson instruments "$(curl -s http://localhost:9001/hascoapi/api/statistics/instruments/count | jq '.body.total')" \
  --argjson procedures "$(curl -s http://localhost:9001/hascoapi/api/statistics/procedures/count | jq '.body.total')" \
  --argjson anatomy "$(curl -s http://localhost:9001/hascoapi/api/statistics/anatomy/count | jq '.body.total')" \
  --argjson medical_devices "$(curl -s http://localhost:9001/hascoapi/api/statistics/medical-devices/count | jq '.body.total')" \
  '{
    ontologies: $ontologies,
    classes: $classes,
    instances: $instances,
    instruments: $instruments,
    procedures: $procedures,
    anatomy: $anatomy,
    medical_devices: $medical_devices
  }' > snapshots/statistics_current.json
```

Then run:

```bash
./compare_minimums_from_snapshots.sh
```

Comparison rule:
- Current values must be >= baseline minimum values.
- For namespaces, URI and MIME must match baseline exactly.
