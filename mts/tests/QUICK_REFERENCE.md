# PMSR Ingestion Test Suite - Quick Reference

## Quick Start

```bash
# Make test runner executable
chmod +x modules/custom/pmsrgui/tests/run-tests.sh

# Run all tests
./modules/custom/pmsrgui/tests/run-tests.sh

# Run specific test category
./modules/custom/pmsrgui/tests/run-tests.sh critical

# Run with verbose output
./modules/custom/pmsrgui/tests/run-tests.sh unit --verbose
```

## Test Categories

### Critical Tests (Run First)
```bash
./modules/custom/pmsrgui/tests/run-tests.sh critical
```
Tests the 4 most critical data loss scenarios:
- All 23 entry points present in hasco.ttl
- ClassEntryPoint base class exists
- PMSR correctly bound to WorkflowStemEntryPoint
- Validator detects missing entry points

### Unit Tests
```bash
./modules/custom/pmsrgui/tests/run-tests.sh unit
```
Fast, isolated tests for validation logic:
- HascoIntegrityValidator (11 tests)
- TreeController API endpoints (4 tests)

### Functional Tests
```bash
./modules/custom/pmsrgui/tests/run-tests.sh functional
```
End-to-end tests requiring running services:
- Ingestion integrity (12 tests)
- API endpoints with real data
- File system operations

### JavaScript Tests
```bash
./modules/custom/pmsrgui/tests/run-tests.sh javascript
```
Browser automation tests for UI features:
- Entry point color-coding (5 tests)
- Tree interaction
- CSS styling

Legacy note: PHPUnit `FunctionalJavascript` tests still use the existing driver configuration in this repository; Playwright is the primary GUI validation procedure.

## Individual Test Classes

```bash
# Validator tests
./modules/custom/pmsrgui/tests/run-tests.sh validator

# Ingestion integrity tests
./modules/custom/pmsrgui/tests/run-tests.sh integrity

# API tests
./modules/custom/pmsrgui/tests/run-tests.sh api

# Color-coding tests
./modules/custom/pmsrgui/tests/run-tests.sh color
```

## Manual Verification

### Verify hasco.ttl Integrity
```bash
# Count entry points
grep -c "rdfs:subClassOf hasco:ClassEntryPoint" /Users/Shared/drupal_private/ont/hasco.ttl
# Should output: 23

# Verify ClassEntryPoint exists
grep "hasco:ClassEntryPoint" /Users/Shared/drupal_private/ont/hasco.ttl | head -5
```

### Verify API Endpoints
```bash
# Check bound entry points
curl -s "http://localhost:8080/rep/bound-entry-points?_format=json" | python3 -m json.tool

# Check ClassEntryPoint children
curl -s "http://localhost:8080/rep/gettopclass?nodeUri=http%3A%2F%2Fhadatac.org%2Font%2Fhasco%2FClassEntryPoint&_format=json" | python3 -c "import sys, json; print(f\"Found {len(json.load(sys.stdin))} entry points\")"
```

### Verify PMSR Binding
```bash
# Check PMSR binding in hasco.ttl
grep -A 2 "pmsr:MedicalSimulationProcessStem" /Users/Shared/drupal_private/ont/hasco.ttl
# Should show: rdfs:subClassOf hasco:WorkflowStemEntryPoint

# Verify ProcessEntryPoint doesn't exist
grep -c "ProcessEntryPoint" /Users/Shared/drupal_private/ont/hasco.ttl
# Should output: 0
```

### Verify Graph Isolation
```bash
# Count entry point definitions in PMSR graph (should be 0)
curl -s -X POST http://localhost:3030/store/sparql \
  --data-urlencode "query=SELECT (COUNT(*) as ?count) FROM <http://pmsr.net/ont/pmsr> WHERE { ?s rdfs:subClassOf <http://hadatac.org/ont/hasco/ClassEntryPoint> }" \
  -H "Accept: application/sparql-results+json" | python3 -c "import sys, json; print(json.load(sys.stdin)['results']['bindings'][0]['count']['value'])"
```

## Continuous Integration

### Pre-Commit Hook
```bash
# Add to .git/hooks/pre-commit
#!/bin/bash
cd web
./modules/custom/pmsrgui/tests/run-tests.sh critical
if [ $? -ne 0 ]; then
    echo "Critical tests failed. Commit aborted."
    exit 1
fi
```

### Weekly Cron Job
```bash
# Add to crontab
0 2 * * 1 cd /opt/homebrew/var/www/drupal/web && ./modules/custom/pmsrgui/tests/run-tests.sh all > /tmp/pmsr-tests.log 2>&1
```

## Troubleshooting

### Tests Fail: "Class not found"
```bash
# Regenerate autoload files
cd /opt/homebrew/var/www/drupal
composer dump-autoload
```

### Tests Fail: "Connection refused"
```bash
# Check services are running
curl http://localhost:8080  # Drupal
curl http://localhost:9001/hascoapi/api/statistics/namespaces  # hascoapi
curl http://localhost:3030/$/ping  # Fuseki
```

### JavaScript Tests Fail
```bash
# Install Playwright browsers
npx playwright install

# Run Playwright GUI checks
npx playwright test
```

### Database Errors
```bash
# Clear Drupal cache
vendor/bin/drush cache:rebuild

# Reset test database
vendor/bin/drush sql:drop --yes
vendor/bin/drush sql:cli < backup.sql
```

## Test Coverage Report

Generate code coverage report:
```bash
cd /opt/homebrew/var/www/drupal/web
vendor/bin/phpunit --coverage-html coverage-report modules/custom/pmsrgui/tests/
open coverage-report/index.html
```

## Expected Results

### All Green ✅
All tests pass - system is healthy

### Some Yellow 🔶
Mock-dependent tests incomplete - system likely healthy, but needs integration testing

### Any Red ❌
Data integrity issue detected - investigate immediately:
1. Check hasco.ttl file integrity
2. Verify API endpoints respond correctly
3. Check triplestore contains all entry points
4. Review recent ingestion logs

## Support

For issues or questions:
1. Check conversation summary in VS Code
2. Review HASCO-INTEGRITY-PROTECTION.md
3. Check test output for specific error messages
4. Contact development team

## Test Maintenance

Update tests when:
- Adding new entry points
- Changing PMSR binding
- Modifying ingestion process
- Adding new ontologies
- Changing API endpoints

Last Updated: 2026-07-19
