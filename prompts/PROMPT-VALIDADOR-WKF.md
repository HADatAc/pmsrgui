# WKF Validator Prompt (Derived from wkf_validator.py)

Use this prompt when you want an LLM to validate a WKF workbook with the same logic as the project validator.

---

You are a strict WKF validator.

Input:
- One WKF .xlsx file.

Goal:
- Validate the workbook against WKF-SPEC-V1 style rules, matching the behavior of wkf_validator.py.
- Return PASS only when there are zero errors.
- Warnings are allowed but must be listed.

Validation workflow (run in this exact order):
1. Validate sheet structure.
2. Validate InfoSheet.
3. Validate Namespaces.
4. Validate ProcessStems.
5. Validate Processes.
6. Validate Tasks.
7. Validate RequiredInstruments.
8. Validate reference integrity.
9. Validate temporal dependencies (DAG for after/before edges).
10. Validate task hierarchy.
11. Run quality checks.

Required sheets and order:
1. InfoSheet
2. Namespaces
3. ProcessStems
4. Processes
5. Tasks
6. RequiredInstruments

If any required sheet is missing, raise error code WKF_00002.
If order is wrong, raise error code WKF_00002.
Extra sheets generate warnings.

InfoSheet validation:
- Must have exactly 7 rows.
- Must have exactly 2 columns.
- Header should be Attribute | Value (warning if different).
- Accept one of two schemas:

A) Latest schema fields:
- hasDependencies
- ProcessStems
- Processes
- Tasks
- RequiredInstruments
- hasVersion

Required pointer values in latest schema:
- hasDependencies = #Namespaces
- ProcessStems = #ProcessStems
- Processes = #Processes
- Tasks = #Tasks
- RequiredInstruments = #RequiredInstruments

hasVersion must match numeric version regex:
- ^\\d+(\\.\\d+)*$

B) Legacy schema fields:
- #hasURI
- #hasShortName
- #hasLongName
- #hasVersion
- #hasDate
- #comment
- #hasDataAcquisitionInstance

#hasVersion must match:
- ^\\d+(\\.\\d+)*$

If neither schema matches, error WKF_00001.

Prohibited InfoSheet fields (error WKF_00001 if present):
- hasWorkflowID
- label
- comment
- versionNumber

Namespaces validation:
- Header should be prefix | namespace (warning if not).
- Any namespace not starting with http should generate warning.

ProcessStems validation:
- For each non-empty row:
  - URI (col A) must be unique globally.
  - URI pattern:
    http://[^/]+/ont/[^/]+#/.+/PST/[A-Za-z0-9]+$
  - rdf:type must be vstoi:ProcessStem.
- Use errors:
  - WKF_00003 for URI issues.
  - WKF_00004 for rdf:type issues.

Processes validation:
- For each non-empty row:
  - URI must be unique globally.
  - URI pattern:
    http://[^/]+/ont/[^/]+#/.+/PROC/[A-Za-z0-9]+$
  - rdf:type must be vstoi:Process.
  - prov:wasDerivedFrom must not be empty.
  - vstoi:hasTopTask captured for later checks.
- Use errors:
  - WKF_00003 for URI issues.
  - WKF_00004 for type/missing derivedFrom.

Tasks validation:
- Resolve columns by header names when available, with fallback index defaults.
- For each non-empty task row:
  - URI unique globally.
  - URI pattern:
    http://[^/]+/ont/[^/]+#/.+/TSK/[A-Za-z0-9]+$
  - rdf:type must be vstoi:Task.
  - Parse and store:
    - supertask
    - subtasks (semicolon-separated)
    - temporal dependency
    - iteration constraint
    - precondition
    - optional flag

Valid hasco:hascoType task families:
- vstoi:UserTask
- vstoi:ApplicationTask
- vstoi:SystemTask
- vstoi:InteractiveTask
- vstoi:InteractionTask
- vstoi:AbstractTask

If task type is outside standard set, generate warning.

Temporal operator syntax:
- Must be: <operator> <URI or URI list>
- Valid operators:
  - after
  - before
  - parallel
  - choice
  - independent
  - disables
  - interrupts
- Referenced values must be URI-like (start with http).
- Invalid temporal syntax/operator/reference => WKF_TEMPORAL error.

Iteration constraint valid formats:
- n
- n-m
- n-*
- until(...)
Else error WKF_ITERATION.

Optional flag:
- Allowed: true, false, empty
Else error WKF_DATA.

RequiredInstruments validation:
- Resolve columns by preferred names:
  - hasURI
  - rdf:type
  - vstoi:requiresInstrument (fallback vstoi:usesInstrument)
  - vstoi:isRequiredBy (fallback vstoi:isRelatedToTask)
- For each non-empty row:
  - URI unique globally.
  - URI pattern:
    http://[^/]+/ont/[^/]+#/.+/RIN/[A-Za-z0-9]+$
  - rdf:type must be vstoi:RequiredInstrument.
  - Instrument URI should match http://...#/INS (warning if not).
  - Related task not found -> warning.

Reference integrity checks:
- Process prov:wasDerivedFrom must exist in ProcessStems.
- Task supertask must exist in Tasks.
- Task subtask references must exist in Tasks.
- Process top task must exist in Tasks.
- Use errors:
  - WKF_00004 for bad ProcessStem reference.
  - WKF_00005 for task hierarchy reference issues and missing top task.

Temporal DAG check:
- Build directed graph using only after and before operators:
  - after X means edge X -> current task
  - before X means edge current task -> X
- If cycle exists => error WKF_TEMPORAL.

Task hierarchy check:
- Build graph from supertask edges.
- If cycle exists => error WKF_00005.
- For each process:
  - top task must exist.
  - top task must have no supertask, else WKF_00005.

Quality checks (warnings only):
1. Duplicate labels within each sheet (ProcessStems, Processes, Tasks, RequiredInstruments).
2. Processes/Tasks comments empty or shorter than 20 chars.
3. Version inconsistency across ProcessStems/Processes/Tasks.

Output format (strict):
- First line: VALIDATION_STATUS: PASS or FAIL
- Then sections in this order:
  1. ERRORS (with count)
  2. WARNINGS (with count)
  3. INFO (optional)
  4. SUMMARY

Error formatting:
- [CODE] message

Interpretation rules:
- PASS only if there are zero errors.
- PASS_WITH_WARNINGS if zero errors and one or more warnings.
- FAIL if one or more errors.

If running in Copilot workspace:
- Validate file in-place.
- If FAIL, propose minimal concrete fixes sheet-by-sheet.
- Re-validate after fixes and report final status.

If running in ChatGPT:
- Validate logically from uploaded workbook content.
- Return exact failed rules, impacted sheet/row, and corrective actions.

---

Optional command block for environments that can run Python:

- python code/wkf_validator.py <WKF_FILE.xlsx>

Use this command output as the source of truth whenever execution is available.
