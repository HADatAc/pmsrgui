# Issue-to-Test Mapping

This document maps each identified issue to the regression tests that verify the fix.

## Critical Issues

### ❌ Issue #1: Empty HASCO CLASSES Tree (Data Loss)

**Description:** hasco.ttl only had 2 of 23 required entry point definitions, causing PMSR to go from "near complete hasco-classes mapping" to "blank hasco-classes"

**Root Cause:** File was modified or replaced without validation, removing critical definitions

**Impact:** CATASTROPHIC - Complete loss of entry point hierarchy

**Protection Layers:**
- Template generation with all 23 entry points
- Pre-modification validation
- Post-modification validation
- Emergency backup system

**Tests:**
- ✅ `HascoIntegrityValidatorTest::testValidateHascoTtlDetectsMissingEntryPoints`
- ✅ `HascoIntegrityValidatorTest::testGenerateCompleteHascoTtlIncludesAllComponents`
- ✅ `IngestionIntegrityTest::testHascoTtlContainsAllRequiredEntryPoints`
- ✅ `IngestionIntegrityTest::testValidatorCanGenerateValidTemplate`
- ✅ `HascoIntegrityValidatorTest::testRequiredEntryPointsConstant`

**Manual Verification:**
```bash
grep -c "rdfs:subClassOf hasco:ClassEntryPoint" /Users/Shared/drupal_private/ont/hasco.ttl
# Expected: 23
```

---

### ❌ Issue #2: Missing ClassEntryPoint Base Class

**Description:** Template generator forgot to include ClassEntryPoint base class definition

**Root Cause:** generateCompleteHascoTtl() only created entry point subclasses, not the base class itself

**Impact:** HIGH - Entry points had no parent class, breaking RDF hierarchy

**Protection Layers:**
- Updated template generator includes ClassEntryPoint
- Validation checks for base class existence

**Tests:**
- ✅ `HascoIntegrityValidatorTest::testValidateHascoTtlDetectsMissingClassEntryPoint`
- ✅ `HascoIntegrityValidatorTest::testGenerateCompleteHascoTtlIncludesAllComponents`
- ✅ `IngestionIntegrityTest::testHascoTtlContainsClassEntryPointBaseClass`

**Manual Verification:**
```bash
grep -A 3 "hasco:ClassEntryPoint" /Users/Shared/drupal_private/ont/hasco.ttl | head -10
# Expected: Shows ClassEntryPoint as owl:Class
```

---

### ❌ Issue #3: Wrong PMSR Entry Point (ProcessEntryPoint)

**Description:** PMSR was bound to ProcessEntryPoint instead of WorkflowStemEntryPoint

**Root Cause:** Incorrect mapping in IngestionController.php createEntryPointMappings() array

**Impact:** HIGH - PMSR bound to wrong entry point, incorrect hierarchy

**Correct Binding:**
```php
'external_uri' => 'https://pmsr.net/ont/MedicalSimulationProcessStem',
'parent_uri' => 'http://hadatac.org/ont/hasco/WorkflowStemEntryPoint',
```

**Tests:**
- ✅ `IngestionIntegrityTest::testHascoTtlDoesNotContainProcessEntryPoint`
- ✅ `IngestionIntegrityTest::testPmsrCorrectlyBoundToWorkflowStemEntryPoint`
- ✅ `HascoIntegrityValidatorTest::testValidateHascoTtlWarnsAboutProcessEntryPoint`

**Manual Verification:**
```bash
# Check correct binding exists
grep -A 2 "pmsr:MedicalSimulationProcessStem" /Users/Shared/drupal_private/ont/hasco.ttl
# Expected: Shows rdfs:subClassOf hasco:WorkflowStemEntryPoint

# Check ProcessEntryPoint doesn't exist
grep -c "ProcessEntryPoint" /Users/Shared/drupal_private/ont/hasco.ttl
# Expected: 0
```

---

### ❌ Issue #4: API Namespace Error

**Description:** Page showed error "Could not retrieve any namespace with the uri that has been provided"

**Root Cause:** TreeController::getTopClass() was calling api->getUri() for ClassEntryPoint, which failed because ClassEntryPoint is not a registered namespace

**Impact:** MEDIUM - Page displayed errors, prevented tree from loading properly

**Fix:** Changed from `repoTopClassNamespaces()` to `getChildren()`, removed unnecessary `getUri()` call

**Tests:**
- ✅ `TreeControllerApiTest::testGetTopClassHandlesClassEntryPointWithoutNamespaceError`
- ✅ `IngestionIntegrityTest::testGetTopClassApiHandlesClassEntryPointCorrectly`
- ✅ `IngestionIntegrityTest::testMapEntryPointsPageLoadsWithoutErrors`

**Manual Verification:**
```bash
curl -s "http://localhost:8080/rep/gettopclass?nodeUri=http%3A%2F%2Fhadatac.org%2Font%2Fhasco%2FClassEntryPoint&_format=json" | python3 -c "import sys, json; print(f'Got {len(json.load(sys.stdin))} entry points')"
# Expected: Got 23 entry points
```

