# WKF Validator Prompt (Aligned with WKF-SPEC-V2 v1.2.2)

Use this prompt when you want an LLM to validate a WKF workbook using WKF-SPEC-V2 v1.2.2 as source of truth.

---

You are a strict WKF validator.

Input:
- One WKF .xlsx file.

Goal:
- Validate the workbook against WKF-SPEC-V2 v1.2.2 rules, including PMSR normative constraints.
- Return PASS only when there are zero errors.
- Warnings are allowed but must be listed.

Validation workflow (run in this exact order):
1. Validate sheet structure.
2. Validate InfoSheet.
3. Validate Namespaces.
4. Validate STD.
5. Validate ProcessStems.
6. Validate Processes.
7. Validate Tasks.
8. Validate RequiredInstruments.
9. Validate reference integrity.
10. Validate temporal dependencies (DAG for after/before edges).
11. Validate task hierarchy.
12. Run quality checks.

Required sheets and order:
1. InfoSheet
2. Namespaces
3. STD
4. ProcessStems
5. Processes
6. Tasks
7. RequiredInstruments

If any required sheet is missing, raise error code WKF_00002.
If order is wrong, raise error code WKF_00002.
Extra sheets generate warnings.

InfoSheet validation:
- Must have exactly 8 rows (1 header + 7 fields).
- Must have exactly 2 columns.
- Header must be exactly Attribute | Value (error if different).
- Required fields (exact order):
  - hasDependencies
  - hasStudyDescription
  - ProcessStems
  - Processes
  - Tasks
  - RequiredInstruments
  - hasVersion
- Required pointer values:
  - hasDependencies = #Namespaces
  - hasStudyDescription = #STD
  - ProcessStems = #ProcessStems
  - Processes = #Processes
  - Tasks = #Tasks
  - RequiredInstruments = #RequiredInstruments
- hasVersion must match numeric version regex:
  - ^\\d+(\\.\\d+)*$
- Prohibited InfoSheet fields (error WKF_00001 if present):
  - hasWorkflowID
  - label
  - comment
  - versionNumber

Namespaces validation:
- Header should be prefix | namespace (warning if not).
- Minimum required rows (error if missing or value differs):
  - hasco = http://hadatac.org/ont/hasco#
  - vstoi = http://hadatac.org/ont/vstoi#
  - prov = http://www.w3.org/ns/prov#
  - rdfs = http://www.w3.org/2000/01/rdf-schema#
  - rdf = http://www.w3.org/1999/02/22-rdf-syntax-ns#
  - owl = http://www.w3.org/2002/07/owl#
  - xsd = http://www.w3.org/2001/XMLSchema#
  - pmsr = https://pmsr.net/ont/
- Any namespace not starting with http should generate warning.
- PMSR profile (error if violated):
  - pmsr prefix MUST exist.
  - pmsr namespace MUST be exactly https://pmsr.net/ont/.

STD validation:
- Row 2 must contain headers (B-O):
  - hasURI, hasco:hasProcess, Study ID, Title, Specific Aims, Significance, Institution, Principal Investigator, Email, Start Date, End Date, vstoi:hasLearningObjectives, vstoi:hasCriticalActions, vstoi:hasDebriefingFocus
- Data starts at row 3.
- For each non-empty row:
  - hasURI must be URI-like (start with http).
  - hasco:hasProcess must be URI-like (start with http).
  - Study ID SHOULD start with STD- (warning if not).
  - Institution should be URI-like (start with http).
  - Principal Investigator should be URI-like (start with http).
  - Start Date and End Date should match YYYY-MM-DD when present.

STD-to-Process linkage checks:
- For each Process URI created in `Processes` sheet, there MUST be at least one STD row with matching `hasco:hasProcess`.
- If an STD row `hasco:hasProcess` does not exist in `Processes`, treat as external reuse (allowed).

ProcessStems validation:
- For each non-empty row:
  - URI (col A) must be unique globally.
  - URI pattern:
    - https://pmsr.net/ont/[^/]+/PST/[A-Za-z0-9]+$
  - rdf:type must be vstoi:ProcessStem.
