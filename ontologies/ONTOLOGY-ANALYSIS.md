# PMSR Ontologies Analysis Report
Generated: 2026-07-18

## Summary

This report analyzes the three ontologies required for PMSR ingestion, showing their top-level concepts and subclass counts.

## 1. PMSR Ontology (pmsr.ttl) ✅ **v0.5 - OFFICIAL**
**Purpose:** Clinical simulation processes with comprehensive NCIT procedure hierarchy

### File Information
- **File:** pmsr.ttl
- **Size:** 116 KB
- **Version:** 0.5 (Extended from 0.4)
- **Total Classes:** 555 classes
  - 5 PMSR base classes
  - 38 NCIT root procedure classes
  - 510 NCIT descendant procedure classes (NEW in v0.5)
  - 2 other classes

### Top-Level Concept
**Medical Simulation Process Stem** (`pmsr:MedicalSimulationProcessStem`)
- **Direct subclasses:** 5
- **Description:** Interactive, guided techniques capable of replacing and/or amplifying real-life medical procedures

### Main Clinical Process Categories
The 5 PMSR base classes represent major clinical procedure categories, each linked to NCIT procedure hierarchies:
1. **Surgical Procedure** - Diagnostic or treatment procedures involving manual/instrumental means
2. **Disease Screening** - Use of devices/markers for detecting disease presence
3. **Therapeutic Procedure** - Treatment-focused medical interventions
4. **Diagnostic Procedure** - Procedures for disease/condition identification
5. **Patient Care Procedure** - General patient care and monitoring activities

### NCIT Procedure Integration (v0.5)
- **38 NCIT Root Classes:** Core procedure types from NCI Thesaurus
- **510 NCIT Descendants:** Complete subclass hierarchy extracted from full NCIT
- **Total NCIT Coverage:** 548 procedure classes (38 roots + 510 descendants)
- **Examples:** Biopsy procedures, imaging techniques, surgical methods, diagnostic tests, screening protocols

---

## 2. NCIT-PMSR Ontology (ncit-pmsr.ttl) ✅ **OFFICIAL - READY**
**Purpose:** Medical devices and equipment (PMSR subset of NCI Thesaurus)

### File Information
- **File:** ncit-pmsr.ttl
- **Size:** 1.6MB
- **Source:** https://hadatac.org/ont/ncit/ncit-pmsr.ttl
- **Total Classes:** 1,460 classes
- **MD5 Checksum:** a552f92e6c9b76f458662c1281baf9d5
- **Status:** ✅ **IN PLACE AND VERIFIED**

### Top-Level Concept
**Manufactured Object** (`obo:NCIT_C97325`)
- **Direct subclasses:** 1
- **Total descendants:** 1,422 classes

### Primary Medical Device Concept
**Diagnostic, Therapeutic, or Research Equipment** (`obo:NCIT_C19238`)
- **Direct subclasses:** 39
- **Total descendants:** 1,421 classes
- **Description:** Manufactured objects used to perform diagnostic, therapeutic, or research activities

### Sample Medical Device Categories (Direct Subclasses)
- Dipstick (obo:NCIT_C106516)
- Cover Slip (obo:NCIT_C126370)
- Blade (obo:NCIT_C126372)
- PAXgene Blood Tissue System (obo:NCIT_C126392)
- Histology Cassette (obo:NCIT_C128638)
- Plus 34 more direct categories with extensive subcategories

### Device Hierarchy
```
NCIT_C97325 (Manufactured Object)
└── NCIT_C19238 (Diagnostic, Therapeutic, or Research Equipment)
    ├── 39 direct subclasses
    └── 1,421 total descendants across all levels
```

**Note:** This PMSR subset contains only device-related classes from the full NCIT (which has ~150,000 classes). The subset focuses specifically on medical equipment, devices, and instruments relevant to clinical simulation.

---

## 3. UBERON Ontology (uberon.ttl) ✅ **OFFICIAL - READY**
**Purpose:** Anatomical structures and entities (PMSR subset)

### File Information
- **File:** uberon.ttl
- **Size:** 11 MB
- **Source:** https://hadatac.org/ont/uberon/uberonpmsr7.ttl
- **Total Classes:** 5,556 anatomical concepts
- **MD5 Checksum:** 490938af86af04054eed2344a0c148f7
- **Status:** ✅ **IN PLACE AND VERIFIED**

### Top-Level Concept
**Anatomical Entity** (`obo:UBERON_0001062`)
- **URI:** http://purl.obolibrary.org/obo/UBERON_0001062
- **Direct subclasses:** Multiple (part of hierarchical structure)
- **Total descendants:** 5,555 classes
- **Definition:** Biological entity that is either an individual member of a biological species or constitutes the structural organization of an individual member of a biological species.

