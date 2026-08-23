PHASE IV - TASK MODEL UPDATE WITH COMPONENT INSTANCES (TSV-ONLY OUTPUT)

Inputs required:
1. Original source document content.
2. Current full Tasks sheet TSV (LATEST_TASKS_SHEET_TSV).
3. Full organization component-instance context (INSTRUMENT_INSTANCES_WITH_COMPONENT_INSTANCES), including:
- DEPLOYMENT_COMPONENT_TO_INSTRUMENT_MAP
- UNMAPPED_COMPONENT_INSTANCES_WITH_NO_DEPLOYMENT_LINK
- ALL_COMPONENT_INSTANCES_IN_ORGANIZATION

If any input is missing, return exactly:
ABORT: Missing required inputs (supporting document and WKF content are both required).

Objective:
Review inputs 2 and 3, then return an updated full Tasks sheet TSV that finalizes simulation-ready task/component linkage using vstoi:usesComponentInstance.

Assignment rules (strict):
1. Use DEPLOYMENT_COMPONENT_TO_INSTRUMENT_MAP as the primary compatibility source.
2. For each task row, choose vstoi:usesComponentInstance values compatible with the task's instrument context when available.
3. Only use component URIs that exist in ALL_COMPONENT_INSTANCES_IN_ORGANIZATION.
4. If a row has no clear deployment-compatible match, keep current value unless evidence justifies change.

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
1. Return ONLY the full updated Tasks TSV file body (header row plus all task rows).
2. Use real TAB separators only.
3. Treat your response as a .tsv file ready for upload with Task Model Update.
4. Do NOT return markdown links, data URLs, markdown fences, summaries, JSON, or any extra text.