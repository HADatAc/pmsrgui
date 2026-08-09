# INS Specification v3.0 (Code-Aligned)

Version: 3.0
Date: 2026-08-06
Status: hascoapi implementation-aligned
Source of truth: hascoapi codebase

## Scope
This document specifies INS behavior as implemented in hascoapi.

Precedence rule:
- If this document conflicts with INS template examples or workbook conventions, hascoapi code behavior is authoritative.

## Lifecycle Status
INS ingestion is implemented but deprecated in code in favor of DSG + DA-SOC.

## INS Processing Pipeline
INS execution entry point:
- Annotator: AnnotateINS

High-level flow:
1. Load and validate InfoSheet catalog keys.
2. Run namespace generation.
3. Run annotation generation.
4. Build generator chain for INS entity sheets.
5. Commit rows into the target named graph.

Generator chain order in code:
1. ResponseOptions
2. CodeBooks
3. CodeBookSlots
4. ComponentStems
5. Components
6. SlotElements
7. Instruments

Notes:
- This is processing order, not workbook tab order.
- Missing or empty entity sheets are skipped by addCustomGeneratorIfSheetExists.

## InfoSheet Catalog Contract (Implemented)
For metadata type INS, hascoapi validates presence of these InfoSheet keys:
- hasDependencies
- Instruments
- SlotElements
- ComponentStems
- Components
- CodeBooks
- CodeBookSlots
- ResponseOptions
- Annotations
- AnnotationStems

Validation semantics:
- Missing expected key: error.
- Extra unexpected key: error.
- Validation is key-set based; workbook tab order is not validated.

Important distinction:
- hasDependencies is treated as an InfoSheet field key, not a sheet name.

## Namespaces Handling (Implemented)
Namespaces are processed by IngestionWorker.nameSpaceGen.

Resolution behavior:
1. Try catalog key Namespaces.
2. Else try catalog key Namespace.
3. Else probe workbook sheets named Namespaces then Namespace.

Row semantics in Namespaces/Namespace sheet:
- hasPrefix: required for row processing.
- hasNameSpace: required only when creating a new namespace entry.
- hasFormat: optional.
- hasSource: optional.

Behavior summary:
- If abbreviation exists with existing metadata/triples, row is skipped.
- If abbreviation exists without source metadata, source fields may be updated and optionally ingested.
- If abbreviation does not exist and hasNameSpace is present, namespace entry is created.
- Missing namespace sheet logs warning and returns false.

Pipeline nuance:
- AnnotateINS invokes nameSpaceGen but does not abort chain construction based on its return value.

## Annotation Sheets Handling (Implemented)
Annotation generation is run before the main INS chain.

Behavior:
- If AnnotationStems/Annotations mapping is missing or invalid, annotation generation is skipped with logging.
- Main INS chain can still run.

## Workbook Order vs Implemented Validation
Implemented behavior does not enforce workbook sheet tab order.

What is enforced:
- InfoSheet key presence and allowed key set for INS.

What is not enforced:
- Physical workbook tab ordering.

## Row Mapping Rules by Generator

### INSGenerator (element types)
INSGenerator is used for:
- instrument
- componentstem
- codebook
- responseoption
- annotationstem
- annotation
- slotelement

Base row mapping behavior:
- All non-empty header/value pairs are copied into the row map.
- Header typo correction is applied for prefix vsoit: -> vstoi:.
- Row is dropped if hasURI is missing or empty.

Automatic fields by element type:
- instrument:
  - hasco:hascoType = vstoi:Instrument
  - optional vstoi:hasStatus from runtime status parameter
  - vstoi:hasSIRManagerEmail injected from DataFile context
- componentstem:
  - hasco:hascoType = vstoi:ComponentStem
  - optional vstoi:hasStatus
  - vstoi:hasSIRManagerEmail
- codebook:
  - hasco:hascoType = vstoi:Codebook
  - optional vstoi:hasStatus
  - vstoi:hasSIRManagerEmail
- responseoption:
  - hasco:hascoType = vstoi:ResponseOption
  - optional vstoi:hasStatus
  - vstoi:hasSIRManagerEmail
- annotationstem:
  - hasco:hascoType = vstoi:AnnotationStem
  - optional vstoi:hasStatus
  - vstoi:hasSIRManagerEmail
- annotation:
  - hasco:hascoType = vstoi:Annotation
  - optional vstoi:hasStatus
  - vstoi:hasSIRManagerEmail