- Use errors:
  - WKF_00003 for URI issues.
  - WKF_00004 for rdf:type issues.

Processes validation:
- For each non-empty row:
  - URI must be unique globally.
  - URI pattern:
    - https://pmsr.net/ont/[^/]+/PROC/[A-Za-z0-9]+$
  - rdf:type must be vstoi:Process.
  - hasco:hascoType must be present and cardinality 1.
  - hasco:hascoType must be consistent with the ProcessStem referenced by prov:wasDerivedFrom.
  - prov:wasDerivedFrom must not be empty.
  - vstoi:hasTopTask captured for later checks.
- Use errors:
  - WKF_00003 for URI issues.
  - WKF_00004 for type/missing derivedFrom.
  - WKF_00007 for Process type rule violations.

Tasks validation:
- Resolve columns by header names when available, with fallback index defaults.
- For each non-empty task row:
  - URI unique globally.
  - URI pattern:
    - https://pmsr.net/ont/[^/]+/TSK/[A-Za-z0-9]+$
  - hasco:hascoType must be exactly vstoi:Task.
  - rdf:type must be vstoi:Task or subclass of vstoi:Task.
  - Parse and store:
    - supertask
    - subtasks (semicolon-separated)
    - temporal dependency
    - iteration constraint
    - optional flag

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
- Resolve columns by names:
  - hasURI
  - rdf:type
  - hasco:hascoType
  - vstoi:usesInstrument
  - vstoi:isRelatedToTask
- For each non-empty row:
  - URI unique globally.
  - URI pattern:
    - https://pmsr.net/ont/[^/]+/RIN/[A-Za-z0-9]+$
  - rdf:type must be vstoi:RequiredInstrument.
  - hasco:hascoType must be present and cardinality 1.
  - usesInstrument should be URI-like (start with http).
  - Related task not found -> warning.

Reference integrity checks:
- Process prov:wasDerivedFrom must exist in ProcessStems.
- Process type rule (WKF_00007):
  - Each Process MUST have exactly one hasco:hascoType value.
  - That value MUST be consistent with hasco:hascoType of the referenced ProcessStem.
- Task supertask must exist in Tasks.
- Task subtask references must exist in Tasks.
- Process top task must exist in Tasks.
- Use errors:
  - WKF_00004 for bad ProcessStem reference.
  - WKF_00007 for Process type rule violations.
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

PMSR normative hierarchy checks (errors):
- There MUST be exactly one top-level task in the task collection.
- All non-top tasks MUST be direct or indirect descendants of that single top-level task.
- The Process vstoi:hasTopTask MUST equal that unique top-level task.

PMSR ingestion-output checks (run when KG/API access is available):
- Ingested WKF MUST generate one Study of type hasco:ProcessBasedStudy per STD row.
- That Study MUST have one SOC-STUDENT of type hasco:subjectGroup.
- SOC-STUDENT MUST initially have no study objects.
- Each row in Processes MUST generate one new Process.
- Each generated Process MUST be associated with at least one generated ProcessBasedStudy via hasco:hasProcess linkage semantics.
- The Process top task MUST be the same unique top-level task from the workbook hierarchy.

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
- FAIL if one or more errors.

If running in Copilot workspace:
- Validate file in-place.
- If FAIL, propose minimal concrete fixes sheet-by-sheet.
- Re-validate after fixes and report final status.
- If KG/API endpoints are available, run PMSR ingestion-output checks and report them separately as POST_INGESTION_CHECKS.

If running in ChatGPT:
- Validate logically from uploaded workbook content.
- Return exact failed rules, impacted sheet/row, and corrective actions.
- If no KG/API access exists, explicitly mark ingestion-output checks as NOT_EXECUTED.

---

Optional command block for environments that can run Python:

- python code/wkf_validator.py <WKF_FILE.xlsx>

Use this command output as the source of truth whenever execution is available.
