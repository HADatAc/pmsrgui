# PMSR Ingestion Regression Test Suite

## Overview

This comprehensive test suite validates all fixes implemented for critical data loss issues and UI features in the PMSR ontology ingestion system.

## Test Organization

### 1. Unit Tests

#### HascoIntegrityValidatorTest.php
**Purpose**: Tests validation logic for hasco.ttl integrity

**Tests:**
- ✅ `testGenerateCompleteHascoTtlIncludesAllComponents` - Template generation includes all 23 entry points + ClassEntryPoint
- ✅ `testValidateHascoTtlWithValidContent` - Valid TTL passes validation
- ✅ `testValidateHascoTtlRejectsEmptyContent` - Empty content fails validation
- ✅ `testValidateHascoTtlRejectsInvalidRdfSyntax` - Invalid RDF syntax fails validation
- ✅ `testValidateHascoTtlDetectsSyntaxErrors` - Detects lowercase "subclassOf" error
- ✅ `testValidateHascoTtlDetectsMissingEntryPoints` - Detects when fewer than 23 entry points present
- ✅ `testValidateHascoTtlDetectsMissingClassEntryPoint` - Detects missing base class
- ✅ `testValidateHascoTtlProvidesStatistics` - Returns detailed validation stats
- ✅ `testRequiredEntryPointsConstant` - Verifies all 23 entry points are defined
- ✅ `testValidateHascoTtlHandlesMalformedTtlGracefully` - Handles malformed TTL without crashing
- ✅ `testValidateHascoTtlWarnsAboutProcessEntryPoint` - Warns about deprecated ProcessEntryPoint

**Run:** `vendor/bin/phpunit modules/custom/pmsrgui/tests/src/Unit/HascoIntegrityValidatorTest.php`

#### TreeControllerApiTest.php
**Purpose**: Tests TreeController API endpoints

**Tests:**
- 🔶 `testGetBoundEntryPointsReturnsValidJson` - getBoundEntryPoints() returns correct JSON format
- 🔶 `testGetTopClassHandlesClassEntryPointWithoutNamespaceError` - getTopClass() works without namespace lookup
- 🔶 `testGetTopClassReturnsMissingParameterError` - Returns 400 error for missing parameters
- 🔶 `testGetChildrenApiReturnsEntryPointSubclasses` - Integration test with hascoapi backend

**Run:** `vendor/bin/phpunit modules/custom/rep/tests/src/Unit/TreeControllerApiTest.php`

### 2. Functional Tests

#### IngestionIntegrityTest.php
**Purpose**: End-to-end tests for ingestion process integrity

**Critical Data Loss Prevention Tests:**
- ✅ `testHascoTtlContainsAllRequiredEntryPoints` - All 23 entry points present in file
- ✅ `testHascoTtlContainsClassEntryPointBaseClass` - Base class exists
- ✅ `testHascoTtlDoesNotContainProcessEntryPoint` - Deprecated entry point removed
- ✅ `testPmsrCorrectlyBoundToWorkflowStemEntryPoint` - Correct PMSR binding
- ✅ `testGetBoundEntryPointsApiReturnsCorrectData` - API returns all bound entry points
- ✅ `testGetTopClassApiHandlesClassEntryPointCorrectly` - No namespace errors
- ✅ `testEmergencyBackupDirectoryIsAvailable` - Backup infrastructure exists
- ✅ `testValidatorCanGenerateValidTemplate` - Template generator works correctly
- ✅ `testMapEntryPointsPageLoadsWithoutErrors` - Page loads without errors
- ✅ `testHascoClassesTreeDisplaysAllEntryPoints` - Tree shows all 23 entry points
- 🔶 `testIngestionReportIncludesTripleCounts` - Reports show triple counts
- 🔶 `testGraphIsolationPreventsDataLoss` - Architecture prevents cross-graph deletion

**Run:** `vendor/bin/phpunit modules/custom/pmsrgui/tests/src/Functional/IngestionIntegrityTest.php`

### 3. JavaScript Tests

#### EntryPointColorCodingTest.php
**Purpose**: Tests JavaScript color-coding functionality

**Tests:**
- ✅ `testEntryPointNodesHaveColorCodingClasses` - CSS classes applied to all entry points
- ✅ `testOnlyEntryPointsGetColored` - Only entry points colored, not children
- ✅ `testFetchBoundEntryPointsCalledOnTreeLoad` - API called during tree initialization
- ✅ `testColorCodingUpdatesOnTreeRefresh` - Colors re-applied after refresh
- ✅ `testCssStylesAppliedToColoredEntryPoints` - Green color correctly applied

**Run:** `vendor/bin/phpunit modules/custom/rep/tests/src/FunctionalJavascript/EntryPointColorCodingTest.php`

**Note:** GUI/browser validation should use the Playwright procedure.
**Legacy note:** PHPUnit `FunctionalJavascript` tests still use the existing driver configuration in this repository.

### 4. PMSR Setup Integration Tests

#### test_pmsr_setup.php
**Purpose**: Integration tests for PMSR Setup ingestion processes - validates rerun-safety and regression

