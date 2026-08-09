# DP2 Specification v3.3 (INS-Aligned, Code-Aware)

Version: 3.3
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

5. Proposed ComponentDeployments linkage:
- DP2 `ComponentDeployments` records explicit deployment-level component bindings and complements `Deployments`.
- Base columns:
	- `Deployment URI`
	- `Instrument Slot URI`
	- `Component Instance URI`
- Placement guidance:
	- Add the `ComponentDeployments` tab right after `Deployments` in workbook order for readability.
	- Workbook tab order is still non-normative for ingestion execution.

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

## 6. DP2-PMSR-V2 Compatibility Profile (Descriptive)
This profile is for interoperability with `mts/DP2-PMSR-V2.xlsx` and is intentionally descriptive.

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

Bundled template status (this module):
- `DP2-PMSR-V2.xlsx` and `DP2-PIAGET-V2.xlsx` both include:
	- `ComponentDeployments` in `InfoSheet` mapped to `#ComponentDeployments`
	- a `ComponentDeployments` worksheet with base headers:
		- `Deployment URI`
		- `Instrument Slot URI`
		- `Component Instance URI`

Observed population highlights:
- Deployments and InstrumentInstances are heavily populated.
- PlatformInstances has a small number of populated rows inside a large preallocated range.
- Platforms, ComponentInstances, FieldsOfView, and SensingPerspective are structurally present but unpopulated in this sample.

Interpretation rule:
- Blank/unpopulated sheets in this sample do not imply those dimensions are optional in all DP2 contexts.

## 7. Validation Guidance
Use two complementary validation layers.

Layer A: implementation-compatibility checks
1. InfoSheet key set satisfies DP2 expected keys.
2. Rows with missing `hasURI` are rejected (or dropped) by generator behavior.
3. Namespace/message/pre-instance pre-generators complete successfully for intended workflow.

Layer B: semantic consistency checks
1. Every deployment references an existing platform instance and instrument instance in the same workbook context.
2. Every instrument instance model reference (`a`) resolves to a valid INS model URI expected by project governance.
3. Component instances (if present) resolve to intended INS-level component semantics.
4. For each `ComponentDeployments` row:
- `Deployment URI` resolves to an existing deployment.
- `Instrument Slot URI` resolves to an existing slot entity URI.
- `Component Instance URI` resolves to an existing component instance.
- Slot membership consistency holds:
	- slot belongs to deployment's instrument (directly or via instrument model governance rule).
	- component instance type is allowed for the referenced slot.

## 8. Authoring Guidance for New DP2 Workbooks
1. Keep InfoSheet keys DP2-complete even if some tabs are intentionally empty.
2. Keep INS-to-DP2 linkage explicit:
- instrument instances must carry model URI in `a`.
3. Treat DP2-PMSR-V2 as a practical profile, not a full ontology coverage template.
4. Prefer non-empty canonical URIs over free text for cross-sheet references.
5. Do not rely on tab order for correctness; rely on keys, headers, and references.
6. If using `ComponentDeployments`, prefer the 3-column minimum:
- `Deployment URI`, `Instrument Slot URI`, `Component Instance URI`.

## 9. Compatibility Statement
A DP2 workbook is considered compatible with this specification when:
1. It complies with implemented hascoapi DP2 catalog and generator behavior.
2. It preserves INS-SPEC model-instance semantics for instruments/components.
3. It can be validated as semantically consistent deployment provenance data.

## 10. Assessment of ComponentDeployments vs `vstoi:hasComponentInstance` in Deployments
Assessment target:
1. (3a) Knowledge whether a component instance is deployed.
2. (3b) Knowledge of which instrument instance receives that component instance.
3. (3c) Knowledge of which platform instance receives that component instance.
4. (3d) Organization inference from deployment context.

Result for proposed 3-column `ComponentDeployments` sheet:
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

## 11. Traceability Anchors
Primary implementation anchors reviewed for this specification:
- hascoapi/app/org/hascoapi/ingestion/AnnotateDP2.java
- hascoapi/app/org/hascoapi/ingestion/DP2Generator.java
- hascoapi/app/org/hascoapi/ingestion/BaseAnnotator.java
- hascoapi/app/org/hascoapi/ingestion/IngestionWorker.java
- hascoapi/app/org/hascoapi/utils/MTSheet.java

Primary semantic anchor:
- INS-SPEC.md

Primary workbook profile anchor:
- mts/DP2-PMSR-V2.xlsx
