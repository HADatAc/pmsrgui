#!/bin/bash

# PMSR Ingestion Regression Test Runner
# Runs comprehensive test suite for data loss prevention and UI features

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Resolve Drupal project root by walking up to the directory that contains
# both composer.json and web/core.
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DRUPAL_ROOT="$SCRIPT_DIR"
while [[ "$DRUPAL_ROOT" != "/" && !( -f "$DRUPAL_ROOT/composer.json" && -d "$DRUPAL_ROOT/web/core" ) ]]; do
    DRUPAL_ROOT="$(dirname "$DRUPAL_ROOT")"
done

if [[ ! -f "$DRUPAL_ROOT/composer.json" || ! -d "$DRUPAL_ROOT/web/core" ]]; then
    echo -e "${RED}✗ Unable to find Drupal project root (expected composer.json + web/core)${NC}"
    exit 1
fi

cd "$DRUPAL_ROOT"

if [[ -d "$DRUPAL_ROOT/web/modules/custom" ]]; then
    CUSTOM_MODULES_DIR="web/modules/custom"
elif [[ -d "$DRUPAL_ROOT/modules/custom" ]]; then
    CUSTOM_MODULES_DIR="modules/custom"
else
    echo -e "${RED}✗ Unable to find custom modules directory (expected web/modules/custom or modules/custom)${NC}"
    exit 1
fi

PHPUNIT_CONFIG_DEFAULT="$DRUPAL_ROOT/$CUSTOM_MODULES_DIR/pmsrgui/tests/phpunit.xml"
if [[ -f "$PHPUNIT_CONFIG_DEFAULT" ]]; then
    PHPUNIT_CONFIG="${PHPUNIT_CONFIG:-$PHPUNIT_CONFIG_DEFAULT}"
elif [[ -f "$DRUPAL_ROOT/web/core/phpunit.xml.dist" ]]; then
    PHPUNIT_CONFIG="${PHPUNIT_CONFIG:-$DRUPAL_ROOT/web/core/phpunit.xml.dist}"
else
    echo -e "${RED}✗ Unable to find a PHPUnit configuration file${NC}"
    exit 1
