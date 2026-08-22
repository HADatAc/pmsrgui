# WKF Template Specification v2.0.0

Version: 2.0.0
Date: 2026-08-11
Status: Production
Purpose: Formal specification of Workflow (WKF) metadata templates for RDF/Hub based on HASCO ontology.

## Revision History
- v2.0.0 (2026-08-11): Deprecated vstoi:hasRequiredInstrument and replaced it with vstoi:usesComponentInstance in Tasks. Deprecated full RequiredInstruments sheet. Deprecated InfoSheet dependency entry RequiredInstruments -> #RequiredInstruments.
- v1.2.3 (2026-08-05): Added normative ProcessBasedStudy rdfs:label composition rule: `<Real user name>'s <ProcessStem rdfs:label> at <YYYY/MM/DD> <HH:MM>`, sourced from ingesting user identity, ProcessStem label, and WKF ingestion submission timestamp.
- v1.2.2 (2026-08-03): Updated STD schema to include hasURI and hasco:hasProcess. Defined row-level ProcessBasedStudy-to-Process linking semantics, including support for reusing previously ingested Process URIs (no in-file Process row required for reused process).
- v1.2.1 (2026-08-03): Corrected Tasks typing semantics: hasco:hascoType is fixed to vstoi:Task (task archetype), while rdf:type can be vstoi:Task or any subclass of vstoi:Task.
- v1.2.0 (2026-08-03): Added mandatory STD sheet and InfoSheet hasStudyDescription -> #STD dependency. Moved educational and study registration properties from Processes to STD.
- v1.1.2 (2026-07-28): Added mandatory Process type rule coherence with ProcessStem.
- v1.1.1 (2026-07-28): Added normative PMSR requirements and ingestion outputs/links.
- v1.1 (2026-07-16): Added study metadata columns to support ProcessBasedStudy integration.
- v1.0 (2026-06-30): Initial release.

## Executive Summary
WKF describes clinical, educational, or operational workflows in a machine-readable spreadsheet format.

Core entities:
- ProcessStems: abstract workflow templates
- Processes: concrete workflow instances
- Tasks: workflow steps with hierarchy and temporal constraints
- STD rows: row-level ProcessBasedStudy records and metadata

Deprecated entity:
- RequiredInstruments: deprecated in v2.0.0

## PMSR Normative Rules
1. There MUST be exactly one top-level task in the task collection.
2. All non-top tasks MUST be direct or indirect descendants of the unique top-level task.
3. The pmsr namespace MUST be exactly https://pmsr.net/ont/.
4. Each STD data row MUST generate one hasco:ProcessBasedStudy during ingestion.
5. Each row in Processes defines a new Process to be created during ingestion.
6. Each new Process row MUST have a corresponding STD row linked by hasco:hasProcess.
7. STD hasco:hasProcess MAY reference:
   - a Process created by a row in the same WKF, or
   - an existing Process URI from a previous WKF ingestion.
8. Process vstoi:hasTopTask MUST equal the unique top-level task.
9. Each Process MUST have exactly one hasco:hascoType, coherent with the referenced ProcessStem hasco:hascoType.
10. InfoSheet MUST include hasStudyDescription -> #STD.
11. Each generated ProcessBasedStudy rdfs:label MUST be composed as `<Real user name>'s <ProcessStem rdfs:label> at <YYYY/MM/DD> <HH:MM>`.

## File Format
- Extension: .xlsx
- Encoding: UTF-8
- Sheet order is mandatory.

## Required Sheet Order
1. InfoSheet
2. Namespaces
3. STD
4. ProcessStems
5. Processes
6. Tasks

Deprecated sheet:
- RequiredInstruments (formerly sheet index 7 in v1.2.3)

## InfoSheet
Two columns exactly: Attribute, Value.
Exactly 7 rows total (1 header + 6 fields), in this exact field order:
1. hasDependencies -> #Namespaces
2. hasStudyDescription -> #STD
3. ProcessStems -> #ProcessStems
4. Processes -> #Processes
5. Tasks -> #Tasks
6. hasVersion -> numeric regex ^\\d+(\\.\\d+)*$

Deprecated field:
- RequiredInstruments -> #RequiredInstruments

Prohibited fields:
- hasWorkflowID
- label
- comment
- versionNumber

## Namespaces
Minimum required rows:
- hasco -> http://hadatac.org/ont/hasco#
- vstoi -> http://hadatac.org/ont/vstoi#
- prov -> http://www.w3.org/ns/prov#
- rdfs -> http://www.w3.org/2000/01/rdf-schema#
- rdf -> http://www.w3.org/1999/02/22-rdf-syntax-ns#
- owl -> http://www.w3.org/2002/07/owl#
- xsd -> http://www.w3.org/2001/XMLSchema#
- pmsr -> https://pmsr.net/ont/

