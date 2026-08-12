PHASE II - TASK MODEL GENERATION (STRICT)

Context:
- This prompt is designed for a plain chat environment (for example, ChatGPT), not a Copilot/agent environment.
- You must work only with the files attached in the current chat.

Mandatory inputs:
1. One supporting source document (the procedure/reference document).
2. One Phase I WKF file.

Hard precondition:
- If either the supporting source document OR the WKF file is missing, STOP immediately and return exactly:
  ABORT: Missing required inputs (supporting document and Phase I WKF are both required).

Single-round execution mode (important):
1. Complete the entire job in one response only (no follow-up questions, no iterative refinement).
2. Prioritize correctness over breadth: prefer a smaller valid model to a larger uncertain model.
3. If evidence is weak, do not invent detailed decompositions; keep the branch compact and record assumptions.
4. Apply this deterministic order and do not deviate:
  - Step A: read source and identify core intervention flow.
  - Step B: add only high-value child tasks needed for safe execution.
  - Step C: connect all parent/child links bidirectionally.
  - Step D: assign task types for 100% of tasks.
  - Step E: run integrity checks and fix any failures before returning.
5. Return only the required outputs (updated WKF + concise summary + assumptions).

Fail-closed generation policy:
1. Do not return a draft that violates size, linkage, or typing rules.
2. If checks fail, simplify and fix internally before returning.
3. Return only a compliant final WKF.

Primary objective:
- Refine the provided Phase I WKF by extracting and encoding a detailed task model from the supporting document.

Compact WKF linkage contract (apply strictly):
1. Keep exactly one root task (the one referenced by Processes.vstoi:hasTopTask).
2. Every non-root task must have exactly one parent in Tasks.vstoi:hasSupertask.
3. Every parent must list all direct children in Tasks.vstoi:hasSubtask.
4. Parent/child links must be bidirectionally consistent (M <-> N columns).
5. No orphan tasks, no disconnected subtrees, and no cycles.
6. 100% of task rows must be reachable from the top task.

Task-model expectations:
1. Treat the WKF's existing single top-level task as the root of the model.
2. Expand this root into a non-flat hierarchy with multiple levels:
  - subtasks
  - sub-subtasks
  - sub-sub-subtasks (when justified by the document)
3. Capture nuanced control-flow semantics where supported by evidence in the document:
  - choice/alternative branches (run one path or another)
  - concurrent/parallel task execution
4. Keep the model clinically and operationally meaningful, not generic.
5. Do not replace the root task with a generic class label; keep it aligned to the selected clinical procedure context.

Strict editing rules:
1. Preserve all valid structures already present in the WKF.
2. Add and link only what is needed for Phase II task-model expansion.
3. Do not duplicate entities that already exist.
4. Keep naming consistent with existing WKF conventions.
5. Maintain URI and cross-sheet integrity.
6. Keep task typing compliant with WKF-SPEC-V2:
  - Tasks.hasco:hascoType = vstoi:Task
  - Tasks.rdf:type = vstoi:Task or subclass of vstoi:Task

Task type assignment policy (mandatory during task-model creation):
1. For every task row in Tasks sheet:
  - Column C (hasco:hascoType) MUST be exactly vstoi:Task.
  - Column B (rdf:type) MUST hold the operational subtype.
2. Parent tasks (tasks with one or more children in vstoi:hasSubtask) MUST be typed as abstract:
  - Tasks.rdf:type = vstoi:AbstractTask.
3. Leaf tasks (tasks with no children) MUST be typed as concrete execution tasks, choosing one of:
  - Interactive leaf: Tasks.rdf:type = vstoi:InteractionTask.
  - Manual leaf: Tasks.rdf:type = vstoi:UserTask.
  - Automated leaf: Tasks.rdf:type = vstoi:AutomatedTask.
4. If a task is typed as vstoi:AutomatedTask or vstoi:InteractionTask, vstoi:hasRequiredInstrument MAY be populated.
5. If a task is NOT typed as vstoi:AutomatedTask and NOT typed as vstoi:InteractionTask, vstoi:hasRequiredInstrument MUST be empty.
6. No task may be returned without explicit typing:
  - Column B (rdf:type) must be non-empty for every task row.
  - Column C (hasco:hascoType) must be vstoi:Task for every task row.
7. Apply typing only after the hierarchy is finalized, then re-check all rows:
  - parent with at least one child => vstoi:AbstractTask
  - leaf without children => one of vstoi:UserTask, vstoi:InteractionTask, vstoi:AutomatedTask

Tiny example row block (parent + 2 leaves):
- Header columns shown: hasURI | rdf:type | hasco:hascoType | rdfs:label | vstoi:hasSupertask | vstoi:hasSubtask
- Parent row:
  https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0100 | vstoi:AbstractTask | vstoi:Task | Prepare patient |  | https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0101 ; https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0102
- Leaf row (manual):
  https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0101 | vstoi:UserTask | vstoi:Task | Explain procedure to patient | https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0100 |
- Leaf row (interactive):
  https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0102 | vstoi:InteractionTask | vstoi:Task | Confirm consent in system | https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0100 |

Mandatory hierarchy-linking rules (no lost tasks):
1. Every non-root task MUST have exactly one valid parent in Tasks.vstoi:hasSupertask.
2. Every parent task MUST explicitly list all of its direct children in Tasks.vstoi:hasSubtask.
3. Parent/child links MUST be bidirectionally consistent:
  - If Child.C(M)=ParentURI in vstoi:hasSupertask, then Parent.C(N) in vstoi:hasSubtask MUST include ChildURI.
  - If Parent.C(N) includes ChildURI, then Child.C(M) MUST equal ParentURI.
