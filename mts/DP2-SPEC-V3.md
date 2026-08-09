# DP2 Specification v3.4 (INS-Aligned, Code-Aware)

Version: 3.4
Date: 2026-08-08
Status: hascoapi implementation-aware and INS-compatible
Sources of truth:
- INS-SPEC.md
- mts/DP2-PMSR-V3.xlsx
- hascoapi DP2 ingestion behavior

## Scope
This document defines DP2 behavior and authoring constraints with the following precedence:

1. hascoapi DP2 ingestion behavior is authoritative for what is accepted and persisted.
2. INS-SPEC.md is authoritative for instrument/component model semantics that DP2 instantiates.
3. DP2-PMSR-V3.xlsx is the structural source of truth for DP2 MT authoring in this revision.

## Why This Revision
DP2-PMSR-V3 is now used as the workbook source of truth for DP2 MT structure while INS-SPEC and hascoapi code remain authoritative for semantic and ingestion behavior.

Instead:
- use INS-SPEC semantics for model-instance relationships,
- use hascoapi behavior for ingestion strictness,
- use DP2-PMSR-V3 to define the concrete DP2 MT structure and URI composition profile.

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
2. ComponentDeployments
3. Platforms
4. PlatformInstances
5. FieldsOfView
6. InstrumentInstances
7. ComponentInstances
8. SensingPerspective

Notes:
- This is processing order, not required workbook tab order.
- Empty/missing sheets are skipped by `addCustomGeneratorIfSheetExists`.

Transactional rule:
- DP2 ingestion is transactional.
- Any DP2 ingest failure invalidates the whole unit of work for that file.
- No partial DP2 persistence is considered a successful outcome.

## 3. InfoSheet Catalog Contract (Implemented)
For metadata type DP2, hascoapi expects these keys in InfoSheet:
- hasDependencies
- Deployments
- ComponentDeployments
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

Implementation note:
- `ComponentDeployments` is ingested through a dedicated generator and is expected in DP2 InfoSheet catalogs.
- The ingestion layer materializes deployment-level and slot-level component-instance links for downstream querying.

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

5. ComponentDeployment is a first-class concept with its own URI:
- DP2 `ComponentDeployments` records explicit deployment-level component bindings and complements `Deployments`.
- Each row is an entity with its own `hasURI` and `rdf:type` = `vstoi:ComponentDeployment`.
- The row links deployment context and installed component through:
	- `hasco:hascoDeployment` -> deployment URI
	- `hasco:hasInstrumentSlot` -> instrument slot URI
	- `hasco:hasComponentInstance` -> component instance URI
- In DP2-PMSR-V3, this is implemented and populated, not only proposed.

6. Optional denormalization columns:
- `Instrument Instance URI` (optional; derivable from `Deployment URI`)
- `Platform Instance URI` (optional; derivable from `Deployment URI`)
- `Component Type URI` (optional; derivable from `Component Instance URI`)

7. Implemented persistence mapping (hascoapi):
- For each `ComponentDeployments` row:
	- `Deployment URI` + `Component Instance URI` is materialized as:
		- `<DeploymentURI> vstoi:hasComponentInstance <ComponentInstanceURI>`
	- `Instrument Slot URI` + `Component Instance URI` is materialized as:
		- `<InstrumentSlotURI> vstoi:hasComponentInstance <ComponentInstanceURI>`

This keeps `Deployments` query-friendly while preserving slot-level assignment on slot resources.

Rationale:
- `Deployment URI` anchors platform/time context through deployment provenance.
- `Instrument Slot URI` preserves slot-level assignment and avoids ambiguity.
- `Component Instance URI` captures concrete installed component identity.

## 6. DP2-PMSR-V3 Structural Profile (Normative)
This section is updated to the DP2-PMSR-V3 structural profile and is normative for DP2 MT authoring in this revision.

Observed workbook tabs:
1. InfoSheet
2. Namespace
3. Deployments
4. ComponentDeployments
5. Platforms
6. PlatformInstances
7. InstrumentInstances
8. ComponentInstances
9. FieldsOfView
10. SensingPerspective