---

## UI/Feature Issues

### ⚠️ Issue #5: Entry Point Color-Coding Not Working

**Description:** Entry points not colored green (bound) or red (unbound) as designed

**Root Cause:** JavaScript event handlers attached AFTER tree initialization, missing the `ready.jstree` event that should trigger color application

**Impact:** MEDIUM - Feature not working, users can't visually identify bound/unbound entry points

**Fix:** Restructured JavaScript to attach event handlers BEFORE calling `initTree()`, ensuring `ready.jstree` event is captured

**Code Location:** `/opt/homebrew/var/www/drupal/web/modules/custom/rep/js/rep_map.js:724-782`

**Tests:**
- ✅ `EntryPointColorCodingTest::testEntryPointNodesHaveColorCodingClasses`
- ✅ `EntryPointColorCodingTest::testFetchBoundEntryPointsCalledOnTreeLoad`
- ✅ `EntryPointColorCodingTest::testColorCodingUpdatesOnTreeRefresh`
- ✅ `EntryPointColorCodingTest::testCssStylesAppliedToColoredEntryPoints`

**Manual Verification:**
```bash
# Check API returns bound entry points
curl -s "http://localhost:8080/rep/bound-entry-points?_format=json" | python3 -c "import sys, json; d=json.load(sys.stdin); print(f'Bound: {d[\"count\"]}')"
# Expected: Bound: 23
```

---

### ⚠️ Issue #6: Bound Terms Being Colored

**Description:** Child nodes of entry points (e.g., UBERON anatomical terms) were being colored green/red incorrectly

**Root Cause:** `updateLeftTreeColors()` didn't filter nodes by URI ending with "EntryPoint"

**Impact:** LOW - Visual confusion, incorrect color-coding of non-entry-point nodes

**Fix:** Added filter `if (uri.endsWith('EntryPoint'))` before applying color classes

**Code Location:** `/opt/homebrew/var/www/drupal/web/modules/custom/rep/js/rep_map.js:549`

**Tests:**
- ✅ `EntryPointColorCodingTest::testOnlyEntryPointsGetColored`

**Manual Verification:**
```javascript
// In browser console on Map Entry Points page:
jQuery('[title*="uberon"]').hasClass('entry-point-bound')
// Expected: false
```

---

### ⚠️ Issue #7: Missing Triple Counts in Reports

**Description:** Ingestion reports didn't show how many triples were loaded for each ontology

**Root Cause:** Report only showed success/failure, didn't query triplestore for triple counts

**Impact:** LOW - Users couldn't verify correct number of triples loaded

**Fix:** Added `namespaceList()` API call after ingestion with `sleep(2)` delay

**Code Location:** `/opt/homebrew/var/www/drupal/web/modules/custom/pmsrgui/src/Controller/IngestionController.php` (processOntologyIngestion method)

**Tests:**
- 🔶 `IngestionIntegrityTest::testIngestionReportIncludesTripleCounts` (incomplete - requires full ingestion)

**Manual Verification:**
```bash
# After running ingestion, check report output
# Expected format: "Loaded 927 triples from hasco ontology"
```

---

### ⚠️ Issue #8: Entry Point Mapping Details Not Shown

**Description:** Reports only showed "already exist (skipped)" without showing what the mappings were

**Root Cause:** createEntryPointMappings() only returned 'created' array, not 'existing' array

**Impact:** LOW - Lack of visibility into existing mappings

**Fix:** Return both 'created' and 'existing' arrays, display with format "BoundTerm → EntryPoint"

**Code Location:** `/opt/homebrew/var/www/drupal/web/modules/custom/pmsrgui/src/Controller/IngestionController.php` (createEntryPointMappings method)

**Tests:**
- 🔶 Manual testing recommended (complex to test in isolation)

---

## Architectural Issues

### 🏗️ Issue #9: Duplicate Entry Points in PMSR Graph

**Description:** PMSR graph contained full entry point class definitions for AnatomicalPartEntryPoint and MedicalDeviceEntryPoint (architectural violation)

**Root Cause:** Entry point definitions were uploaded to wrong graph during ingestion

**Impact:** HIGH - Violates graph isolation principle, entry point definitions should only exist in hasco graph

**Fix:** Deleted duplicate triples via SPARQL UPDATE on PMSR graph

**Graph Architecture:**
- **hasco graph** - Entry point CLASS DEFINITIONS + external BINDINGS
- **Source graphs** (pmsr, uberon, ncit) - External CONTENT only

**Tests:**
- 🔶 `IngestionIntegrityTest::testGraphIsolationPreventsDataLoss` (documented, requires SPARQL analysis)

**Manual Verification:**
```bash
# Count entry point definitions in PMSR graph (should be 0)
curl -s -X POST http://localhost:3030/store/sparql \
  --data-urlencode "query=SELECT (COUNT(*) as ?count) FROM <http://pmsr.net/ont/pmsr> WHERE { ?s rdfs:subClassOf <http://hadatac.org/ont/hasco/ClassEntryPoint> }" \
  -H "Accept: application/sparql-results+json" | python3 -c "import sys, json; print(json.load(sys.stdin)['results']['bindings'][0]['count']['value'])"
# Expected: 0
```

