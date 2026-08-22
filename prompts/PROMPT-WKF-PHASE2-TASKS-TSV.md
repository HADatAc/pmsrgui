PHASE II - TASK MODEL GENERATION (GENERATION-ONLY OUTPUT)

Inputs required:
1. WKF-SPEC-V3 content.
2. Original source document content.
3. Phase I Tasks sheet TSV content.

If actual source document content is missing (filename/path/title/metadata/attachment reference only), return exactly:
ABORT: Missing required inputs (supporting document and Phase I WKF are both required).

If WKF-SPEC-V3 content or Phase I Tasks sheet TSV is missing, return exactly:
ABORT: Missing required inputs (WKF-SPEC-V3 and Phase I Tasks sheet are both required).

Consistency gate:
If Phase I Tasks sheet clearly refers to a different clinical process than the source document, return exactly:
ABORT: Inconsistent inputs (Phase I Tasks sheet and source document refer to different processes).

Objective:
Using the source document content, the Phase I Tasks sheet, and WKF-SPEC-V3 rules, generate the updated Phase II Tasks sheet TSV.

Mandatory task rules:
1. hasco:hascoType must be exactly vstoi:Task for every task row.
2. rdf:type must be exactly one of:
- vstoi:AbstractTask
- vstoi:ManualTask
- vstoi:AutomatedTask
- vstoi:InteractionTask
3. Parent tasks must be vstoi:AbstractTask.
4. Leaf tasks must be ManualTask, AutomatedTask, or InteractionTask.
5. Exactly one top-level task (empty vstoi:hasSupertask).
6. Every non-top task has exactly one parent.
7. hasSupertask/hasSubtask links are bidirectionally consistent.
8. No disconnected tasks and no hierarchy cycles.
9. Allowed temporal operators only: after, before, parallel, choice, independent, disables, interrupts.
10. after/before graph must be acyclic.

usesComponentInstance policy for this phase:
1. Use column vstoi:usesComponentInstance (never vstoi:hasRequiredInstrument).
2. For Phase II, keep vstoi:usesComponentInstance empty unless explicitly evidenced and unambiguous.
3. If present, each value must be URI-like (starts with http).

Output contract (strict):
1. Return exactly these 3 sections in this order:
- OUTPUT_TASKS_SHEET_TSV
- OUTPUT_SUMMARY_JSON
- OUTPUT_ASSUMPTIONS
2. OUTPUT_TASKS_SHEET_TSV must contain ONLY the Tasks header row and task rows as real TSV (tab-separated).
3. Do NOT output a full WKF workbook, multiple sheets, markdown links, JSON object outside the required section, or .xlsx content.
4. Do NOT include InfoSheet, Namespaces, STD, ProcessStems, Processes, or RequiredInstruments in the response.
5. Preserve valid existing Phase I task rows and relationships unless changes are required by WKF-SPEC-V3 rules or source evidence.
6. Do NOT repeat or summarize any packet sections (header, goal, prompt, inputs, or TSV blocks).
7. Copy/link packaging is handled by application logic, not by this generation response.
8. If you cannot comply exactly, return exactly one line:
- ABORT: Could not produce compliant Phase II Tasks TSV output.
9. Do not return markdown fences, summaries, or any extra text.