4. Use the WKF-SPEC-V2 expected delimiter for multi-value fields in vstoi:hasSubtask, and keep separator usage consistent across all rows.
5. The process top task (Processes.vstoi:hasTopTask) MUST exist in Tasks and MUST have empty vstoi:hasSupertask.
6. The task model MUST form one connected rooted tree under the single top task:
  - exactly one root task (the top task)
  - no orphan tasks
  - no disconnected subtrees
  - no cycles
7. No task row may be left unreachable from the top task.

Operational rule while editing tasks:
1. Whenever you add or move a task:
  - set/update that task's vstoi:hasSupertask
  - update the corresponding parent's vstoi:hasSubtask list in the same edit pass
2. Whenever you remove or reparent a task:
  - remove stale child references from old parent vstoi:hasSubtask
  - add correct child references to new parent vstoi:hasSubtask
3. Do not leave partially updated links at any step in the final output.

Complexity guardrails (do not overcomplicate):
1. Prefer the simplest clinically valid hierarchy supported by evidence.
2. Do not split a step into micro-steps unless the source document clearly requires separate actions, decisions, roles, or timing.
3. Stop decomposition when a leaf task is an executable clinical action with clear actor and objective.
4. Keep depth to what evidence supports; avoid adding extra levels just to increase detail.
5. Use choice/concurrency only when explicitly stated or strongly implied by the source document.
6. If uncertain between two decompositions, choose the smaller model and record the assumption.

Clinical focus guardrails (avoid losing the main procedure focus):
1. Keep the model centered on the core clinical intervention (the procedure itself), not on peripheral steps.
2. Preparation and reporting/documentation tasks are supportive; include only what is necessary for safe execution and traceability.
3. Do not over-decompose preparation/reporting into many micro-steps unless explicitly required by the source document.
4. Group minor preparation details under one preparation parent task when possible.
5. Group minor reporting details under one documentation/reporting parent task when possible.
6. Unless the source document explicitly requires otherwise, ensure the core intervention branch is at least as detailed as preparation + reporting branches.
7. If the model starts to become dominated by preparation/reporting tasks, merge or prune low-value splits and keep only clinically meaningful actions.

Complexity budget (default, unless strong evidence requires exception):
1. Maximum hierarchy depth: 4 levels total from root (root + 3 levels).
2. Keep total leaf tasks focused:
  - Core intervention leaves: target >= 50% of all leaves.
  - Preparation leaves: target <= 30% of all leaves.
  - Reporting/documentation leaves: target <= 20% of all leaves.
3. Hard size caps:
  - Total Tasks rows (excluding header) <= 30.
  - Preparation + reporting/documentation tasks combined <= 40% of all tasks.
  - Children per parent task <= 6 (unless evidence requires more).
4. If any budget threshold is exceeded, reduce complexity by merging low-value splits before returning output.
5. Exceed a budget threshold only when the supporting document clearly demands it; in that case, provide explicit evidence and justification in the summary.

Evidence discipline:
1. Derive tasks and relationships from explicit or strongly implied evidence in the supporting document.
2. If an assumption is unavoidable, keep it minimal and list it explicitly.

Mandatory integrity check before returning output:
1. Verify all task URIs are unique.
2. Verify every vstoi:hasSupertask URI points to an existing task URI.
3. Verify every URI in every vstoi:hasSubtask cell points to an existing task URI.
4. Verify bidirectional parent/child consistency for 100% of task links.
5. Verify exactly one root task and that it matches Processes.vstoi:hasTopTask.
6. Verify all task rows are reachable from the root.
7. Verify no cycles in the hierarchy.
8. If any check fails, fix the WKF before returning it (do not return a partially linked model).
9. Verify typing completeness:
  - 100% of task rows have non-empty rdf:type
  - 100% of task rows have hasco:hascoType = vstoi:Task
  - all parent rows are vstoi:AbstractTask
  - all leaf rows are concrete types (vstoi:UserTask, vstoi:InteractionTask, or vstoi:AutomatedTask)
10. If typing checks fail, fix all task types before returning output.
11. Reject unresolved typing:
  - Do not return any task row with empty rdf:type.
  - Do not return any task row with unresolved/generic operational type.

Output required:
1. Return the updated WKF file for Phase II.
2. Return a concise change summary with:
  - number of tasks added
  - hierarchy depth reached
  - total tasks in final model
  - leaf distribution: core intervention vs preparation vs reporting/documentation (counts and percentages)
  - size caps check (pass/fail + values)
  - where choice branches were added
  - where concurrent tasks were added
  - how procedure focus was preserved (core vs preparation/reporting balance)
  - confirmation that 100% of tasks are connected to the top task
  - confirmation that parent/child bidirectional checks passed
  - confirmation that 100% of tasks were typed (rdf:type + hasco:hascoType)
3. Return a short "Assumptions" section (or "None").

Response format for one-round execution (keep concise):
1. Updated WKF (attached/result file).
2. Summary (max 12 bullets total):
  - tasks added
  - depth reached
  - leaf distribution
  - choice/concurrency locations
  - connectivity check result
  - typing completeness result
  - focus/budget compliance
3. Assumptions (max 5 bullets, or "None").