## STD (sheet index 3)
Layout:
- Row 1: optional title/helper row
- Row 2: effective headers
- Row 3+: data rows

Row 2 headers and semantics (B-O):
- B hasURI (URI, required): ProcessBasedStudy URI for the row
- C hasco:hasProcess (URI, required): linked Process URI
- D Study ID (string, required): SHOULD start with STD-
- E Title (string, required)
- F Specific Aims (string, optional)
- G Significance (string, optional)
- H Institution (URI, required)
- I Principal Investigator (URI, required)
- J Email (email, optional)
- K Start Date (system-assigned at ingestion submission time; strict dependency for label generation)
- L End Date (YYYY-MM-DD, optional)
- M vstoi:hasLearningObjectives (string/list, optional)
- N vstoi:hasCriticalActions (string/list, optional)
- O vstoi:hasDebriefingFocus (string/list, optional)

Normative linkage semantics:
- hasco:hasProcess is mandatory in every non-empty STD row.
- If hasco:hasProcess points to a Process row in the same WKF, that Process is newly created by this ingestion.
- If hasco:hasProcess points to a preexisting Process URI, no local Processes row is required for that URI.

ProcessBasedStudy rdfs:label semantics:
- Output format MUST be `<Real user name>'s <ProcessStem rdfs:label> at <YYYY/MM/DD> <HH:MM>`.
- `<Real user name>` MUST come from the ingesting user identity (submission context), not from STD PI values.
- `<ProcessStem rdfs:label>` MUST come from the ProcessStem referenced by the linked Process (prov:wasDerivedFrom).
- `<ProcessStem rdfs:label>` resolution is strict; ingestion MUST fail if the referenced ProcessStem cannot be found or has no rdfs:label.
- Start Date remains a strict dependency for label generation.
- Ingestion MUST set effective Start Date from the DataFile submission timestamp (the timestamp when the WKF ingestion request is submitted).
- Start Date/date-time resolution is strict; ingestion MUST fail if effective Start Date is missing or not parseable as an exact datetime.

## ProcessStems
Columns:
hasURI, rdf:type, hasco:hascoType, rdfs:label, rdfs:comment, vstoi:hasStatus, vstoi:hasContent, vstoi:hasLanguage, vstoi:hasVersion, prov:wasDerivedFrom, prov:wasGeneratedBy, vstoi:hasReviewNote, vstoi:hasSIRManagerEmail, vstoi:hasEditorEmail, hasco:hasImage, hasco:hasWebDocument

Rules:
- rdf:type MUST be vstoi:ProcessStem.

## Processes
Columns:
hasURI, rdf:type, hasco:hascoType, rdfs:label, rdfs:comment, vstoi:hasStatus, vstoi:hasLanguage, vstoi:hasVersion, prov:wasDerivedFrom, vstoi:hasReviewNote, vstoi:hasSIRManagerEmail, vstoi:hasEditorEmail, vstoi:hasTopTask, hasco:hasImage, hasco:hasWebDocument

Rules:
- Each non-empty row defines a new Process.
- rdf:type MUST be vstoi:Process.
- hasco:hascoType MUST be present with cardinality 1.
- prov:wasDerivedFrom MUST reference an existing ProcessStem URI.
- vstoi:hasTopTask MUST reference an existing Task URI.
- Study and educational metadata are defined in STD, not in Processes.

## Tasks
Columns:
hasURI, rdf:type, hasco:hascoType, rdfs:label, rdfs:comment, vstoi:hasStatus, vstoi:hasLanguage, vstoi:hasVersion, prov:wasDerivedFrom, vstoi:hasReviewNote, vstoi:hasSIRManagerEmail, vstoi:hasEditorEmail, vstoi:hasSupertask, vstoi:hasSubtask, vstoi:hasTemporalDependency, vstoi:usesComponentInstance, hasco:hasImage, hasco:hasWebDocument, vstoi:hasIterationConstraint, vstoi:supportsObjective