**Rerun-Safety Tests (4 Ingestion Processes):**
- ✅ `testOntologiesRerunSafe` - Ontology ingestion clears existing triples before reloading
- ✅ `testINSRerunSafe` - INS ingestion deletes old DataFiles before creating new ones
- ✅ `testGeographyRerunSafe` - Geography ingestion deletes old KGR DataFiles
- ✅ `testPeopleRerunSafe` - People ingestion deletes old KGR-PEOPLE DataFile

**Regression Tests (5 Setup Processes):**
- ✅ `testBootstrapRegression` - Config Bootstrap creates repository & essential namespaces
- ✅ `testOntologiesRegression` - PMSR/NCIT/UBERON namespaces exist with correct URIs (`NCIT_` not `ncit.owl`)
- ✅ `testINSRegression` - INS DataFile graphs exist with expected triple counts (~1,894)
- ✅ `testGeographyRegression` - 10+ KGR DataFile graphs exist for geography data
- ✅ `testPeopleRegression` - KGR-PEOPLE DataFile exists

**Run:**
```bash
# All setup tests (rerun-safe + regression)
php modules/custom/pmsrgui/tests/test_pmsr_setup.php all

# Only rerun-safe tests
php modules/custom/pmsrgui/tests/test_pmsr_setup.php rerun-safe

# Only regression tests
php modules/custom/pmsrgui/tests/test_pmsr_setup.php regression

# Individual process tests
php modules/custom/pmsrgui/tests/test_pmsr_setup.php ontologies
php modules/custom/pmsrgui/tests/test_pmsr_setup.php ins
php modules/custom/pmsrgui/tests/test_pmsr_setup.php geography
php modules/custom/pmsrgui/tests/test_pmsr_setup.php people
```

**Note:** Requires hascoapi (port 9001) and Fuseki (port 3030) running. See SETUP_TEST_README.md for details.

## Issues Covered

### Issue #1: CRITICAL - Empty HASCO CLASSES Tree
**Root Cause:** hasco.ttl only had 2 of 23 required entry point definitions  
**Impact:** PMSR went from "near complete hasco-classes mapping" to "blank hasco-classes"  
**Tests:** 
- `testHascoTtlContainsAllRequiredEntryPoints`
- `testValidateHascoTtlDetectsMissingEntryPoints`
- `testGenerateCompleteHascoTtlIncludesAllComponents`

### Issue #2: Missing ClassEntryPoint Base Class
**Root Cause:** Template generator forgot to include the base class itself  
**Impact:** Entry points had no parent class, breaking hierarchy  
**Tests:**
- `testHascoTtlContainsClassEntryPointBaseClass`
- `testValidateHascoTtlDetectsMissingClassEntryPoint`

### Issue #3: Wrong PMSR Entry Point
**Root Cause:** ProcessEntryPoint created by mistake instead of WorkflowStemEntryPoint  
**Impact:** PMSR bound to wrong entry point  
**Tests:**
- `testHascoTtlDoesNotContainProcessEntryPoint`
- `testPmsrCorrectlyBoundToWorkflowStemEntryPoint`
- `testValidateHascoTtlWarnsAboutProcessEntryPoint`

### Issue #4: API Namespace Error
**Root Cause:** getTopClass() called getUri() for ClassEntryPoint, causing "Could not retrieve namespace" error  
**Impact:** Page showed error messages, prevented tree from loading  
**Tests:**
- `testGetTopClassApiHandlesClassEntryPointCorrectly`
- `testMapEntryPointsPageLoadsWithoutErrors`

### Issue #5: Color-Coding Not Working
**Root Cause:** JavaScript event handlers attached AFTER tree initialization, missing ready.jstree event  
**Impact:** Entry points not colored green/red based on binding status  
**Tests:**
- `testEntryPointNodesHaveColorCodingClasses`
- `testFetchBoundEntryPointsCalledOnTreeLoad`
- `testColorCodingUpdatesOnTreeRefresh`

### Issue #6: Bound Terms Being Colored
**Root Cause:** updateLeftTreeColors() didn't filter by URI ending with "EntryPoint"  
**Impact:** Children of entry points (e.g., UBERON terms) were incorrectly colored  
**Tests:**
- `testOnlyEntryPointsGetColored`

### Issue #7: Duplicate Entry Points in PMSR Graph
**Root Cause:** PMSR graph contained full entry point class definitions (architectural violation)  
**Impact:** Entry point definitions in wrong graph, violating graph isolation  
**Tests:**
- `testGraphIsolationPreventsDataLoss` (documented)

### Issue #8: Missing Triple Counts
**Root Cause:** Reports didn't query namespaceList() after ingestion  
**Impact:** Users couldn't verify correct number of triples loaded  
**Tests:**
- `testIngestionReportIncludesTripleCounts` (documented)

### Issue #9: NCIT and UBERON Namespace URI Corruption
**Root Cause:** Namespace URIs set as `ncit.owl` and `uberon.owl` instead of `NCIT_` and `UBERON_`  
**Impact:** Triples loaded to wrong named graphs, preventing proper deletion  
**Tests:**
- `testOntologiesRegression` - Validates correct URIs with underscore suffix

