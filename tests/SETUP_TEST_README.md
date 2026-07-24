# PMSR Setup Test Suite

Comprehensive test suite for PMSR Setup processes including rerun-safety and regression tests.

## Overview

This test suite validates:

### 1. **Rerun-Safe Tests** (4 Ingestion Processes)
Tests that running ingestion multiple times doesn't accumulate duplicate data:
- ✅ Ingest PMSR Ontologies - Clears existing triples before re-loading
- ✅ Ingest INS Instruments - Deletes old INS-PMSR DataFiles before creating new ones  
- ✅ Ingest KRG Geography and Organizations - Deletes old KGR DataFiles before ingesting
- ✅ Ingest KGR People - Deletes old KGR-PEOPLE DataFile before ingesting

### 2. **Regression Tests** (5 Setup Processes)
Tests that all processes complete successfully and produce expected results:
1. **PMSR Config Bootstrap** - Configures repository settings and Drupal configuration (does NOT load ontologies)
2. **Ingest PMSR Ontologies** - Validates pmsr, ncit, uberon namespaces with correct URIs and triples
3. **Ingest INS Instruments** - Checks DataFile graphs contain instrument definitions
4. **Ingest KRG Geography** - Verifies geography DataFiles are loaded
5. **Ingest KGR People** - Verifies people DataFiles are loaded

## Prerequisites

- hascoapi running on port 9001
- Apache Fuseki running on port 3030  
- Drupal running on port 8080
- PHP CLI with JSON support

## Usage

```bash
# Run all tests (regression + rerun-safe)
php test_pmsr_setup.php all

# Run only regression tests
php test_pmsr_setup.php regression

# Run only rerun-safe tests
php test_pmsr_setup.php rerun-safe

# Run individual process tests
php test_pmsr_setup.php ontologies
php test_pmsr_setup.php ins
php test_pmsr_setup.php geography
php test_pmsr_setup.php people
```

## Test Methodology

### Rerun-Safety Testing

The rerun-safe tests verify that:
1. Initial triple counts are recorded
2. After re-ingestion, triple counts remain **the same** (not doubled)
3. Named graphs are properly cleaned before re-ingestion
4. No duplicate DataFile entities accumulate

**Example:**
```
Initial state: pmsr = 1,876 triples
After rerun: pmsr = 1,876 triples ✅ (not 3,752)
```

### Regression Testing

The regression tests verify that:
1. All required namespaces exist in hascoapi
2. Namespace URIs are correct (e.g., `NCIT_` not `ncit.owl`)
3. DataFile named graphs exist in Fuseki
4. Triple counts are reasonable (> 0)
5. Essential configurations are in place

## Expected Results

### After Initial Setup (all processes run once):

```
✓ [BOOTSTRAP] Can connect to hascoapi
✓ [BOOTSTRAP] Namespaces are loaded
✓ [BOOTSTRAP] Essential namespace 'rdf' exists
✓ [BOOTSTRAP] Essential namespace 'rdfs' exists
✓ [BOOTSTRAP] Essential namespace 'owl' exists
✓ [BOOTSTRAP] Essential namespace 'hasco' exists
✓ [ONTOLOGIES] PMSR namespace exists
✓ [ONTOLOGIES] PMSR has triples (found: 1876)
✓ [ONTOLOGIES] PMSR URI is correct
✓ [ONTOLOGIES] NCIT namespace exists
✓ [ONTOLOGIES] NCIT has triples (found: 21218)
✓ [ONTOLOGIES] NCIT URI is correct (has underscore)
✓ [ONTOLOGIES] UBERON namespace exists
✓ [ONTOLOGIES] UBERON has triples (found: 180667)
✓ [ONTOLOGIES] UBERON URI is correct (has underscore)
✓ [INS] At least one DataFile graph exists
✓ [INS] INS DataFiles contain significant data (found: 1894 triples)
✓ [INS] Found INS DataFile with expected size: 1894 triples
✓ [GEOGRAPHY] DataFile graphs exist
✓ [GEOGRAPHY] Multiple KGR DataFiles found (expected 10+, found: 10)
✓ [PEOPLE] DataFile graphs exist
```

### Exit Codes

- `0` - All tests passed
- `1` - One or more tests failed
- `2` - All tests skipped (system not initialized)

## Troubleshooting

### "Failed to connect to hascoapi"
```bash
# Check if hascoapi is running
curl http://127.0.0.1:9001/hascoapi/api/repo/table/namespaces

# If not running, start hascoapi Docker container
docker-compose up -d hascoapi
```

### "No ontologies loaded"
Run the PMSR Setup processes in order:
1. PMSR Config Bootstrap
2. Ingest PMSR Ontologies
3. Ingest INS Instruments
4. Ingest KRG Geography and Organizations
5. Ingest KRG People

### "DataFile graphs exist but triple count is low"
Check Fuseki directly:
```bash
curl -X POST http://127.0.0.1:3030/store/query \
  --data-urlencode "query=SELECT DISTINCT ?g (COUNT(*) AS ?count) WHERE { GRAPH ?g { ?s ?p ?o } } GROUP BY ?g" \
  -H "Accept: application/sparql-results+json"
```

## Implementation Notes

### Fixed Issue: NCIT and UBERON URI Corruption

**Problem:** ncit/uberon were loading with `.owl` URIs instead of `_` suffix:
- ❌ `http://purl.obolibrary.org/obo/ncit.owl`
- ❌ `http://purl.obolibrary.org/obo/uberon.owl`

**Fix:** Corrected namespace URIs in `IngestionController.php`:
- ✅ `http://purl.obolibrary.org/obo/NCIT_`
- ✅ `http://purl.obolibrary.org/obo/UBERON_`

This allows proper deletion by named graph and prevents pollution of the default graph.

### Fixed Issue: INS Ingestion Not Rerun-Safe

**Problem:** Each INS ingestion created a new DataFile with timestamp-based URI, accumulating duplicates.

**Fix:** Added Step 5 to `IngestionINSController.php` that:
1. Searches for existing INS-PMSR templates
2. Calls `uningestMT()` to delete old DataFile graphs and metadata
3. Then creates fresh entities

### Rerun-Safe Guarantee

All four ingestion processes now implement the pattern:
```
1. Search for existing entity by label
2. Delete associated DataFile (removes named graph with all triples)
3. Delete metadata entity  
4. Create fresh entities with new URIs
5. Ingest data
```

This ensures **idempotent behavior** - running N times produces the same result as running once.

## Continuous Integration

To integrate with CI/CD:

```bash
#!/bin/bash
# ci-test-pmsr.sh

# Start services
docker-compose up -d

# Wait for services
sleep 10

# Run tests
php /path/to/test_pmsr_setup.php regression

# Capture exit code
EXIT_CODE=$?

# Stop services
docker-compose down

exit $EXIT_CODE
```

## Future Enhancements

- [ ] Automated rerun-safe testing (trigger ingestion via API with CSRF)
- [ ] Performance benchmarking (measure ingestion time)
- [ ] Data validation (verify specific entity URIs exist)
- [ ] Coverage reporting (which ingestion paths are tested)
- [ ] Integration with GitHub Actions / Jenkins