- slotelement:
  - vstoi:hasSIRManagerEmail injected

Instrument-specific normalization in INSGenerator:
- Attempts to resolve vstoi:hasAnatomy and vstoi:hasFidelity from header variants.
- Splits semicolon or pipe-delimited multi-values for hasAnatomy/hasFidelity into list values.
- Performs post-commit anatomy verification queries for debug logging.

### ComponentGenerator
Component rows are processed by a dedicated generator.

Behavior:
- Copies all non-empty header/value pairs.
- If rdfs:label exists, duplicates label text into vstoi:hasContent.
- Injects rdf:subClassOf = vstoi:Component.
- Injects hasco:hascoType = vstoi:Component.
- Optional vstoi:hasStatus from runtime status parameter.
- Injects vstoi:hasSIRManagerEmail.

### CodeBookSlotGenerator
CodeBookSlot rows are processed by a dedicated generator.

Behavior:
- Copies all non-empty header/value pairs.
- Requires vstoi:belongsTo to proceed for a row group.
- Computes slot priority sequentially per belongsTo group in input order.
- Overrides/adds:
  - vstoi:hasPriority (computed)
  - hasURI = belongsTo + /CBS/ + priority
  - rdf:type = vstoi:CodebookSlot
  - hasco:hascoType = vstoi:CodebookSlot
  - rdfs:label = CodebookSlot <priority>
  - rdfs:comment = CodeBookSlot <priority> of codebook with URI <belongsTo>
- Injects vstoi:hasSIRManagerEmail.

Implication:
- In CodeBookSlots ingestion, workbook values for hasURI, hasPriority, rdf:type, hasco:hascoType, rdfs:label, and rdfs:comment are not strictly authoritative because generator logic recomputes/overrides them.

## Implemented INS Concept Model (POJO-Level)
This section summarizes concept relationships implemented in POJOs.

### Instrument and Container structure
- Instrument extends Container.
- Container includes:
  - vstoi:hasFirst
  - vstoi:belongsTo
  - vstoi:hasNext
  - vstoi:hasPrevious
  - vstoi:hasPriority
  - vstoi:hasShortName
  - vstoi:hasLanguage
  - vstoi:hasVersion
  - vstoi:hasStatus

### SlotElements
- SlotElement is an interface implemented by:
  - ContainerSlot
  - Subcontainer
- Chain semantics are represented by hasNext/hasPrevious with belongsTo and optional hasPriority.
- ContainerSlot defines vstoi:hasComponent for component attachment.
- Subcontainer is a slot element variant with container semantics.

Association semantics:
- Instrument-to-component association is modeled through slot elements:
  - Instrument -> hasFirst -> slot chain
  - slot -> hasComponent -> Component (for ContainerSlot rows)

### Components and stems
- Component includes:
  - vstoi:hasComponentStem
  - vstoi:hasCodebook
  - vstoi:isAttributeOf
  - lifecycle/editorial metadata fields
- ComponentStem includes:
  - vstoi:hasContent
  - vstoi:hasLanguage
  - vstoi:hasVersion
  - provenance/editorial metadata fields

Construction semantics:
- A Component links to its template definition through vstoi:hasComponentStem.
- A Component may link to a response model through vstoi:hasCodebook.

### Codebooks and response options
- CodebookSlot includes:
  - vstoi:belongsTo
  - vstoi:hasResponseOption
  - vstoi:hasPriority
- ResponseOption includes content/language/version and editorial fields.

Composition semantics:
- Codebook -> CodebookSlot rows define ordered positions.
- CodebookSlot -> hasResponseOption optionally binds a response option.

### Annotations
- Annotation includes:
  - vstoi:belongsTo
  - vstoi:hasAnnotationStem
  - vstoi:hasPosition
  - vstoi:hasContentWithStyle
- AnnotationStem includes:
  - vstoi:hasContent
  - vstoi:hasLanguage
  - vstoi:hasVersion
  - provenance/editorial metadata fields

## Implemented Strictness Statement
Implemented strictness is generator-driven and key-set driven, not workbook-observation driven.

Therefore:
- Required/optional behavior in practice is determined by:
  - InfoSheet key validation rules,
  - row-drop or row-rewrite logic in generators,
  - and POJO/model acceptance at commit time.
- Workbook-level observed non-empty rates are descriptive only and not normative for hascoapi behavior.