DP2-PMSR-V3 header profile by sheet:
1. `Deployments`
- `hasURI`, `a`, `rdfs:label`, `vstoi:hasPlatformInstance`, `vstoi:hasInstrumentInstance`, `vstoi:designedAtTime`, `prov:startedAtTime`, `prov:endedAtTime`
2. `ComponentDeployments`
- `hasURI`, `rdf:type`, `hasco:hascoDeployment`, `hasco:hasInstrumentSlot`, `hasco:hasComponentInstance`
3. `Platforms`
- `hasURI`, `rdfs:subClassOf`, `rdfs:label`, `hasco:hasMaker`, `rdfs:comment`, `hasco:hasImage`, `vstoi:hasWebDocumentation`
4. `PlatformInstances`
- `hasURI`, `a`, `hasco:hascoType`, `rdfs:label`, `vstoi:hasSerialNumber`, `hasco:hasFirstCoordinate`, `hasco:hasFirstCoordinateUnit`, `hasco:hasFirstCoordinateCharacteristic`, `hasco:hasSecondCoordinate`, `hasco:hasSecondCoordinateUnit`, `hasco:hasSecondCoordinateCharacteristic`, `hasco:hasThirdCoordinate`, `hasco:hasThirdCoordinateUnit`, `hasco:hasThirdCoordinateCharacteristic`, `hasco:partOf`
5. `InstrumentInstances`
- `hasURI`, `a`, `hasco:hascoType`, `rdfs:label`, `vstoi:hasSerialNumber`, `skos:definition`, `owl:sameAs`, `vstoi:hasOwner`
6. `ComponentInstances`
- `hasURI`, `a`, `hasco:hascoType`, `rdfs:label`, `vstoi:hasSerialNumber`, `vstoi:isInstrumentAttachment`
7. `FieldsOfView`
- `hasURI`, `a`, `hasco:hasGeometry`, `rdfs:label`, `hasco:isFieldOfViewOf`, `hasco:hasFirstParameter`, `hasco:hasFirstParameterUnit`, `hasco:hasFirstParameterCharacteristic`, `hasco:hasSecondParameter`, `hasco:hasSecondParameterUnit`, `hasco:hasSecondParameterCharacteristic`, `hasco:hasThirdParameter`, `hasco:hasThirdParameterUnit`, `hasco:hasThirdParameterCharacteristic`
8. `SensingPerspective`
- `hasURI`, `a`, `vstoi:perspectiveOf`, `hasco:hasPerspectiveEntity`, `hasco:hasPerspectiveCharacteristic`, `vstoi:hasAccuracyPercentage`, `vstoi:hasAccuracyR2`, `vstoi:hasOutputResolution`, `vstoi:hasMaxResponseTimeValue`, `hasco:hasResponseTimeUnit`, `vstoi:hasLowRangeValue`, `vstoi:hasHighRangeValue`

`hasco:hascoType` usage in V3:
1. Present in `PlatformInstances`.
2. Present in `InstrumentInstances`.
3. Present in `ComponentInstances`.
4. Not a standard column in `Deployments` or `ComponentDeployments` headers.

Observed population highlights:
- Deployments, ComponentDeployments, InstrumentInstances, and ComponentInstances are populated.
- PlatformInstances is populated.
- Platforms, FieldsOfView, and SensingPerspective are structurally present and may be empty in specific files.

Interpretation rule:
- Blank/unpopulated sheets in this sample do not imply those dimensions are optional in all DP2 contexts.

## 7. URI Composition Rules (DP2-PMSR-V3 Source-of-Truth Profile)
General rules:
1. Every entity row must provide non-empty `hasURI` (or `uri` alias that maps to `hasURI`).
2. URIs should be CURIEs or absolute IRIs resolvable by workbook namespace mappings.
3. Cross-sheet reference fields must point to existing URIs in their target sheets.

Per-element URI composition rules:
1. `Deployments.hasURI`
- Compose as deployment identifier URI, typically `pmsr:DPL<id>`.
- `<id>` should be unique in workbook scope.
2. `ComponentDeployments.hasURI`
- Compose as component-deployment identifier URI, typically `pmsr:DPC<id>`.
- This is a first-class entity URI, distinct from deployment URI and component URI.
3. `Platforms.hasURI`
- Compose as platform model/class URI (not platform instance URI).
- When local to project namespace, use a stable class identifier URI under the workbook prefix.
4. `PlatformInstances.hasURI`
- Compose as concrete platform-instance URI (for room/lab/site instance identity), e.g. a stable location/resource token under `pmsr:`.
5. `InstrumentInstances.hasURI`
- Compose as instrument-instance identifier URI, typically `pmsr:INI<id>`.
6. `ComponentInstances.hasURI`
- Compose as component-instance identifier URI, typically `pmsr:CPI<id>`.
7. `FieldsOfView.hasURI`
- Compose as field-of-view entity URI with a stable unique token under workbook namespace.
8. `SensingPerspective.hasURI`
- Compose as sensing-perspective entity URI with a stable unique token under workbook namespace.

Reference field composition rules:
1. `Deployments.vstoi:hasPlatformInstance` must reference a `PlatformInstances.hasURI` value.
2. `Deployments.vstoi:hasInstrumentInstance` must reference an `InstrumentInstances.hasURI` value.
3. `ComponentDeployments.hasco:hascoDeployment` must reference a `Deployments.hasURI` value.
4. `ComponentDeployments.hasco:hasInstrumentSlot` must reference a valid instrument slot URI.
5. `ComponentDeployments.hasco:hasComponentInstance` must reference a `ComponentInstances.hasURI` value.