### Issue #10: INS Ingestion Not Rerun-Safe
**Root Cause:** Each INS ingestion created new DataFile with timestamp-based URI, accumulating duplicates  
**Impact:** Running INS ingestion N times created N DataFiles with duplicate data  
**Tests:**
- `testINSRerunSafe` - Verifies old DataFiles deleted before new ingestion
- `testINSRegression` - Checks DataFile count and triple counts

### Issue #11: Missing Ingestion Regression Tests
**Root Cause:** No automated tests for PMSR Setup ingestion processes  
**Impact:** Could not verify rerun-safety or detect regressions in setup processes  
**Tests:**
- `testBootstrapRegression` - Validates bootstrap process
- `testOntologiesRegression` - Validates ontology ingestion
- `testINSRegression` - Validates INS ingestion
- `testGeographyRegression` - Validates KRG Geography ingestion
- `testPeopleRegression` - Validates KRG People ingestion

## 8-Layer Protection System Tests

The tests validate all 8 protection layers implemented to prevent data loss:

1. **Template Generation** - `testValidatorCanGenerateValidTemplate`
2. **Automatic Backups** - `testEmergencyBackupDirectoryIsAvailable`
3. **Pre-Modification Validation** - `testValidateHascoTtlWithValidContent`
4. **Post-Modification Validation** - Covered by validator tests
5. **File-Level Auto-Restore** - Covered by validator tests
6. **Graph Isolation** - `testGraphIsolationPreventsDataLoss`
7. **Post-Ingestion Validation** - Covered by ingestion tests
8. **Emergency Restore** - Covered by backup tests

## Running Tests

### Run All Tests
```bash
cd /opt/homebrew/var/www/drupal/web

# Run all tests using the test runner script
../tests/run-tests.sh all

# OR run individual test suites:

# Unit tests
vendor/bin/phpunit modules/custom/pmsrgui/tests/src/Unit/
vendor/bin/phpunit modules/custom/rep/tests/src/Unit/

# Functional tests
vendor/bin/phpunit modules/custom/pmsrgui/tests/src/Functional/

# JavaScript tests (GUI checks via Playwright)
vendor/bin/phpunit modules/custom/rep/tests/src/FunctionalJavascript/

# PMSR Setup tests (integration/regression)
php modules/custom/pmsrgui/tests/test_pmsr_setup.php all
```

### Run Specific Test Class
```bash
vendor/bin/phpunit modules/custom/pmsrgui/tests/src/Unit/HascoIntegrityValidatorTest.php
```

### Run Specific Test Method
```bash
vendor/bin/phpunit --filter testHascoTtlContainsAllRequiredEntryPoints modules/custom/pmsrgui/tests/src/Functional/IngestionIntegrityTest.php
```

## Test Status Legend

- ✅ **Complete** - Test fully implemented and executable
- 🔶 **Incomplete** - Test structure exists but requires mocking/integration setup
- ⚠️ **Manual** - Requires manual testing due to complexity

## Prerequisites

### Unit Tests
- PHPUnit installed via Composer
- Drupal test infrastructure

### Functional Tests
- Running Drupal instance at localhost:8080
- hascoapi backend at localhost:9001
- Apache Fuseki at localhost:3030/store
- Test database configured
- hasco.ttl file at /Users/Shared/drupal_private/ont/

### JavaScript Tests
- Playwright test runner and browser binaries
- `drupal/core-dev` package installed
- Browser testing environment configured

### PMSR Setup Integration Tests
- Apache Fuseki at localhost:3030/store
- hascoapi backend at localhost:9001
- Drupal instance at localhost:8080
- Test data files (INS-PMSR.xlsx, KGR-*.xlsx) in place
- PHP CLI (php command available)

## Continuous Integration

These tests should be run:
1. **Before any commit** to master/main branch
2. **After any ontology ingestion** to verify integrity
3. **After any PMSR Setup process** to verify rerun-safety
4. **Before production deployment**
5. **Weekly** as part of scheduled maintenance

## Manual Testing Checklist

Some scenarios require manual testing:

- [ ] Full PMSR ingestion process (397 classes)
- [ ] INS-PMSR.xlsx file upload and ingestion
- [ ] Geography KGR ingestion from 10 Excel files
- [ ] People KGR ingestion from KGR-PEOPLE.xlsx
- [ ] Triple count verification in reports
- [ ] Triple count verification in reports
- [ ] Color-coding visual verification (green/red)
- [ ] Emergency backup restoration
- [ ] Graph isolation during concurrent ingestions
- [ ] Performance with large ontologies

## Documentation

For detailed information about each issue and fix:
- See: `/Users/pp3223/git/cenarios/docs/HASCO-INTEGRITY-PROTECTION.md`
- Conversation transcript: Available in VS Code session logs

## Maintenance

**Test Owner:** Development team  
**Last Updated:** 2026-07-19  
**Next Review:** After any significant ingestion changes

## Support

If tests fail:
1. Check prerequisites are met
2. Verify backend services are running
3. Review test output for specific failures
4. Check conversation summary for context
5. Contact development team if issues persist