## Compatibility Notes
- Namespaces tab named Namespace or Namespaces is supported by fallback probing.
- Header typo vsoit: is corrected to vstoi: at ingestion.
- INS ingestion includes automatic enrichment fields (for example hasco:hascoType, vstoi:hasSIRManagerEmail, optional hasStatus).

## INS-PMSR-V2 Compatibility Profile
This profile makes this specification fully compatible with INS-PMSR-V2.xlsx while staying faithful to hascoapi behavior.

### Sheet Tabs in INS-PMSR-V2.xlsx
Observed workbook tab order:
1. InfoSheet
2. Namespaces
3. Instruments
4. SlotElements
5. ComponentStems
6. Components
7. CodeBooks
8. CodeBookSlots
9. ResponseOptions
10. Annotations
11. AnnotationStems

Compatibility rule:
- Keep this tab order in generated/edited workbooks for interoperability.
- hascoapi does not require tab order for validation, but this order is the compatible profile for INS-PMSR-V2.

### InfoSheet rows required for INS in hascoapi
Required catalog keys for INS:
- hasDependencies
- Instruments
- SlotElements
- ComponentStems
- Components
- CodeBooks
- CodeBookSlots
- ResponseOptions
- Annotations
- AnnotationStems

INS-PMSR-V2-compatible InfoSheet mapping values:
- hasDependencies -> #Namespaces
- Instruments -> #Instruments
- SlotElements -> #SlotElements
- ComponentStems -> #ComponentStems
- Components -> #Components
- CodeBooks -> #CodeBooks
- CodeBookSlots -> #CodeBookSlots
- ResponseOptions -> #ResponseOptions
- Annotations -> #Annotations
- AnnotationStems -> #AnnotationStems

### Sheet headers compatible with INS-PMSR-V2 and hascoapi
The following header sets are compatible with both INS-PMSR-V2 and implemented ingestion.

Instruments headers:
- hasURI
- hasco:hascoType
- rdfs:subClassOf
- rdfs:label
- vstoi:hasShortName
- vstoi:hasLanguage
- vstoi:hasVersion
- hasco:hasMaker
- rdfs:comment
- hasco:hasImage
- vstoi:hasFidelity
- vstoi:hasAnatomy
- vstoi:maxLoggedMeasurements
- vstoi:minOperatingTemperature
- vstoi:maxOperatingTemperature
- hasco:hasOperatingTemperatureUnit
- hasco:hasWebDocument
- vstoi:hasFirst

SlotElements headers:
- hasURI
- hasco:hascoType
- vstoi:belongsTo
- vstoi:hasComponent
- vstoi:hasNext
- vstoi:hasPrevious
- vstoi:hasFirst
- vstoi:hasPriority
- rdfs:label

ComponentStems headers:
- hasURI
- hasco:hascoType
- rdfs:subClassOf
- rdfs:label
- vstoi:hasContent
- vstoi:hasLanguage
- vstoi:hasVersion
- hasco:hasMaker
- rdfs:comment:
- hasco:hasImage
- hasco:hasWebDocument

Components headers:
- hasURI
- hasco:hascoType
- rdf:type
- rdfs:label
- vstoi:hasComponentStem
- vstoi:hasCodebook
- vstoi:isAttributeOf
- hasco:hasWebDocument

CodeBooks headers:
- hasURI
- hasco:hascoType
- rdf:type
- rdfs:label
- vstoi:hasContent
- vstoi:hasLanguage
- vstoi:hasVersion
- rdfs:comment
- hasco:hasImage
- hasco:hasWebDocument

CodeBookSlots headers:
- hasURI
- hasco:hascoType
- rdf:type
- vstoi:belongsTo
- vstoi:hasResponseOption
- vstoi:hasPriority

ResponseOptions headers:
- hasURI
- hasco:hascoType
- rdf:type
- rdfs:label
- vstoi:hasContent
- vstoi:hasLanguage
- vstoi:hasVersion
- hasco:hasMaker
- rdfs:comment
- hasco:hasImage
- hasco:hasWebDocument

Annotations headers:
- hasURI
- hasco:hascoType
- rdf:type
- rdfs:label
- vstoi:belongsTo
- vstoi:hasAnnotationStem
- vstoi:hasPosition
- vstoi:hasContentWithStyle
- rdfs:comment
- hasco:hasImage
- hasco:hasWebDocument

