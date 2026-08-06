# WKF Template Specification v1.2.3

Version: 1.2.3
Date: 2026-08-05
Status: Production
Purpose: Formal specification of Workflow (WKF) metadata templates for RDF/Hub based on HASCO ontology.

## Revision History
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
- RequiredInstruments: tools/resources for tasks
- STD rows: row-level ProcessBasedStudy records and metadata

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
7. RequiredInstruments

## InfoSheet
Two columns exactly: Attribute, Value.
Exactly 8 rows total (1 header + 7 fields), in this exact field order:
1. hasDependencies -> #Namespaces
2. hasStudyDescription -> #STD
3. ProcessStems -> #ProcessStems
4. Processes -> #Processes
5. Tasks -> #Tasks
6. RequiredInstruments -> #RequiredInstruments
7. hasVersion -> numeric regex ^\\d+(\\.\\d+)*$

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
- `<ProcessStem rdfs:label>` MUST come from the ProcessStem referenced by the linked Process (`prov:wasDerivedFrom`).
- `<ProcessStem rdfs:label>` resolution is strict; ingestion MUST fail if the referenced ProcessStem cannot be found or has no rdfs:label.
- `Start Date` remains a strict dependency for label generation.
- Ingestion MUST set effective `Start Date` from the DataFile submission timestamp (the timestamp when the WKF ingestion request is submitted).
- Start Date/date-time resolution is strict; ingestion MUST fail if effective `Start Date` is missing or not parseable as an exact datetime.

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
hasURI, rdf:type, hasco:hascoType, rdfs:label, rdfs:comment, vstoi:hasStatus, vstoi:hasLanguage, vstoi:hasVersion, prov:wasDerivedFrom, vstoi:hasReviewNote, vstoi:hasSIRManagerEmail, vstoi:hasEditorEmail, vstoi:hasSupertask, vstoi:hasSubtask, vstoi:hasTemporalDependency, vstoi:hasRequiredInstrument, hasco:hasImage, hasco:hasWebDocument, vstoi:hasIterationConstraint, vstoi:supportsObjective

Typing rules:
- hasco:hascoType MUST be exactly vstoi:Task.
- rdf:type MUST be vstoi:Task or subclass of vstoi:Task.
- vstoi:hasRequiredInstrument MAY be used as a mirror link to RequiredInstruments rows.
- If vstoi:hasRequiredInstrument is used, it MUST only be populated for tasks whose rdf:type is vstoi:AutomatedTask or vstoi:InteractionTask.
- If vstoi:hasRequiredInstrument is used, each referenced RequiredInstrument URI MUST exist in RequiredInstruments and MUST have vstoi:isRelatedToTask equal to the current task URI.
- For tasks whose rdf:type is not vstoi:AutomatedTask and not vstoi:InteractionTask, vstoi:hasRequiredInstrument MUST be empty.

Temporal operators allowed:
after, before, parallel, choice, independent, disables, interrupts

## RequiredInstruments
Columns:
hasURI, rdf:type, hasco:hascoType, rdfs:label, rdfs:comment, vstoi:usesInstrument, vstoi:isRelatedToTask, vstoi:hasInstrumentConfig

Rules:
- rdf:type MUST be vstoi:RequiredInstrument.
- hasco:hascoType MUST be present with cardinality 1.
- vstoi:usesInstrument MUST be present with cardinality 1 and MUST be URI-like (start with http).
- vstoi:isRelatedToTask MUST be present with cardinality 1 and MUST reference an existing Task URI.
- The task referenced by vstoi:isRelatedToTask MUST have rdf:type vstoi:AutomatedTask or vstoi:InteractionTask.
- One RequiredInstrument row represents exactly one instrument-requirement mapping: one vstoi:usesInstrument to one vstoi:isRelatedToTask.

## URI Patterns
Base: https://pmsr.net/ont/<TEMPLATE_ID>
- ProcessStem: https://pmsr.net/ont/<TEMPLATE_ID>/PST/<ID>
- Process: https://pmsr.net/ont/<TEMPLATE_ID>/PROC/<ID>
- Task: https://pmsr.net/ont/<TEMPLATE_ID>/TSK/<ID>
- RequiredInstrument: https://pmsr.net/ont/<TEMPLATE_ID>/RIN/<ID>

## Cross-Sheet Integrity Requirements
- All required sheets must exist in mandatory order.
- Process prov:wasDerivedFrom must exist in ProcessStems.
- Process vstoi:hasTopTask must exist in Tasks.
- Task supertask/subtask references must resolve inside Tasks.
- Temporal dependency graph for after/before must be acyclic.
- For each Process row created in current WKF, there must be at least one STD row where hasco:hasProcess equals that Process URI.

## Validation Outcome Rules
- PASS only when zero errors.
- Warnings are allowed but must be reported.

## Notes
This reconstructed file was restored from available session context after accidental deletion of an untracked file. It is intended to function as the active source of truth for this workspace.
