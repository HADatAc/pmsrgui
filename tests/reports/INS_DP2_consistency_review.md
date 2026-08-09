# INS-DP2 Consistency Review (Strict URI-based)

Date: 2026-08-07
Inputs:
- mts/INS-PMSR-V2.xlsx
- mts/DP2-PMSR-V2.xlsx
- mts/DP2-PIAGET-V2.xlsx

## Summary

1. The previously reported 130 "fallback" rows are not true INS slot coverage failures.
2. They are malformed deployment rows in DP2-PMSR where `vstoi:hasDetectorInstance` contains a timestamp value.
3. After filtering to valid URI component links only, there are zero unresolved INS slot-owner mappings for both DP2 files.

## Evidence

- `tests/reports/ins_dp2_fallback_130_cases.csv`
  - Shows the 130 rows; detector value pattern is timestamp-like (e.g. `2025-06-05T13:01:01.000`) instead of URI lists.
- `tests/reports/DP2-PMSR-V2.malformed_detector_rows.csv`
  - Captures all malformed detector rows in DP2-PMSR.
- `tests/reports/DP2-PMSR-V2.unresolved_model_slots.csv`
  - Empty unresolved list for valid URI component links.
- `tests/reports/DP2-PIAGET-V2.unresolved_model_slots.csv`
  - Empty unresolved list.

## Regenerated ComponentDeployments

- DP2-PMSR-V2:
  - Deployments: 333
  - ComponentDeployments rows generated from valid URI links: 845
  - Malformed detector rows excluded from ComponentDeployments generation: 130
- DP2-PIAGET-V2:
  - Deployments: 18
  - ComponentDeployments rows generated: 168
  - Malformed detector rows: 0

## INS Expansion Assessment

- For components identified via valid DP2 component URIs, no additional INS slot-owner coverage is required.
- Therefore, no INS sheet expansion was necessary to achieve strict URI-based INS-DP2 connectivity for valid links.

## Remaining Data Quality Action

- Correct DP2-PMSR malformed deployment rows by moving timestamp values out of `vstoi:hasDetectorInstance` into `vstoi:designedAtTime` where applicable.
