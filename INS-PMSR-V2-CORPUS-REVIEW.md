# INS-PMSR-V2 Workbook Review Using Documentation Corpus

Workbook under review: [pmsrgui/mts/INS-PMSR-V2.xlsx](pmsrgui/mts/INS-PMSR-V2.xlsx)

Review corpus used:
- Internal corpus index: [pmsrgui/INSTRUMENT-DOCUMENTATION-COLLECTION-BASELINE.md](pmsrgui/INSTRUMENT-DOCUMENTATION-COLLECTION-BASELINE.md#L1)
- External curated sources: [pmsrgui/EXTERNAL-INSTRUMENT-DOCS-CURATION.md](pmsrgui/EXTERNAL-INSTRUMENT-DOCS-CURATION.md#L1)
- INS spec baseline: [pmsrgui/INS-SPEC.md](pmsrgui/INS-SPEC.md#L1)

## Sheet Coverage Summary
- InfoSheet: 0 rows with hasURI
- Namespaces: 0 rows with hasURI
- Instruments: 75 rows with hasURI
- SlotElements: 154 rows with hasURI
- ComponentStems: 233 rows with hasURI
- Components: 166 rows with hasURI
- CodeBooks: 25 rows with hasURI
- CodeBookSlots: 61 rows with hasURI
- ResponseOptions: 61 rows with hasURI
- Annotations: 0 rows with hasURI
- AnnotationStems: 0 rows with hasURI

## Findings (Ordered by Severity)

### High 1: CodeBookSlots Missing ResponseOption Links
- Count: 13
- Risk: Codebooks cannot produce deterministic selectable states; downstream UI/runtime may receive underspecified value spaces.
- Corpus implication: use manufacturer/user documentation in [pmsrgui/EXTERNAL-INSTRUMENT-DOCS-CURATION.md](pmsrgui/EXTERNAL-INSTRUMENT-DOCS-CURATION.md#L1) to define valid response states before ingestion.
- Affected slots:
  - row 2 | pmsr:/CBK1738087261869295/CBS/1 | codebook=pmsr:/CBK1738087261869295 | priority=1
  - row 3 | pmsr:/CBK1738087261869295/CBS/2 | codebook=pmsr:/CBK1738087261869295 | priority=2
  - row 4 | pmsr:/CBK1738087261869295/CBS/3 | codebook=pmsr:/CBK1738087261869295 | priority=3
  - row 5 | pmsr:/CBK1738190767525383/CBS/1 | codebook=pmsr:/CBK1738190767525383 | priority=1
  - row 6 | pmsr:/CBK1738190767525383/CBS/2 | codebook=pmsr:/CBK1738190767525383 | priority=2
  - row 7 | pmsr:/CBK1738190767525383/CBS/3 | codebook=pmsr:/CBK1738190767525383 | priority=3
  - row 8 | pmsr:/CBK1738190767525383/CBS/4 | codebook=pmsr:/CBK1738190767525383 | priority=4
  - row 11 | pmsr:/CBK1738186252574965/CBS/1 | codebook=pmsr:/CBK1738186252574965 | priority=1
  - row 12 | pmsr:/CBK1738186252574965/CBS/2 | codebook=pmsr:/CBK1738186252574965 | priority=2
  - row 13 | pmsr:/CBK1738187728844755/CBS/1 | codebook=pmsr:/CBK1738187728844755 | priority=1
  - row 14 | pmsr:/CBK1738187728844755/CBS/2 | codebook=pmsr:/CBK1738187728844755 | priority=2
  - row 16 | pmsr:/CBK1738088009248155/CBS/1 | codebook=pmsr:/CBK1738088009248155 | priority=1
  - row 17 | pmsr:/CBK1738088009248155/CBS/2 | codebook=pmsr:/CBK1738088009248155 | priority=2

### High 2: Components Referencing Missing ComponentStem URIs
- Count: 12
- Risk: semantic chain Component -> ComponentStem is broken for these rows, reducing ontology consistency and limiting reusable meaning.
- Corpus implication: validate stem identity and intended anatomical/device semantics against internal ontology docs in [cenarios/docs/07-Drupal-Based-Ontology-Management.md](cenarios/docs/07-Drupal-Based-Ontology-Management.md) and INS governance in [pmsrgui/INS-SPEC.md](pmsrgui/INS-SPEC.md#L1).
- Affected components:
  - row 2 | Shock Link Monitor  -- CB:EMPTY | pmsr:/COM1738095221724775 | stem=pmsr:/CSM1738095066920345 | instrumentRef=uberon:0004535
  - row 3 | Chest Inflator  -- CB:Chest Inflator | pmsr:/COM1738097990641815 | stem=pmsr:/CSM1738097871592315 | instrumentRef=uberon:0001004
  - row 4 | Stoma Inflator  -- CB:Stomach Inflator | pmsr:/COM1738098129989165 | stem=pmsr:/CSM1738098066919465 | instrumentRef=uberon:0001007
  - row 5 | Skill Guide Device  -- CB:EMPTY | pmsr:/COM1738099771423295 | stem=pmsr:/CSM1738099711825795 | instrumentRef=
  - row 6 | SimPad Device  -- CB:EMPTY | pmsr:/COM1738100153764155 | stem=pmsr:/CSM1738100063469285 | instrumentRef=
  - row 7 | Electrode  -- CB:Eletrodes Adult | pmsr:/COM1738187586682545 | stem=pmsr:/CSM1738187263734175 | instrumentRef=
  - row 8 | Electrode  -- CB:Eletrodes Pediatric | pmsr:/COM1738187818694655 | stem=pmsr:/CSM1738187263734175 | instrumentRef=
  - row 9 | DAE Trainer  -- CB:EMPTY | pmsr:/COM1738188452521625 | stem=pmsr:/CSM1738188394988515 | instrumentRef=
  - row 10 | Microphone  -- CB:EMPTY | pmsr:/COM1739399134970895 | stem=pmsr:/CSM1739399077752465 | instrumentRef=
  - row 11 | IV Access  -- CB:EMPTY | pmsr:/COM1740091620192285 | stem=pmsr:/CSM1740090464389005 | instrumentRef=uberon:0009055
  - row 12 | Anal Canal  -- CB:EMPTY | pmsr:/COM1740091527359405 | stem=pmsr:/CSM1740090719432385 | instrumentRef=uberon:0005409
  - row 13 | Pupil Simulator  -- CB:Pupil Simulator Size | pmsr:/COM1740097957423935 | stem=pmsr:/CSM1740097644832435 | instrumentRef=uberon:0000970

### Medium 1: Components Without Instrument Association
- Count: 7
- Risk: components exist but are not tied to any instrument instance, making runtime placement and maintenance ambiguous.
- Corpus implication: cross-check intended assignment using instrument workflows in [wkf-dev/WKF-SPEC-V1.md](wkf-dev/WKF-SPEC-V1.md#L1) and required-instrument mappings in [hascoapi/docs/WKF-REQUIREDINSTRUMENTS-FIX.md](hascoapi/docs/WKF-REQUIREDINSTRUMENTS-FIX.md).
- Unassigned components:
  - row 5 | Skill Guide Device  -- CB:EMPTY | pmsr:/COM1738099771423295
  - row 6 | SimPad Device  -- CB:EMPTY | pmsr:/COM1738100153764155
  - row 7 | Electrode  -- CB:Eletrodes Adult | pmsr:/COM1738187586682545
  - row 8 | Electrode  -- CB:Eletrodes Pediatric | pmsr:/COM1738187818694655
  - row 9 | DAE Trainer  -- CB:EMPTY | pmsr:/COM1738188452521625
  - row 10 | Microphone  -- CB:EMPTY | pmsr:/COM1739399134970895
  - row 15 | Lymphedema Condition Component Stem  -- CB:EMPTY | pmsr:/COM1740497043510835

### Medium 2: CodeBooks With No Slots
- Count: 2
- Risk: codebooks exist but define no usable option sequence.
- Affected codebooks:
  - row 9 | PASS and FAIL | pmsr:/CBK1738148623334734
  - row 12 | Pupil Simulator Size | pmsr:/CBK1740097825927975

## Positive Integrity Checks
- Components without codebook: 0 (target achieved: zero).
- SlotElements missing component: 0 (target achieved: zero).
- Instruments without slots: 0 (target achieved: zero).
- Broken next/previous slot links: next=0, previous=0.
- Detector/Actuator coverage: detector=75, actuator=75.

## Corpus-Based Content Quality Assessment
- Internal corpus has sufficient INS/WKF governance references and migration evidence for structural validation.
- External corpus is strong for vendor/support portals and standards, but still needs model-specific PDF capture per instrument for complete audit readiness.
- Duplicate instrument naming remains (Cancer Patient Simulator appears twice), requiring model/version disambiguation using external manuals.

## Recommended Remediation Sequence
1. Resolve 13 CodeBookSlots missing response options by deriving valid value states from external manuals in [pmsrgui/EXTERNAL-INSTRUMENT-DOCS-CURATION.md](pmsrgui/EXTERNAL-INSTRUMENT-DOCS-CURATION.md#L1).
2. Resolve 12 missing ComponentStem references by either creating matching stems or correcting URI references.
3. Assign 7 unbound components to specific instruments or mark them as explicit shared/global components by policy.
4. Populate 2 empty codebooks with ordered response options and provenance notes.
5. Add model/version provenance fields in comments for all legacy rows touched in this pass.

## Review Verdict
- Structural readiness: medium-high.
- Semantic/documentation readiness: medium.
- Blocking issues before final publication: 4 issue groups listed above.