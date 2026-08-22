PHASE IV - TASK MODEL UPDATE WITH COMPONENT INSTANCES (TSV-ONLY OUTPUT)

Inputs required:
1. Original source document content.
2. Current WKF table content.

If any input is missing, return exactly:
ABORT: Missing required inputs (supporting document and WKF content are both required).

Objective:
Return an updated Tasks sheet TSV that finalizes simulation-ready task/component linkage using vstoi:usesComponentInstance.

Mandatory task rules:
1. hasco:hascoType must be exactly vstoi:Task for every task row.
2. rdf:type must be exactly one of:
- vstoi:AbstractTask
- vstoi:ManualTask
- vstoi:AutomatedTask
- vstoi:InteractionTask
3. Exactly one top-level task.
4. Every non-top task has exactly one parent.
5. hasSupertask/hasSubtask links are bidirectionally consistent.
6. No disconnected tasks and no cycles.

usesComponentInstance policy (strict for this phase):
1. Use vstoi:usesComponentInstance (never vstoi:hasRequiredInstrument).
2. Populate for AutomatedTask or InteractionTask when evidence exists.
3. Keep empty for non-eligible task types.
4. Every populated value must be URI-like (starts with http).
5. Do not remove previously valid usesComponentInstance values unless they are clearly invalid.

Output contract (strict):
1. Do NOT display the Tasks TSV inline.
2. Return exactly one markdown link labeled Copy Simulation Tasks.
3. The link target must be a data URL containing the full URL-encoded Tasks TSV:
- data:text/plain;charset=utf-8,<URL-ENCODED-TSV>
4. The encoded payload must contain ONLY Tasks TSV (header row plus all task rows).
5. Do not return markdown fences, summaries, or any extra text.