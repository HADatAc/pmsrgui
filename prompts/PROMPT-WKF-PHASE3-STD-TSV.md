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
2. Preserve stable identifiers and canonical existing URIs exactly when already present; do not invent alternate namespaces or replacement URIs.
3. Do not invent unsupported facts.
4. Always return the canonical STD header in this exact order: hasURI, hasco:hasProcess, Study ID, Title, Specific Aims, Significance, Institution, Principal Investigator, Email, Start Date, End Date, vstoi:hasLearningObjectives, vstoi:hasCriticalActions, vstoi:hasDebriefingFocus.
5. Preserve supplied hasURI, hasco:hasProcess, Study ID, Title, Institution, Principal Investigator, and Email values. Derive a missing hasco:hasProcess from the current WKF Processes sheet; do not leave it empty.
6. Ensure references to process/task context remain coherent with current WKF.
7. Inspect source-document evidence for learning-focused properties and populate them when supported by the STD schema, especially: `vstoi:hasLearningObjectives`, `vstoi:hasCriticalActions`, `vstoi:hasDebriefingFocus`, `Specific Aims`, `Significance`.
8. If any prior assumptions conflict with current SOURCE_DOCUMENT_CONTENT and current WKF STD content, ignore prior assumptions and use current inputs only.

Output contract (strict):
1. Return ONLY the STD TSV FILE content (the exact .tsv file body): header row plus all STD rows.
2. Use real TAB characters (U+0009) between columns; never commas, pipes, or literal "\\t".
3. Do not return markdown links, data URLs, markdown fences, summaries, JSON, or any extra text.
4. If evidence is partial or uncertain, still return the canonical STD TSV, retaining supplied identity and process values and leaving unsupported optional fields empty.
5. Return ABORT only when required inputs are actually missing.