AnnotationStems headers:
- hasURI
- hasco:hascoType
- rdf:type
- rdfs:label
- vstoi:hasContent
- vstoi:hasLanguage
- vstoi:hasVersion
- rdfs:comment
- hasco:hasImage
- hasco:hasWebDocument

Compatibility notes for these headers:
- The header rdfs:comment: with trailing colon is accepted as a workbook-compatible header in ComponentStems.
- hascoapi header typo correction only auto-fixes vsoit: to vstoi:.

### Generator-aware compatibility constraints
To be both workbook-compatible and code-compatible, apply these constraints:

1. CodeBookSlots sheet:
- Provide vstoi:belongsTo for each row.
- Treat hasURI, vstoi:hasPriority, rdf:type, hasco:hascoType, rdfs:label, and rdfs:comment as generator-controlled at ingestion.

2. Components sheet:
- Provide hasURI and rdfs:label.
- hascoapi will mirror rdfs:label to vstoi:hasContent and inject rdf:subClassOf/hasco:hascoType.

3. Instrument rows:
- Use vstoi:hasAnatomy and vstoi:hasFidelity with single or multi-value strings.
- Semicolon or pipe-delimited values are supported by ingestion normalization.

4. Namespaces sheet:
- Prefer using hasPrefix and hasNameSpace in every row.
- hasFormat and hasSource are optional and govern optional ontology ingestion behavior.

### Compatibility statement
An INS workbook is INS-PMSR-V2 compatible under this specification when:
- It follows the observed INS-PMSR-V2 tab order and headers above, and
- Its InfoSheet includes the INS key set required by hascoapi, and
- It respects generator-aware constraints where hascoapi computes or overrides values.

## Summary
This file is aligned to implemented hascoapi INS behavior, including:
- catalog validation,
- namespace and annotation pre-processing,
- generator execution order,
- row-level transformations,
- and concept-level linkage semantics.

It additionally defines an explicit compatibility profile for INS-PMSR-V2.xlsx so workbook conventions and implemented ingestion semantics are aligned in one specification.

## Appendix A: Rule-to-Code Traceability
This appendix maps key specification rules to exact hascoapi implementation anchors.

### A.1 Pipeline and validation
1. INS entry point and chain construction order:
- hascoapi/app/org/hascoapi/ingestion/AnnotateINS.java:51
- hascoapi/app/org/hascoapi/ingestion/AnnotateINS.java:71
- hascoapi/app/org/hascoapi/ingestion/AnnotateINS.java:73
- hascoapi/app/org/hascoapi/ingestion/AnnotateINS.java:75
- hascoapi/app/org/hascoapi/ingestion/AnnotateINS.java:77
- hascoapi/app/org/hascoapi/ingestion/AnnotateINS.java:79
- hascoapi/app/org/hascoapi/ingestion/AnnotateINS.java:81
- hascoapi/app/org/hascoapi/ingestion/AnnotateINS.java:83

2. InfoSheet key-set validation (presence and extra-key checks, no tab-order enforcement):
- hascoapi/app/org/hascoapi/ingestion/BaseAnnotator.java:64
- hascoapi/app/org/hascoapi/ingestion/BaseAnnotator.java:71
- hascoapi/app/org/hascoapi/ingestion/BaseAnnotator.java:84

3. INS expected catalog keys (including hasDependencies):
- hascoapi/app/org/hascoapi/utils/MTSheet.java:17

4. Sheet skip behavior when mapped sheet is empty/missing:
- hascoapi/app/org/hascoapi/ingestion/BaseAnnotator.java:102
- hascoapi/app/org/hascoapi/ingestion/BaseAnnotator.java:141

5. Namespaces preprocessing and fallback sheet resolution:
- hascoapi/app/org/hascoapi/ingestion/IngestionWorker.java:869
- hascoapi/app/org/hascoapi/ingestion/IngestionWorker.java:873

6. Annotation preprocessing and skip behavior:
- hascoapi/app/org/hascoapi/ingestion/IngestionWorker.java:1189
- hascoapi/app/org/hascoapi/ingestion/IngestionWorker.java:1238

### A.2 Generator mapping and overrides
1. INSGenerator row copy, header normalization, hasURI gating:
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:29
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:69
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:158

2. Header typo auto-fix vsoit: -> vstoi::
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:199
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:201
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:208

3. Instrument-specific property normalization and multi-value splitting:
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:91
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:95
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:100
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:243

4. Instrument hascoType injection:
- hascoapi/app/org/hascoapi/ingestion/INSGenerator.java:117