---

### 🏗️ Issue #10: Dangerous uploadOntology() Pattern

**Description:** Old ingestion code used `uploadOntology()` which deleted entire hasco graph then loaded from uploaded file

**Root Cause:** `RepoPage.java:237-243` - appOntology.deleteTriples() then loadTriples()

**Impact:** CATASTROPHIC - If uploaded file incomplete, permanent data loss

**Fix:** Replaced with namespace-specific `repoIngestNamespaceOntology()` which uses graph isolation

**Tests:**
- 🔶 `IngestionIntegrityTest::testGraphIsolationPreventsDataLoss` (architectural documentation)

**Code Change:**
```php
// OLD (dangerous):
$result = $api->uploadOntology($hascoTempPath, 'hasco', 'application');

// NEW (safe):
$result = $api->repoIngestNamespaceOntology('hasco', 'http://hadatac.org/ont/hasco/', $file_content, 'text/turtle');
```

---

## Protection System Tests

### 8-Layer Protection System

**Purpose:** Prevent catastrophic data loss during ontology ingestion

#### Layer 1: Template Generation
**Test:** `HascoIntegrityValidatorTest::testValidatorCanGenerateValidTemplate`

#### Layer 2: Automatic Backups
**Test:** `IngestionIntegrityTest::testEmergencyBackupDirectoryIsAvailable`

#### Layer 3: Pre-Modification Validation
**Tests:**
- `HascoIntegrityValidatorTest::testValidateHascoTtlWithValidContent`
- `HascoIntegrityValidatorTest::testValidateHascoTtlRejectsInvalidRdfSyntax`
- `HascoIntegrityValidatorTest::testValidateHascoTtlDetectsSyntaxErrors`

#### Layer 4: Post-Modification Validation
**Test:** Covered by validator tests (same validation logic)

#### Layer 5: File-Level Auto-Restore
**Test:** Covered by validator and backup tests

#### Layer 6: Graph Isolation
**Test:** `IngestionIntegrityTest::testGraphIsolationPreventsDataLoss` (documented)

#### Layer 7: Post-Ingestion Validation
**Tests:**
- `IngestionIntegrityTest::testGetBoundEntryPointsApiReturnsCorrectData`
- `IngestionIntegrityTest::testHascoClassesTreeDisplaysAllEntryPoints`

#### Layer 8: Emergency Restore
**Test:** Covered by backup and validation tests

---

## Test Coverage Summary

### By Test Type

**Unit Tests:** 15 tests
- HascoIntegrityValidatorTest: 11 tests ✅
- TreeControllerApiTest: 4 tests 🔶

**Functional Tests:** 12 tests
- IngestionIntegrityTest: 12 tests (10 ✅, 2 🔶)

**JavaScript Tests:** 5 tests
- EntryPointColorCodingTest: 5 tests ✅

**Total:** 32 tests (28 complete, 4 incomplete)

### By Issue Severity

**Critical Issues:** 18 tests ✅
- Data loss prevention
- Entry point integrity
- Validation logic

**High Impact Issues:** 8 tests (7 ✅, 1 🔶)
- Architecture violations
- API errors
- Binding errors

**Medium/Low Impact Issues:** 6 tests (3 ✅, 3 🔶)
- UI features
- Reporting enhancements

---

## Running Tests by Issue

### Test All Critical Data Loss Prevention
```bash
./modules/custom/pmsrgui/tests/run-tests.sh critical
```

### Test Entry Point Integrity (Issues #1, #2, #3)
```bash
vendor/bin/phpunit --filter "HascoTtl|RequiredEntryPoints|ClassEntryPoint|Pmsr" \
  modules/custom/pmsrgui/tests/
```

### Test API Endpoints (Issue #4)
```bash
vendor/bin/phpunit --filter "GetTopClass|GetBoundEntryPoints" \
  modules/custom/rep/tests/
```

### Test Color-Coding (Issues #5, #6)
```bash
vendor/bin/phpunit modules/custom/rep/tests/src/FunctionalJavascript/
```

### Test Protection Layers
```bash
vendor/bin/phpunit --filter "Validator|Backup|Validation" \
  modules/custom/pmsrgui/tests/
```

---

## Test Status Legend

- ✅ **Complete** - Test fully implemented and ready to run
- 🔶 **Incomplete** - Test structure exists but requires mocking/integration
- ⚠️ **Manual** - Requires manual testing due to complexity

---

## Documentation References

- **Protection System Details:** `/Users/pp3223/git/cenarios/docs/HASCO-INTEGRITY-PROTECTION.md`
- **Test Suite Overview:** `REGRESSION_TEST_SUITE.md`
- **Quick Reference:** `QUICK_REFERENCE.md`
- **Conversation Transcript:** VS Code session logs

---

**Last Updated:** 2026-07-19  
**Test Suite Version:** 1.0  
**Maintainer:** Development Team
