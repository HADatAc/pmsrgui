PHASE II - TASK MODEL GENERATION (SINGLE ROUND, COMPACT)

Context:
- Use only the files attached in this chat.
- Execute in one response only (no questions, no iterative refinement).

Mandatory inputs:
1. One supporting source document.
2. One Phase I WKF file.

Hard precondition:
- If either input is missing, return exactly:
  ABORT: Missing required inputs (supporting document and Phase I WKF are both required).

Objective:
- Refine the Phase I WKF into a Phase II task model that is connected, correctly typed, and clinically focused.

Minimal deterministic workflow (5 steps):
1. Identify the core clinical intervention flow from the source document.
2. Add only high-value tasks required for safe execution.
3. Keep preparation/reporting compact unless explicitly required by evidence.
4. Connect all tasks with consistent parent/child links.
5. Assign task types for 100% of task rows, then run integrity checks and fix all failures before returning.

Fail-closed generation policy:
1. Do not return a draft that violates size, linkage, or typing rules.
2. If checks fail, simplify and fix internally before returning.
3. Return only a compliant final WKF.
4. If compliance still cannot be achieved in one round, return exactly:
  ABORT: Noncompliant output (CTT size/typing/linkage constraints not satisfied).

Essential WKF linkage rules:
1. Exactly one root task, equal to Processes.vstoi:hasTopTask.
2. Every non-root task has exactly one Tasks.vstoi:hasSupertask parent.
3. Every parent lists all direct children in Tasks.vstoi:hasSubtask.
4. Parent/child links are bidirectionally consistent (M <-> N columns).
5. No orphan tasks, no disconnected subtrees, no cycles.
6. 100% of task rows are reachable from the root.

Task typing rules (exact encoding):
1. For every Tasks row:
  - Column C (hasco:hascoType) MUST be exactly vstoi:Task.
  - Column B (rdf:type) MUST be vstoi:Task or a subclass.
2. Parent task (has children): rdf:type = vstoi:AbstractTask.
3. Leaf task (no children): rdf:type MUST be one of:
  - vstoi:UserTask (manual)
  - vstoi:InteractionTask (interactive)
  - vstoi:AutomatedTask (automated)
4. vstoi:hasRequiredInstrument:
  - MAY be populated only for vstoi:AutomatedTask or vstoi:InteractionTask.
  - MUST be empty for all other task rdf:type values.

Anti-overcomplication budget (default):
1. Max depth: 4 levels total (root + 3).
2. Leaf distribution targets:
  - Core intervention >= 50%
  - Preparation <= 30%
  - Reporting/documentation <= 20%
3. Hard size caps:
  - Total Tasks rows (excluding header) <= 30.
  - Preparation + reporting/documentation tasks combined <= 40% of all tasks.
  - Children per parent task <= 6 (unless evidence requires more).
4. If exceeded without strong evidence, merge/prune low-value splits.

Typing gate (must pass before return):
1. 100% of Tasks rows must have non-empty rdf:type.
2. 100% of Tasks rows must have hasco:hascoType = vstoi:Task.
3. Parent rows must be rdf:type = vstoi:AbstractTask.
4. Leaf rows must be exactly one of:
  - vstoi:UserTask
  - vstoi:InteractionTask
  - vstoi:AutomatedTask
5. Do not return any row with generic or unresolved typing.

Tiny example (parent + 2 leaves):
- hasURI | rdf:type | hasco:hascoType | rdfs:label | vstoi:hasSupertask | vstoi:hasSubtask
- https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0100 | vstoi:AbstractTask | vstoi:Task | Prepare patient |  | https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0101 ; https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0102
- https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0101 | vstoi:UserTask | vstoi:Task | Explain procedure to patient | https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0100 |
- https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0102 | vstoi:InteractionTask | vstoi:Task | Confirm consent in system | https://pmsr.net/ont/<TEMPLATE_ID>/TSK/0100 |

Output required:
1. Updated WKF file for Phase II.
2. Concise summary (max 12 bullets):
  - tasks added
  - depth reached
  - total tasks
  - leaf distribution (core/prep/reporting counts and %)
  - size caps check (pass/fail + values)
  - choice/concurrency locations
  - 100% connectivity confirmation
  - bidirectional linkage confirmation
  - 100% typing confirmation (typed rows / total rows)
3. Assumptions (max 5 bullets or None).