## 8. Validation Guidance
Use two complementary validation layers.

Layer A: implementation-compatibility checks
1. InfoSheet key set satisfies DP2 expected keys.
2. Rows with missing `hasURI` are rejected (or dropped) by generator behavior.
3. Namespace/message/pre-instance pre-generators complete successfully for intended workflow.

Layer B: semantic consistency checks
1. Every deployment references an existing platform instance and instrument instance in the same workbook context.
2. Every instrument instance model reference (`a`) resolves to a valid URI under any KG-registered namespace, including `ncit`.
3. Namespace validity for model URIs is determined by registered KG namespaces, not only by INS-specific model namespaces.
4. Component instances (if present) resolve to intended INS-level component semantics.
5. For each `ComponentDeployments` row:
- `Deployment URI` resolves to an existing deployment.
- `Instrument Slot URI` resolves to an existing slot entity URI.
- `Component Instance URI` resolves to an existing component instance.
- Slot membership consistency holds:
	- slot belongs to deployment's instrument (directly or via instrument model governance rule).
	- component instance type is allowed for the referenced slot.

Layer C: transactional completion checks
1. DP2 completion status is `PROCESSED` only when the full DP2 transaction succeeds.
2. If any DP2 validation or generation step fails, DP2 processing result is `ERROR` and the transaction must not leave partial committed state.

## 9. Authoring Guidance for New DP2 Workbooks
1. Keep InfoSheet keys DP2-complete even if some tabs are intentionally empty.
2. Keep INS-to-DP2 linkage explicit:
- instrument instances must carry model URI in `a`.
3. Treat DP2-PMSR-V3 as the structural source of truth for DP2 MT shape.
4. Prefer non-empty canonical URIs over free text for cross-sheet references.
5. Do not rely on tab order for correctness; rely on keys, headers, and references.
6. `ComponentDeployments` rows must have their own `hasURI` and should include `rdf:type` = `vstoi:ComponentDeployment`.
7. Include `hasco:hascoType` columns in `PlatformInstances`, `InstrumentInstances`, and `ComponentInstances`.

## 10. Compatibility Statement
A DP2 workbook is considered compatible with this specification when:
1. It complies with implemented hascoapi DP2 catalog and generator behavior.
2. It preserves INS-SPEC model-instance semantics for instruments/components.
3. Instrument model URIs in `InstrumentInstances.a` resolve under a KG-registered namespace, including `ncit`.
4. It can be validated as semantically consistent deployment provenance data.
5. It is ingested transactionally as a single DP2 unit of work.

## 11. Assessment of ComponentDeployments vs `vstoi:hasComponentInstance` in Deployments
Assessment target:
1. (3a) Knowledge whether a component instance is deployed.
2. (3b) Knowledge of which instrument instance receives that component instance.
3. (3c) Knowledge of which platform instance receives that component instance.
4. (3d) Organization inference from deployment context.

Result for `ComponentDeployments` as implemented first-class entity sheet:
1. (3a) Yes. Presence of a `Component Instance URI` row gives explicit deployed/not-deployed evidence.
2. (3b) Yes. `Deployment URI` links to `Deployments`, which identifies the instrument instance.
3. (3c) Yes. `Deployment URI` links to `Deployments`, which identifies the platform instance.
4. (3d) Yes with governance assumption. If component ownership follows instrument ownership, organization can be inferred from the deployment's instrument/platform ownership context.

Comparison with `vstoi:hasComponentInstance` directly in `Deployments`:
1. Strength: deployment context is direct and naturally includes platform/time through deployment.
2. Weakness: if only a flat list is used, slot assignment is not captured.
3. Weakness: current implementation had data-population fragility in deployment component list handling.

Copilot assessment:
1. A separate `ComponentDeployments` sheet with `Deployment URI` + `Instrument Slot URI` is better than a flat deployment component list for provenance quality and slot traceability.
2. This design resolves the main ambiguity by anchoring each component assignment to a specific deployment and slot.
3. Keep `vstoi:hasComponentInstance` in `Deployments` as a derived convenience view for fast reads, not as the authoritative source of slot-level truth.

## 12. Traceability Anchors
Primary implementation anchors reviewed for this specification:
- hascoapi/app/org/hascoapi/ingestion/AnnotateDP2.java
- hascoapi/app/org/hascoapi/ingestion/DP2Generator.java
- hascoapi/app/org/hascoapi/ingestion/BaseAnnotator.java
- hascoapi/app/org/hascoapi/ingestion/IngestionWorker.java
- hascoapi/app/org/hascoapi/utils/MTSheet.java

Primary semantic anchor:
- INS-SPEC.md

Primary workbook profile anchor:
- mts/DP2-PMSR-V3.xlsx
