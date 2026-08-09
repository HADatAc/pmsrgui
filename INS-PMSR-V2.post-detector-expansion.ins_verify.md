# INS Verification Report

Workbook: mts/INS-PMSR-V2.xlsx

## Verdict: PASS

## Summary
- Errors: 0
- Warnings: 2
- Instruments: 75
- Components: 394
- SlotElements: 382
- CodeBooks: 25
- CodeBookSlots: 66
- ResponseOptions: 73

## Sheet Counts
- Instruments: 75
- SlotElements: 382
- ComponentStems: 472
- Components: 394
- CodeBooks: 25
- CodeBookSlots: 66
- ResponseOptions: 73

## Findings

### WARN COMP_LABEL_DUP
- Message: Duplicate component labels detected.
- Count: 3
- Samples:
  - {'label': 'cancer patient simulator interaction event detector', 'count': 2}
  - {'label': 'cancer patient simulator airway pressure detector', 'count': 2}
  - {'label': 'cancer patient simulator pulse rate detector', 'count': 2}

### WARN DET_ACT_PARITY
- Message: Detector/Actuator count mismatch.
- Count: 1
- Samples:
  - {'detector_count': 303, 'actuator_count': 75}