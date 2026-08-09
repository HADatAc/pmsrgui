# DP2 Specification v3.0 (INS-Aligned, Code-Aware)

Version: 3.0
Date: 2026-08-07
Status: hascoapi implementation-aware and INS-compatible
Sources of truth:
- INS-SPEC.md
- mts/DP2-PMSR-V2.xlsx
- hascoapi DP2 ingestion behavior

## Scope
This document defines DP2 behavior and authoring constraints with the following precedence:

1. hascoapi DP2 ingestion behavior is authoritative for what is accepted and persisted.
2. INS-SPEC.md is authoritative for instrument/component model semantics that DP2 instantiates.
3. DP2-PMSR-V2.xlsx is an interoperability profile and concrete example, not a complete normative template.

## Why This Revision
DP2-PMSR-V2 is operationally useful but not fully populated across all DP2 dimensions. Therefore, this specification must not infer DP2 global rules solely from DP2-PMSR-V2 population density.

Instead:
- use INS-SPEC semantics for model-instance relationships,
- use hascoapi behavior for ingestion strictness,
- use DP2-PMSR-V2 to define a practical compatibility profile.

## 1. DP2 Role Relative to INS
DP2 is the instance-and-deployment layer for models defined in INS.

INS defines models such as:
- instrument models,
- component models,
- and their structural/semantic relationships.

DP2 defines runtime/provenance instances such as:
- instrument instances,
- component instances,
- platform instances,
- and deployment events connecting them.

Core linkage expectation:
1. Instrument instance rows in DP2 reference INS-level instrument models through the `a` field.
2. Deployment rows bind instrument instances to platform instances over time.
3. Component instances (when used) provide instance-level attachment/deployment context for component-level entities defined by INS.

## 2. DP2 Processing Pipeline (Implemented)
DP2 execution entry point:
- Annotator: `AnnotateDP2`

High-level flow:
1. Load and validate InfoSheet catalog keys (`BaseAnnotator.loadCatalog`).
2. Run namespace generation (`IngestionWorker.nameSpaceGen`).
3. Run message generation (`IngestionWorker.messageGen`).
4. Run pre-generation of instance sheets (`IngestionWorker.deployInstancesGen`).
5. Validate selected DP2 references (`AnnotateDP2.validateDP2Instances`).
6. Build generator chain for DP2 entity sheets and commit rows.

Generator chain order in code follows the DP2 key list in `MTSheet`:
1. Deployments
2. Platforms
3. PlatformInstances
4. FieldsOfView
5. InstrumentInstances
6. ComponentInstances
7. SensingPerspective

Notes:
- This is processing order, not required workbook tab order.
- Empty/missing sheets are skipped by `addCustomGeneratorIfSheetExists`.

## 3. InfoSheet Catalog Contract (Implemented)
For metadata type DP2, hascoapi expects these keys in InfoSheet:
- hasDependencies
- Deployments
- Platforms
- PlatformInstances
- FieldsOfView
- InstrumentInstances
- ComponentInstances
- SensingPerspective

Validation semantics:
- Missing expected key: error.
- Extra unexpected key: warning for DP2 (non-fatal), unlike stricter behavior in other MTs.

DP2-specific resilience:
- Catalog mappings are sanitized for common DP2 mapping mistakes.
- If mapping points to the wrong tab and key-named tab exists with data, mapping may be repaired automatically.

## 4. Row Mapping Rules (DP2Generator)
Base row mapping behavior:
- All non-empty header/value pairs are copied into row map.
- Row alias accepted: `uri` can populate `hasURI` when `hasURI` is absent.
- Row is dropped if `hasURI` remains empty.

Automatic fields injected:
- `hasco:hasDataFile` for every DP2 row.
- `vstoi:hasStatus`: from row column when present; otherwise fallback to runtime status parameter.
- `a` is defaulted by element type when missing.
- `hasco:hascoType` and `vstoi:hasSIRManagerEmail` are injected by element type.
- Deployments also receive `hasco:canUpdate`.

Element type defaults for `a`/`hasco:hascoType`:
- deployment -> `vstoi:Deployment`
- platform -> `vstoi:Platform`
- platforminstance -> `vstoi:PlatformInstance`
- fieldofview -> `vstoi:FieldOfView`
- instrumentinstance -> `vstoi:InstrumentInstance`
- componentinstance -> `vstoi:ComponentInstance`

Implication:
- Some workbook fields are advisory because ingestion auto-fills when absent.

## 5. DP2-INS Semantic Contract
This section aligns DP2 semantics with INS-SPEC.

1. INS model to DP2 instance:
- DP2 `InstrumentInstances.a` should point to an INS instrument model URI.

2. Deployment provenance:
- DP2 `Deployments` expresses the event/context in which an instrument instance is deployed at a platform instance.

3. Component instance deployment:
- DP2 `ComponentInstances` (when populated) should represent concrete component-level instances tied to instrument deployment context.

4. Non-substitution rule:
- DP2 does not redefine INS model semantics. DP2 only instantiates and situates them in space/time and operational context.

## 6. PlatformInstances and Deployments (Implementation Detail)
This section captures how platform instances and deployments are currently implemented in hascoapi.

PlatformInstances implementation points:
1. `PlatformInstance` is a first-class VSTOI instance type and supports:
- type/model URI (`a`/`rdf:type` semantics),
- label and serial,
- coordinate triplets with units and characteristics,
- organizational containment via `hasco:partOf`.

2. In DP2 export/generation logic (`DP2PlataformInstances`), the `a` column is intentionally written with the platform class URI (`typeUri`) rather than generic `vstoi:PlatformInstance`.