Typing rules:
- hasco:hascoType MUST be exactly vstoi:Task.
- rdf:type MUST be exactly one of: vstoi:AbstractTask, vstoi:ManualTask, vstoi:AutomatedTask, vstoi:InteractionTask.
- vstoi:hasRequiredInstrument is deprecated and MUST NOT be used in new templates.
- vstoi:usesComponentInstance MAY be used to link tasks directly to component instance URIs.
- If vstoi:usesComponentInstance is used, it MUST only be populated for tasks whose rdf:type is vstoi:AutomatedTask or vstoi:InteractionTask.
- If vstoi:usesComponentInstance is used, each referenced value MUST be a URI-like component instance (start with http).
- For tasks whose rdf:type is not vstoi:AutomatedTask and not vstoi:InteractionTask, vstoi:usesComponentInstance MUST be empty.

Task hierarchy and cardinality rules:
- A top-level task is a task with no value for vstoi:hasSupertask.
- Every non-top task MUST have exactly one immediate parent via vstoi:hasSupertask.
- A child task MUST NOT have more than one immediate parent.
- A parent task MUST NOT list the same child more than once in vstoi:hasSubtask.
- If task P lists task C in vstoi:hasSubtask, then C.vstoi:hasSupertask MUST equal P.
- If task C has vstoi:hasSupertask equal to P, then P.vstoi:hasSubtask MUST include C.
- If a task has no child tasks, it MUST NOT have rdf:type vstoi:AbstractTask.
- If rdf:type is vstoi:AbstractTask, it MUST have at least one child task.
- If rdf:type is vstoi:AbstractTask and temporal dependency is parallel, choice, or independent, it MUST have at least two child tasks.

Temporal operators allowed:
after, before, parallel, choice, independent, disables, interrupts

## Deprecated RequiredInstruments
The RequiredInstruments sheet and its semantics are deprecated in v2.0.0.

Deprecated columns and semantics:
- hasURI, rdf:type, hasco:hascoType, rdfs:label, rdfs:comment, vstoi:usesInstrument, vstoi:isRelatedToTask, vstoi:hasInstrumentConfig

Migration guidance:
- Replace any prior mirror use of vstoi:hasRequiredInstrument with direct task-level vstoi:usesComponentInstance values.

## URI Patterns
Base: https://pmsr.net/ont/<TEMPLATE_ID>
- ProcessStem: https://pmsr.net/ont/<TEMPLATE_ID>/PST/<ID>
- Process: https://pmsr.net/ont/<TEMPLATE_ID>/PROC/<ID>
- Task: https://pmsr.net/ont/<TEMPLATE_ID>/TSK/<ID>

Deprecated:
- RequiredInstrument: https://pmsr.net/ont/<TEMPLATE_ID>/RIN/<ID>

## Cross-Sheet Integrity Requirements
- Ingestion-time validation MUST enforce that all required sheets exist in mandatory order.
- Ingestion-time validation MUST enforce that each Process prov:wasDerivedFrom value resolves to an existing ProcessStem.
- Ingestion-time validation MUST enforce that each Process vstoi:hasTopTask value resolves to an existing Task.
- Ingestion-time validation MUST enforce that all Task vstoi:hasSupertask and vstoi:hasSubtask references resolve inside Tasks.
- Ingestion-time validation MUST enforce that the temporal dependency graph induced by after and before is acyclic.
- Ingestion-time validation MUST enforce that each Task rdf:type is exactly one of: vstoi:AbstractTask, vstoi:ManualTask, vstoi:AutomatedTask, vstoi:InteractionTask.
- Ingestion-time validation MUST enforce that a top-level task is any task with empty vstoi:hasSupertask, and that there is exactly one top-level task in the task collection.
- Ingestion-time validation MUST enforce that every non-top task has exactly one immediate parent via vstoi:hasSupertask.
- Ingestion-time validation MUST enforce that no child task is attached to more than one immediate parent.
- Ingestion-time validation MUST enforce bidirectional consistency between vstoi:hasSupertask and vstoi:hasSubtask for every parent-child pair.
- Ingestion-time validation MUST enforce that all tasks are in the unique top-level task hierarchy, with no isolated or disconnected tasks.
- Ingestion-time validation MUST enforce that every vstoi:AbstractTask has at least one child task.
- Ingestion-time validation MUST enforce that if a task is vstoi:AbstractTask and its temporal dependency operator is parallel, choice, or independent, that task has at least two child tasks.
- Ingestion-time validation MUST enforce that the task hierarchy formed by vstoi:hasSupertask and inverse vstoi:hasSubtask is acyclic.
- Ingestion-time validation MUST enforce that for each Process row created in the current WKF, there is at least one STD row where hasco:hasProcess equals that Process URI.

## Validation Outcome Rules
- PASS only when zero errors.
- Warnings are allowed but must be reported.

## Notes
This file is derived from WKF-SPEC-V2.md and introduces the v2.0.0 deprecations and replacements for task-to-instrument linkage.
