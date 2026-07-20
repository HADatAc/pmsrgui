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

# Change to Drupal root
cd "$(dirname "$0")/../../.."

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  PMSR Ingestion Regression Test Suite${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Check prerequisites
echo -e "${YELLOW}Checking prerequisites...${NC}"

# Check if PHPUnit is available
if ! command -v vendor/bin/phpunit &> /dev/null; then
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
        vendor/bin/phpunit $VERBOSE \
            modules/custom/pmsrgui/tests/src/Unit/ \
            modules/custom/rep/tests/src/Unit/
        ;;
    
    functional)
        echo -e "${BLUE}Running Functional Tests...${NC}"
        echo ""
        vendor/bin/phpunit $VERBOSE \
            modules/custom/pmsrgui/tests/src/Functional/
        ;;
    
    javascript)
        echo -e "${BLUE}Running JavaScript Tests...${NC}"
        echo ""
        if ! command -v chromedriver &> /dev/null; then
            echo -e "${RED}✗ ChromeDriver not found${NC}"
            echo -e "${YELLOW}Install with: brew install --cask chromedriver${NC}"
            exit 1
        fi
        vendor/bin/phpunit $VERBOSE \
            modules/custom/rep/tests/src/FunctionalJavascript/
        ;;
    
    validator)
        echo -e "${BLUE}Running HascoIntegrityValidator Tests...${NC}"
        echo ""
        vendor/bin/phpunit $VERBOSE \
            modules/custom/pmsrgui/tests/src/Unit/HascoIntegrityValidatorTest.php
        ;;
    
    integrity)
        echo -e "${BLUE}Running Ingestion Integrity Tests...${NC}"
        echo ""
        vendor/bin/phpunit $VERBOSE \
            modules/custom/pmsrgui/tests/src/Functional/IngestionIntegrityTest.php
        ;;
    
    api)
        echo -e "${BLUE}Running API Tests...${NC}"
        echo ""
        vendor/bin/phpunit $VERBOSE \
            modules/custom/rep/tests/src/Unit/TreeControllerApiTest.php
        ;;
    
    color)
        echo -e "${BLUE}Running Color-Coding Tests...${NC}"
        echo ""
        vendor/bin/phpunit $VERBOSE \
            modules/custom/rep/tests/src/FunctionalJavascript/EntryPointColorCodingTest.php
        ;;
    
    critical)
        echo -e "${BLUE}Running Critical Data Loss Prevention Tests...${NC}"
        echo ""
        vendor/bin/phpunit $VERBOSE \
            --filter "testHascoTtlContainsAllRequiredEntryPoints|testHascoTtlContainsClassEntryPointBaseClass|testPmsrCorrectlyBoundToWorkflowStemEntryPoint|testValidateHascoTtlDetectsMissingEntryPoints" \
            modules/custom/pmsrgui/tests/src/Functional/IngestionIntegrityTest.php
        ;;
    
    all)
        echo -e "${BLUE}Running All Tests...${NC}"
        echo ""
        
        echo -e "${YELLOW}1. Unit Tests${NC}"
        vendor/bin/phpunit $VERBOSE \
            modules/custom/pmsrgui/tests/src/Unit/ \
            modules/custom/rep/tests/src/Unit/ || true
        
        echo ""
        echo -e "${YELLOW}2. Functional Tests${NC}"
        vendor/bin/phpunit $VERBOSE \
            modules/custom/pmsrgui/tests/src/Functional/ || true
        
        echo ""
        echo -e "${YELLOW}3. JavaScript Tests (if ChromeDriver available)${NC}"
        if command -v chromedriver &> /dev/null; then
            vendor/bin/phpunit $VERBOSE \
                modules/custom/rep/tests/src/FunctionalJavascript/ || true
        else
            echo -e "${YELLOW}Skipping JavaScript tests - ChromeDriver not found${NC}"
        fi
        ;;
    
    help|--help|-h)
        echo "Usage: $0 [test-type] [--verbose]"
        echo ""
        echo "Test Types:"
        echo "  all         - Run all tests (default)"
        echo "  unit        - Run unit tests only"
        echo "  functional  - Run functional tests only"
        echo "  javascript  - Run JavaScript tests only"
        echo "  validator   - Run HascoIntegrityValidator tests"
        echo "  integrity   - Run ingestion integrity tests"
        echo "  api         - Run API endpoint tests"
        echo "  color       - Run color-coding tests"
        echo "  critical    - Run critical data loss prevention tests"
        echo "  help        - Show this help message"
        echo ""
        echo "Options:"
        echo "  --verbose   - Show detailed test output"
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
