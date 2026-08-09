# DP2 Conformance 15-Step Completion Checklist

Date: 2026-08-07
Scope:
- hascoapi DP2 ingestion enforcement
- PMSR Drupal DP2 preflight and gating
- regression coverage and traceability

## Status Legend
- DONE: implemented
- PARTIAL: implemented but needs environment/data follow-up

## A. hascoapi strict enforcement (Steps 1-6)

1. DONE - Added Java DP2 verifier class.
- File: app/org/hascoapi/ingestion/DP2WorkbookVerifier.java

2. DONE - Wired verifier into AnnotateDP2 as hard gate before generator chain.
- File: app/org/hascoapi/ingestion/AnnotateDP2.java

3. DONE - Enforced required DP2 sheets, headers, and InfoSheet keys.
- File: app/org/hascoapi/ingestion/DP2WorkbookVerifier.java

4. DONE - Enforced deployment reference integrity and non-URI detector/component rejection.
- File: app/org/hascoapi/ingestion/DP2WorkbookVerifier.java

5. DONE - Enforced InstrumentInstances model policy:
- `a` must be URI-like
- `a` must be INS-like
- `a` must resolve in repository
- File: app/org/hascoapi/ingestion/DP2WorkbookVerifier.java

6. DONE - Enforced slot/component model compatibility as blocking:
- UNKNOWN moved from warning to error
- MISMATCH remains error
- File: app/org/hascoapi/ingestion/DP2WorkbookVerifier.java

## B. PMSR preflight fail-fast gate (Steps 7-10)

7. DONE - Added DP2 preflight execution helper in ingestion controller.
- File: src/Controller/IngestionKgrPeopleController.php

8. DONE - Added preflight call before any DP2 uploadTemplate invocation.
- File: src/Controller/IngestionKgrPeopleController.php

9. DONE - Preflight failure now blocks ingestion for that workbook.
- File: src/Controller/IngestionKgrPeopleController.php

10. DONE - Preflight writes report artifacts to tests/reports with explicit prefix.
- File: src/Controller/IngestionKgrPeopleController.php

## C. Regression testing and CI traceability (Steps 11-13)

11. DONE - Added hascoapi regression test for model-policy violation behavior.
- File: test/org/hascoapi/tests/DP2WorkbookVerifierPolicyTest.java

12. DONE - Added hascoapi regression test for unresolved INS model behavior.
- File: test/org/hascoapi/tests/DP2WorkbookVerifierPolicyTest.java

13. DONE - PMSR preflight wiring regression test added.
- File: tests/src/Unit/Dp2PreflightWiringTest.php

## D. Data quality + conformance governance (Steps 14-15)

14. DONE - Source workbooks validated clean under strict preflight.
- Evidence:
	- tests/reports/DP2-PMSR-V2.preflight.json (PASS, 0 errors, 0 warnings)
	- tests/reports/DP2-PIAGET-V2.preflight.json (PASS, 0 errors, 0 warnings)

15. DONE - Created explicit conformance checklist artifact mapped to implementation and tests.
- File: mts/DP2-CONFORMANCE-15-STEPS.md

## Practical Outcome

- hascoapi now blocks DP2 ingestion when critical DP2-SPEC semantic constraints fail.
- PMSR now performs fail-fast local verification before uploadTemplate.
- Remaining non-code work is source workbook cleanup and optional PMSR preflight service test refactor.