### Anatomical Coverage
The PMSR subset of UBERON includes comprehensive anatomical vocabulary:
- **Body systems:** Cardiovascular, respiratory, digestive, nervous, etc.
- **Organs:** Heart, lung, liver, kidney, brain, stomach, etc.
- **Tissues:** Epithelial, connective, muscle, nervous tissues
- **Body regions:** Head, thorax, abdomen, limbs, etc.
- **Body parts and structures:** Bones, muscles, blood vessels, nerves
- **Anatomical spaces:** Body cavities, lumens, spaces

### Anatomical Hierarchy
```
UBERON_0001062 (Anatomical Entity)
├── Material Anatomical Entity
│   ├── Anatomical Structure
│   ├── Organism Subdivision
│   └── 5,555+ descendant concepts
└── Multiple levels of anatomical organization
```

**Note:** This PMSR subset contains 5,556 anatomical concepts from the full UBERON (which has significantly more classes). The subset focuses specifically on anatomical structures relevant to clinical simulation scenarios.

---

## Ontology Ingestion Requirements

### Namespace Registration
Each ontology requires registration with these parameters:

#### PMSR
- **Label:** pmsr
- **Namespace URI:** https://pmsr.net/ont/pmsr#
- **Source:** https://hadatac.org/ont/pmsr/pmsr.ttl
- **MIME Type:** text/turtle
- **Entry Point:** pmsr:MedicalSimulationProcessStem

#### NCIT
- **Label:** ncit
- **Namespace URI:** http://purl.obolibrary.org/obo/ncit.owl#
- **Source:** https://hadatac.org/ont/ncit/ncit-pmsr.ttl
- **MIME Type:** text/turtle
- **Entry Point:** obo:NCIT_C97325 (Manufactured Object)

#### UBERON
- **Label:** uberon
- **Namespace URI:** http://purl.obolibrary.org/obo/uberon.owl#
- **Source:** http://purl.obolibrary.org/obo/uberon.owl
- **MIME Type:** text/turtle or application/rdf+xml
- **Entry Point:** obo:UBERON_0001062 (Anatomical Entity)

---

## Usage in Study Search

These ontologies support the Study Search functionality:

1. **Medical Devices (NCIT):** Users can browse and select equipment/instruments required for clinical scenarios
   - 1,422 device concepts available
   - Hierarchical browsing from "Manufactured Object" root

2. **Clinical Processes (PMSR):** Users can classify and search scenarios by procedure type
   - 5 major procedure categories
   - Covers surgical, diagnostic, therapeutic, screening, and care procedures

3. **Anatomical Structures (UBERON):** Users can specify body parts/systems involved in scenarios
   - Comprehensive anatomical vocabulary
   - Hierarchical anatomical structure browsing

---

## Next Steps

1. ✅ **PMSR ontology** - Present and ready (pmsr.ttl, 116KB, 555 classes)
2. ✅ **NCIT-PMSR ontology** - Present and ready (ncit-pmsr.ttl, 1.6MB, 1,460 classes)
3. ✅ **UBERON ontology** - Present and ready (uberonpmsr7.ttl, 11MB, 5,556 classes)

### Ready for Ingestion:
All three ontologies are downloaded, verified, and ready to be ingested into Apache Fuseki:
- **Total vocabulary:** 7,571 concepts (555 + 1,460 + 5,556)
- **Total file size:** 12.716 MB
- **Status:** Complete semantic infrastructure for PMSR clinical simulation

---

## Summary Statistics

| Ontology | Purpose | File Size | Total Classes | Top-Level Concept | Descendants | Status |
|----------|---------|-----------|---------------|-------------------|-------------|--------|
| PMSR v0.5 | Clinical Processes | 116 KB | 555 | MedicalSimulationProcessStem | 548 NCIT procedures | ✅ **OFFICIAL v0.5** |
| NCIT-PMSR | Medical Devices | 1.6 MB | 1,460 | Manufactured Object → Medical Device | 1,421 total | ✅ **OFFICIAL** |
| UBERON v7 | Anatomical Parts | 11 MB | 5,556 | Anatomical Entity | 5,555 total | ✅ **OFFICIAL** |

**Current status:** 3 of 3 ontologies ready for ingestion (ALL verified and in place).  
**Total size ready:** 12.716 MB providing comprehensive coverage:
- **Clinical Procedures:** 555 classes (5 PMSR base + 548 NCIT procedures with full hierarchy)
- **Medical Devices:** 1,460 classes (complete PMSR subset from NCIT)
- **Anatomical Structures:** 5,556 classes (complete PMSR subset from UBERON)
- **Total vocabulary:** 7,571 concepts for clinical simulation scenarios
