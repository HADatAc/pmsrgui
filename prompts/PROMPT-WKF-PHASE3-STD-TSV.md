PHASE III - SCENARIO UPDATE (STD SHEET, TSV-ONLY OUTPUT)

Inputs required:
1. Original source document content.
2. Current WKF table content.

If any input is missing, return exactly:
ABORT: Missing required inputs (supporting document and WKF content are both required).

Objective:
Produce the STD sheet content aligned with the scenario/source document, preserving WKF-SPEC-V3 semantics and URI consistency.

Rules:
1. Update only STD-sheet-relevant information.
2. Preserve stable identifiers and valid existing URIs whenever possible.
3. Do not invent unsupported facts.
4. Keep column semantics consistent with the provided STD sheet header.
5. Ensure references to process/task context remain coherent with current WKF.

Output contract (strict):
1. Do NOT display the STD TSV inline.
2. Return exactly one markdown link labeled Copy Scenario.
3. The link target must be a data URL containing the full URL-encoded STD TSV:
- data:text/plain;charset=utf-8,<URL-ENCODED-TSV>
4. The encoded payload must contain ONLY STD TSV (header row plus all STD rows).
5. Do not return markdown fences, summaries, or any extra text.