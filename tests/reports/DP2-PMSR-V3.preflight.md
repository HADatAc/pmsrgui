# DP2 Verification Report

- Workbook: /opt/homebrew/var/www/drupal/web/modules/custom/pmsrgui/mts/DP2-PMSR-V3.xlsx
- Result: PASS
- Errors: 0
- Warnings: 1

## Summary
- errors: 0
- warnings: 1
- ins_workbook: /opt/homebrew/var/www/drupal/web/modules/custom/pmsrgui/mts/INS-PMSR-V3.xlsx
- ins_slot_validation_enabled: True
- ins_slot_count: 382
- ii_model_policy: ins-or-external
- ii_model_nonins_count: 237
- ii_model_inslike_count: 96
- deployments: 333
- platform_instances: 7
- instrument_instances: 333
- component_instances: 846
- deployment_component_link_column: 
- deployment_rows_with_component_link: 0

## Sheet Counts
- InfoSheet: data_rows=9, non_empty_rows=9
- Namespace: data_rows=2, non_empty_rows=2
- Deployments: data_rows=333, non_empty_rows=333
- ComponentDeployments: data_rows=845, non_empty_rows=845
- Platforms: data_rows=0, non_empty_rows=0
- PlatformInstances: data_rows=7, non_empty_rows=7
- FieldsOfView: data_rows=0, non_empty_rows=0
- InstrumentInstances: data_rows=333, non_empty_rows=333
- ComponentInstances: data_rows=846, non_empty_rows=846
- SensingPerspective: data_rows=0, non_empty_rows=0

## Findings

### WARN DEPLOY_COMPONENT_COL_MISSING (1)
- Deployments sheet has neither vstoi:hasComponentInstance nor vstoi:hasDetectorInstance.
- Samples:
  - {'sheet': 'Deployments'}