Deployments implementation points:
1. `Deployment` includes direct links to:
- one instrument instance (`vstoi:hasInstrumentInstance`),
- one platform instance (`vstoi:hasPlatformInstance`),
- zero-to-many component instances (`vstoi:hasComponentInstance`),
- time fields (`vstoi:designedAtTime`, `prov:startedAtTime`, `prov:endedAtTime`).

2. In DP2 workbook generation (`DP2Deployments`), the sheet currently uses a column named `vstoi:hasDetectorInstance` and leaves detector/time columns blank in the generated row writer, even though the Deployment POJO supports component-instance and time fields.

Interpretation:
- Deployment semantics in the domain model are richer than what the current DP2-PMSR-V2 sample populates.
- Therefore DP2-SPEC treats these dimensions as normative capabilities, not optional by ontology design.

## 7. ComponentInstances in Deployment Context
Component instances are the main under-populated area in DP2-PMSR-V2, but they are part of deployment semantics in code.

Normative intent:
1. INS defines component models.
2. DP2 `ComponentInstances` instantiates those models in real deployment context.
3. Deployments should connect relevant component instances to the deployment event.

Current implementation notes:
1. `Deployment` uses `vstoi:hasComponentInstance` (list semantics).
2. DP2 deployment sheet header in generator currently remains `vstoi:hasDetectorInstance` (legacy naming in workbook transform path).
3. `DP2ComponentsInstances` writes `hasURI`, `a` (component class URI), label, and serial.

Practical rule for authoring and review:
- Prefer component-instance aware deployments conceptually.
- Accept that current workbook/profile may not yet materialize this linkage fully in rows.
- Track this as implementation/profile drift, not as DP2 semantic absence.

## 8. DP2-PMSR-V2 Compatibility Profile (Descriptive)
This profile is for interoperability with `mts/DP2-PMSR-V2.xlsx` and is intentionally descriptive.

Observed workbook tabs:
1. InfoSheet
2. Namespace
3. Deployments
4. Platforms
5. PlatformInstances
6. InstrumentInstances
7. ComponentInstances
8. FieldsOfView
9. SensingPerspective

Observed population highlights:
- Deployments and InstrumentInstances are heavily populated.
- PlatformInstances has a small number of populated rows inside a large preallocated range.
- Platforms, ComponentInstances, FieldsOfView, and SensingPerspective are structurally present but unpopulated in this sample.

Interpretation rule:
- Blank/unpopulated sheets in this sample do not imply those dimensions are optional in all DP2 contexts.

Additional profile caveat:
- The sample uses deployment rows centered on instrument+platform linkage, while component-instance linkage is not materially populated.
- This must be interpreted as profile incompleteness, not a contradiction to the deployment model in code.

## 9. Validation Guidance
Use two complementary validation layers.

Layer A: implementation-compatibility checks
1. InfoSheet key set satisfies DP2 expected keys.
2. Rows with missing `hasURI` are rejected (or dropped) by generator behavior.
3. Namespace/message/pre-instance pre-generators complete successfully for intended workflow.

Layer B: semantic consistency checks
1. Every deployment references an existing platform instance and instrument instance in the same workbook context.
2. Every instrument instance model reference (`a`) resolves to a valid INS model URI expected by project governance.
3. Component instances (if present) resolve to intended INS-level component semantics.
4. Where component instances are used, deployment-level linkage should be checked against `vstoi:hasComponentInstance` semantics, while acknowledging current workbook legacy column naming (`vstoi:hasDetectorInstance`) in some generation paths.

Implementation caveat for reviewers:
- `AnnotateDP2.validateDP2Instances` currently validates columns named `hasPlatform`/`hasInstrument` in selected sheets. This behavior should be treated as implementation-specific and reviewed when defining strict workbook QA rules.

## 10. Authoring Guidance for New DP2 Workbooks
1. Keep InfoSheet keys DP2-complete even if some tabs are intentionally empty.
2. Keep INS-to-DP2 linkage explicit:
- instrument instances must carry model URI in `a`.
3. Treat DP2-PMSR-V2 as a practical profile, not a full ontology coverage template.
4. Prefer non-empty canonical URIs over free text for cross-sheet references.
5. Do not rely on tab order for correctness; rely on keys, headers, and references.
6. For component-aware deployments, populate ComponentInstances and preserve explicit deployment association fields in a consistent naming convention (`hasComponentInstance` preferred semantically).

## 11. Compatibility Statement
A DP2 workbook is considered compatible with this specification when:
1. It complies with implemented hascoapi DP2 catalog and generator behavior.
2. It preserves INS-SPEC model-instance semantics for instruments/components.
3. It can be validated as semantically consistent deployment provenance data.

## 12. Traceability Anchors
Primary implementation anchors reviewed for this specification:
- hascoapi/app/org/hascoapi/ingestion/AnnotateDP2.java
- hascoapi/app/org/hascoapi/ingestion/DP2Generator.java
- hascoapi/app/org/hascoapi/ingestion/BaseAnnotator.java
- hascoapi/app/org/hascoapi/ingestion/IngestionWorker.java
- hascoapi/app/org/hascoapi/utils/MTSheet.java
- hascoapi/app/org/hascoapi/entity/pojo/Deployment.java
- hascoapi/app/org/hascoapi/entity/pojo/PlatformInstance.java
- hascoapi/app/org/hascoapi/transform/mt/dp2/DP2Deployments.java
- hascoapi/app/org/hascoapi/transform/mt/dp2/DP2PlataformInstances.java
- hascoapi/app/org/hascoapi/transform/mt/dp2/DP2ComponentsInstances.java

Primary semantic anchor:
- INS-SPEC.md

Primary workbook profile anchor:
- mts/DP2-PMSR-V2.xlsx