fi

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  PMSR Ingestion Regression Test Suite${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Test-result database configuration.
# Override via environment variables when needed.
DRUPAL_DB_HOST="${DRUPAL_DB_HOST:-localhost}"
DRUPAL_DB_PORT="${DRUPAL_DB_PORT:-3306}"
DRUPAL_DB_USER="${DRUPAL_DB_USER:-drupal}"
DRUPAL_DB_PASS="${DRUPAL_DB_PASS:-drupal}"
TEST_RESULT_DB_NAME="${TEST_RESULT_DB_NAME:-drupal_test_results}"
TEST_RESULT_DB_URL="mysql://${DRUPAL_DB_USER}:${DRUPAL_DB_PASS}@${DRUPAL_DB_HOST}:${DRUPAL_DB_PORT}/${TEST_RESULT_DB_NAME}"

ensure_test_result_database() {
    echo -e "${YELLOW}Ensuring test-result database exists: ${TEST_RESULT_DB_NAME}${NC}"
    if ! mysql -h "${DRUPAL_DB_HOST}" -P "${DRUPAL_DB_PORT}" -u"${DRUPAL_DB_USER}" -p"${DRUPAL_DB_PASS}" \
        -e "CREATE DATABASE IF NOT EXISTS \`${TEST_RESULT_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" > /dev/null 2>&1; then
        echo -e "${RED}✗ Unable to create/access database '${TEST_RESULT_DB_NAME}' with current credentials${NC}"
        echo -e "${YELLOW}  Grant CREATE privileges or pre-create the DB, then rerun tests.${NC}"
        echo -e "${YELLOW}  Current target URL: ${TEST_RESULT_DB_URL}${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ Test-result database ready: ${TEST_RESULT_DB_NAME}${NC}"
}

# Check prerequisites
echo -e "${YELLOW}Checking prerequisites...${NC}"

# Check if PHPUnit is available
if [[ ! -x "vendor/bin/phpunit" ]]; then
    echo -e "${RED}✗ PHPUnit not found. Run: composer require --dev phpunit/phpunit${NC}"
    exit 1
fi
echo -e "${GREEN}✓ PHPUnit found${NC}"

# Check if hascoapi is running
if ! curl -s http://localhost:9001/hascoapi/api/statistics/namespaces > /dev/null 2>&1; then
    echo -e "${YELLOW}⚠ hascoapi not running at localhost:9001${NC}"
    echo -e "${YELLOW}  Some integration tests will be skipped${NC}"
else
    echo -e "${GREEN}✓ hascoapi running${NC}"
fi

# Check if Drupal is accessible
if ! curl -s http://localhost:8080 > /dev/null 2>&1; then
    echo -e "${YELLOW}⚠ Drupal not accessible at localhost:8080${NC}"
    echo -e "${YELLOW}  Functional tests will fail${NC}"
else
    echo -e "${GREEN}✓ Drupal accessible${NC}"
fi

# Check if hasco.ttl exists
if [ ! -f "/Users/Shared/drupal_private/ont/hasco.ttl" ]; then
    echo -e "${YELLOW}⚠ hasco.ttl not found at /Users/Shared/drupal_private/ont/${NC}"
    echo -e "${YELLOW}  Some tests will be skipped${NC}"
else
    echo -e "${GREEN}✓ hasco.ttl found${NC}"
fi

echo ""
echo -e "${BLUE}================================================${NC}"

# Parse command line arguments
TEST_TYPE="${1:-all}"
VERBOSE="${2:-}"

case "$TEST_TYPE" in
    unit)
        echo -e "${BLUE}Running Unit Tests...${NC}"
        echo ""
        vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/pmsrgui/tests/src/Unit/ \
            "$CUSTOM_MODULES_DIR"/rep/tests/src/Unit/
        ;;
    
    functional)
        echo -e "${BLUE}Running Functional Tests...${NC}"
        echo ""
        ensure_test_result_database
        SIMPLETEST_DB="$TEST_RESULT_DB_URL" vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/pmsrgui/tests/src/Functional/
        ;;
    
    javascript)
        echo -e "${BLUE}Running JavaScript Tests...${NC}"
        echo ""
        ensure_test_result_database
        if ! command -v chromedriver &> /dev/null; then
            echo -e "${RED}✗ ChromeDriver not found${NC}"
            echo -e "${YELLOW}Install with: brew install --cask chromedriver${NC}"
            exit 1
        fi
        SIMPLETEST_DB="$TEST_RESULT_DB_URL" vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/rep/tests/src/FunctionalJavascript/
        ;;
    
    validator)
        echo -e "${BLUE}Running HascoIntegrityValidator Tests...${NC}"
        echo ""
        vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/pmsrgui/tests/src/Unit/HascoIntegrityValidatorTest.php
        ;;
    
    integrity)
        echo -e "${BLUE}Running Ingestion Integrity Tests...${NC}"
        echo ""
        ensure_test_result_database
        SIMPLETEST_DB="$TEST_RESULT_DB_URL" vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/pmsrgui/tests/src/Functional/IngestionIntegrityTest.php
        ;;
    
    api)
        echo -e "${BLUE}Running API Tests...${NC}"
        echo ""
        vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/rep/tests/src/Unit/TreeControllerApiTest.php
        ;;
    
    color)
        echo -e "${BLUE}Running Color-Coding Tests...${NC}"
        echo ""
        ensure_test_result_database
        SIMPLETEST_DB="$TEST_RESULT_DB_URL" vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/rep/tests/src/FunctionalJavascript/EntryPointColorCodingTest.php
        ;;
    
    setup)
        echo -e "${BLUE}Running PMSR Setup Tests...${NC}"
        echo ""
        php "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_pmsr_setup.php all
        ;;
    
    setup-regression)
        echo -e "${BLUE}Running PMSR Setup Regression Tests...${NC}"
        echo ""
        php "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_pmsr_setup.php regression
        echo ""
        echo -e "${BLUE}Running Namespace Policy Regression...${NC}"
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_namespace_policy.sh
        echo ""
        echo -e "${BLUE}Running Entry-Point Soundness Regression...${NC}"
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_entrypoint_soundness.sh
        echo ""
        echo -e "${BLUE}Running Entry-Point Color-Coding Regression...${NC}"
        if command -v chromedriver &> /dev/null; then
            ensure_test_result_database
            SIMPLETEST_DB="$TEST_RESULT_DB_URL" vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
                "$CUSTOM_MODULES_DIR"/rep/tests/src/FunctionalJavascript/EntryPointColorCodingTest.php
        else
            echo -e "${YELLOW}Skipping Entry-Point Color-Coding Regression - ChromeDriver not found${NC}"
        fi
        ;;

    namespace-policy)
        echo -e "${BLUE}Running Namespace Policy Regression...${NC}"
        echo ""
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_namespace_policy.sh
        ;;

    entrypoints-soundness)
        echo -e "${BLUE}Running Entry-Point Soundness Regression...${NC}"
        echo ""
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_entrypoint_soundness.sh
        ;;

    safety-gate)
        echo -e "${BLUE}Running Namespace Safety Gate (strict)...${NC}"
        echo ""
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_namespace_policy.sh
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_entrypoint_soundness.sh
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/compare_baseline_minimums.sh

        if ! command -v chromedriver &> /dev/null; then
            echo -e "${RED}✗ ChromeDriver not found (required for safety-gate color-coding verification)${NC}"
            exit 1
        fi
        ensure_test_result_database
        SIMPLETEST_DB="$TEST_RESULT_DB_URL" vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/rep/tests/src/FunctionalJavascript/EntryPointColorCodingTest.php
        ;;
    
    setup-rerun)
        echo -e "${BLUE}Running PMSR Setup Rerun-Safety Tests...${NC}"
        echo ""
        php "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_pmsr_setup.php rerun-safe
        ;;
    
    critical)
        echo -e "${BLUE}Running Critical Data Loss Prevention Tests...${NC}"
        echo ""
        vendor/bin/phpunit $VERBOSE \
            --filter "testHascoTtlContainsAllRequiredEntryPoints|testHascoTtlContainsClassEntryPointBaseClass|testPmsrCorrectlyBoundToWorkflowStemEntryPoint|testValidateHascoTtlDetectsMissingEntryPoints" \
            "$CUSTOM_MODULES_DIR"/pmsrgui/tests/src/Functional/IngestionIntegrityTest.php
        ;;
    
    all)
        echo -e "${BLUE}Running All Tests...${NC}"
        echo ""
        
        echo -e "${YELLOW}1. Unit Tests${NC}"
        vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/pmsrgui/tests/src/Unit/ \
            "$CUSTOM_MODULES_DIR"/rep/tests/src/Unit/ || true
        
        echo ""
        echo -e "${YELLOW}2. Functional Tests${NC}"
        ensure_test_result_database
        SIMPLETEST_DB="$TEST_RESULT_DB_URL" vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
            "$CUSTOM_MODULES_DIR"/pmsrgui/tests/src/Functional/ || true
        
        echo ""
        echo -e "${YELLOW}3. JavaScript Tests (if ChromeDriver available)${NC}"
        if command -v chromedriver &> /dev/null; then
            SIMPLETEST_DB="$TEST_RESULT_DB_URL" vendor/bin/phpunit -c "$PHPUNIT_CONFIG" $VERBOSE \
                "$CUSTOM_MODULES_DIR"/rep/tests/src/FunctionalJavascript/ || true
        else
            echo -e "${YELLOW}Skipping JavaScript tests - ChromeDriver not found${NC}"
        fi
        
        echo ""
        echo -e "${YELLOW}4. PMSR Setup Tests${NC}"
        php "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_pmsr_setup.php all || true

        echo ""
        echo -e "${YELLOW}5. Namespace Policy Regression${NC}"
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_namespace_policy.sh || true

        echo ""
        echo -e "${YELLOW}6. Entry-Point Soundness Regression${NC}"
        bash "$CUSTOM_MODULES_DIR"/pmsrgui/tests/test_entrypoint_soundness.sh || true
        ;;
    
    help|--help|-h)
        echo "Usage: $0 [test-type] [--verbose]"
        echo ""
        echo "Test Types:"
        echo "  all              - Run all tests (default)"
        echo "  unit             - Run unit tests only"
        echo "  functional       - Run functional tests only"
        echo "  javascript       - Run JavaScript tests only"
        echo "  validator        - Run HascoIntegrityValidator tests"
        echo "  integrity        - Run ingestion integrity tests"
        echo "  api              - Run API endpoint tests"
        echo "  color            - Run color-coding tests"
        echo "  setup            - Run PMSR Setup tests (all)"
        echo "  setup-regression - Run PMSR Setup regression tests"
        echo "  setup-rerun      - Run PMSR Setup rerun-safety tests"
        echo "  namespace-policy - Run namespace policy regression test"
        echo "  entrypoints-soundness - Run entry-point soundness regression test"
        echo "  safety-gate      - Run strict namespace corruption prevention gate"
        echo "  critical         - Run critical data loss prevention tests"
        echo "  help             - Show this help message"
        echo ""
        echo "Options:"
        echo "  --verbose   - Show detailed test output"
        echo ""
        echo "Environment overrides:"
        echo "  DRUPAL_DB_HOST, DRUPAL_DB_PORT, DRUPAL_DB_USER, DRUPAL_DB_PASS"
        echo "  TEST_RESULT_DB_NAME"
        echo ""
        echo "Examples:"
        echo "  $0                    # Run all tests"
        echo "  $0 unit               # Run only unit tests"
        echo "  $0 critical --verbose # Run critical tests with details"
        exit 0
        ;;
    
    *)
        echo -e "${RED}Unknown test type: $TEST_TYPE${NC}"
        echo "Run '$0 help' for usage information"
        exit 1
        ;;
esac

echo ""
echo -e "${BLUE}================================================${NC}"
echo -e "${GREEN}Test execution completed!${NC}"
echo -e "${BLUE}================================================${NC}"