5. ComponentGenerator enrichment/override behavior:
- hascoapi/app/org/hascoapi/ingestion/ComponentGenerator.java:10
- hascoapi/app/org/hascoapi/ingestion/ComponentGenerator.java:54
- hascoapi/app/org/hascoapi/ingestion/ComponentGenerator.java:80

6. CodeBookSlotGenerator computed fields and overrides:
- hascoapi/app/org/hascoapi/ingestion/CodeBookSlotGenerator.java:10
- hascoapi/app/org/hascoapi/ingestion/CodeBookSlotGenerator.java:59
- hascoapi/app/org/hascoapi/ingestion/CodeBookSlotGenerator.java:60
- hascoapi/app/org/hascoapi/ingestion/CodeBookSlotGenerator.java:61
- hascoapi/app/org/hascoapi/ingestion/CodeBookSlotGenerator.java:63
- hascoapi/app/org/hascoapi/ingestion/CodeBookSlotGenerator.java:64

### A.3 POJO concept semantics
1. Instrument/Container slot-chain fields:
- hascoapi/app/org/hascoapi/entity/pojo/Instrument.java:35
- hascoapi/app/org/hascoapi/entity/pojo/Container.java:30
- hascoapi/app/org/hascoapi/entity/pojo/Container.java:37
- hascoapi/app/org/hascoapi/entity/pojo/Container.java:52
- hascoapi/app/org/hascoapi/entity/pojo/Container.java:55
- hascoapi/app/org/hascoapi/entity/pojo/Container.java:58

2. SlotElement implementations and component attachment:
- hascoapi/app/org/hascoapi/entity/pojo/SlotElement.java:3
- hascoapi/app/org/hascoapi/entity/pojo/ContainerSlot.java:26
- hascoapi/app/org/hascoapi/entity/pojo/ContainerSlot.java:31
- hascoapi/app/org/hascoapi/entity/pojo/Subcontainer.java:35

3. Component construction from stem and codebook:
- hascoapi/app/org/hascoapi/entity/pojo/Component.java:22
- hascoapi/app/org/hascoapi/entity/pojo/Component.java:24

4. CodebookSlot optional response option binding:
- hascoapi/app/org/hascoapi/entity/pojo/CodebookSlot.java:22
- hascoapi/app/org/hascoapi/entity/pojo/CodebookSlot.java:27

5. Annotation and annotation-stem linkage:
- hascoapi/app/org/hascoapi/entity/pojo/Annotation.java:25
- hascoapi/app/org/hascoapi/entity/pojo/Annotation.java:30
- hascoapi/app/org/hascoapi/entity/pojo/AnnotationStem.java:20

## Appendix B: Reviewer Compatibility Checklist
Use this quick checklist for INS review against hascoapi + INS-PMSR-V2 profile.

### B.1 Structural checklist
- InfoSheet contains all required INS keys (including hasDependencies).
- Workbook uses INS-PMSR-V2 tab names and tab order.
- Namespaces sheet exists as Namespaces or Namespace.
- Entity sheet names in InfoSheet map to existing workbook tabs.

### B.2 Header checklist
- Each entity sheet uses the header set defined in the compatibility profile.
- ComponentStems accepts rdfs:comment: as workbook-compatible header.
- No unsupported predicate-prefix typo exists except vsoit:, which is auto-corrected.

### B.3 Generator-aware checklist
- CodeBookSlots rows provide vstoi:belongsTo.
- Reviewer expects CodeBookSlots hasURI/priority/type/label/comment to be generator-controlled at ingestion.
- Components rows provide hasURI and rdfs:label so hasContent mirroring works.
- Instrument anatomy/fidelity multi-values use semicolon or pipe when multiple values are intended.

### B.4 Semantic linkage checklist
- Instrument slot-chain is represented (hasFirst plus slot hasNext/hasPrevious where applicable).
- SlotElements.belongsTo references target instrument URI.
- SlotElements.hasComponent references component URI when component attachment is present.
- Components.hasComponentStem references existing stem URI.
- Components.hasCodebook, when present, references existing codebook URI.
- CodeBookSlots.hasResponseOption, when present, references existing response option URI.
- Annotation rows (if used) reference valid annotation stem and container URIs.

### B.5 Ingestion outcome checklist
- Empty optional sheets are acceptable and skipped.
- Namespace processing warnings are reviewed separately from core entity ingestion.
- Final graph contains expected generated/overridden CodeBookSlot triples.
