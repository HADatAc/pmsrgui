OPTIONAL ACTIVITY - TASK MODEL VERIFICATION/CORRECTION (TSV-ONLY OUTPUT)

Inputs required:
1. Original source document content.
2. Current WKF table content.
3. Validation output with broken rule IDs/messages (optional but recommended).

If required inputs are missing, return exactly:
ABORT: Missing required inputs (supporting document and WKF content are both required).

Objective:
Fix only task-model inconsistencies while preserving valid structure and URI stability.

Mandatory correction rules:
1. hasco:hascoType must be exactly vstoi:Task for every task row.
2. rdf:type must be exactly one of:
- vstoi:AbstractTask
- vstoi:ManualTask
- vstoi:AutomatedTask
- vstoi:InteractionTask
3. Exactly one top-level task.
4. Every non-top task has exactly one parent.
5. hasSupertask/hasSubtask links must stay bidirectionally consistent.
6. No disconnected tasks, no hierarchy cycles, and acyclic after/before dependencies.
7. vstoi:usesComponentInstance only for AutomatedTask or InteractionTask.
8. Keep vstoi:usesComponentInstance empty for non-eligible task types.

Output contract (strict):
1. Return only the Tasks sheet TSV.
2. Include header row and all task rows.
3. Do not return markdown fences.
4. Do not return explanations, bullets, summaries, or extra text.
5. Do not include any other sheet content.
