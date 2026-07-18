# PMSR Ontologies Directory

This directory contains the official ontology files for PMSR ingestion.

## Current Files (Ready for Ingestion)

1. **pmsr.ttl** ✅ - PMSR (Medical Simulation Process) Ontology v0.5 **OFFICIAL**
   - Source: Extended from v0.4 with full NCIT procedure hierarchy
   - Namespace: https://pmsr.net/ont/pmsr#
   - Format: Turtle (text/turtle)
   - Size: 116 KB
   - Classes: 555 (5 PMSR base + 38 NCIT root + 510 NCIT descendant + 2 other)
   - Root Procedure Classes: 38 NCIT procedures
   - Descendant Classes: 510 NCIT procedure subclasses (NEW in v0.5)
   - Status: **READY - COMPREHENSIVE PROCEDURE COVERAGE**

2. **ncit-pmsr.ttl** ✅ - NCIT PMSR Subset (Medical Devices) **OFFICIAL**
   - Source: https://hadatac.org/ont/ncit/ncit-pmsr.ttl
   - Namespace: http://purl.obolibrary.org/obo/ncit.owl#
   - Format: Turtle (text/turtle)
   - Size: 1.6 MB
   - Classes: 1,460 medical device classes
   - Top Concept: NCIT_C97325 (Manufactured Object) → NCIT_C19238 (Medical Device)
   - Status: **READY**

3. **uberon.ttl** ✅ - UBERON PMSR Subset v7 (Anatomical Structures) **OFFICIAL**
   - Source: https://hadatac.org/ont/uberon/uberonpmsr7.ttl
   - Namespace: http://purl.obolibrary.org/obo/
   - Format: Turtle (text/turtle)
   - Size: 11 MB
   - Classes: 5,556 anatomical concepts
   - Top Concept: UBERON_0001062 (Anatomical Entity)
   - MD5: 490938af86af04054eed2344a0c148f7
   - Status: **READY - COMPREHENSIVE ANATOMICAL COVERAGE**

## Installation Instructions

All three ontology files are now present in the cenarios/ontologies directory. To complete installation, copy them to this directory:

```bash
cd /opt/homebrew/var/www/drupal/web/modules/custom/pmsrgui/ontologies/

# Copy UBERON from cenarios (already done for PMSR and NCIT)
cp /Users/pp3223/git/cenarios/ontologies/uberonpmsr7.ttl ./

# Verify all files are present
ls -lh *.ttl
```

Or download directly from hadatac.org:

```bash
cd /opt/homebrew/var/www/drupal/web/modules/custom/pmsrgui/ontologies/

# Download PMSR ontology v0.5 (already present)
# curl -o pmsr.ttl https://hadatac.org/ont/pmsr/pmsr.ttl

# Download NCIT PMSR subset (already present)
# curl -o ncit-pmsr.ttl https://hadatac.org/ont/ncit/ncit-pmsr.ttl

# Download UBERON PMSR subset v7
curl -o uberon.ttl https://hadatac.org/ont/uberon/uberonpmsr7.ttl
```

## Usage

These files are used by the "Ingest PMSR Ontologies" function accessible at:
Repository → PMSR Setup → Ingest Ontologies

The ingestion process will:
1. Verify all three files exist
2. Check if each ontology is registered in the system
3. Clear any existing triples for each ontology
4. Load the triples from these local files into the triplestore
