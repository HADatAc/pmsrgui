PHASE IV - TASK MODEL UPDATE WITH COMPONENT INSTANCES (TSV-ONLY OUTPUT)

Inputs required:
1. Original source document content.
2. Current full Tasks sheet TSV (LATEST_TASKS_SHEET_TSV).
3. Full organization component-instance context (INSTRUMENT_INSTANCES_WITH_COMPONENT_INSTANCES), including:
- DEPLOYMENT_COMPONENT_TO_INSTRUMENT_MAP
- UNMAPPED_COMPONENT_INSTANCES_WITH_NO_DEPLOYMENT_LINK
- ALL_COMPONENT_INSTANCES_IN_ORGANIZATION

If any input is missing, return exactly:
ABORT: Missing required inputs (supporting document and Phase I WKF are both required).

Objective:
Review inputs 2 and 3, then immediately return an updated full Tasks sheet TSV that finalizes simulation-ready task/component linkage using vstoi:usesComponentInstance. Do not say you are ready, do not ask for confirmation, and do not wait for another instruction.

Assignment rules (strict):
1. Use DEPLOYMENT_COMPONENT_TO_INSTRUMENT_MAP as the primary compatibility source.
2. Every vstoi:InteractionTask or vstoi:AutomatedTask row MUST have one or more vstoi:usesComponentInstance URI values in the output.
3. Only use component URIs that exist in ALL_COMPONENT_INSTANCES_IN_ORGANIZATION; select deployment-compatible URIs whenever available.
4. If no exact deployment-compatible component exists, assign the closest semantically relevant URI from ALL_COMPONENT_INSTANCES_IN_ORGANIZATION. Do not change the task type to avoid assignment.

Task interaction review:
1. Review every leaf task against the source document and component inventory before assigning components.
2. Change a leaf task from vstoi:ManualTask to vstoi:InteractionTask only when it is a learner action whose execution or outcome is directly observable by a simulator component.
3. For this secretion-aspiration workflow, review tasks that operate or check the aspirator, pre-oxygenate the patient, perform suction, monitor patient response, or readapt ventilator/O2 as potential InteractionTask candidates.
4. Keep preparation, communication, hygiene, and non-observable manual actions as vstoi:ManualTask.

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
2. Populate every AutomatedTask and InteractionTask with one or more compatible component-instance URIs, including tasks changed to InteractionTask by the interaction review above.
3. Keep empty for non-eligible task types.
4. Every populated value must be URI-like (starts with http).
5. Before returning, verify that every InteractiveTask or AutomatedTask row has one or more vstoi:usesComponentInstance URIs, including rows without an exact deployment match.
6. Do not remove previously valid usesComponentInstance values unless they are clearly invalid.

Output contract (strict):
1. Return ONLY the full updated Tasks TSV file body (header row plus all task rows).
2. Use real TAB separators only.
3. Treat your response as a .tsv file ready for upload with Task Model Update.
4. Use exactly the same header columns from LATEST_TASKS_SHEET_TSV; do not add vstoi:hasRequiredInstrument or any other extra column.
5. Every data row must have exactly the same number of TAB-separated columns as the header.
6. Do NOT return markdown links, data URLs, markdown fences, summaries, JSON, or any extra text.