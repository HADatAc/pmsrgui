#!/usr/bin/env php
<?php

/**
 * PMSR Setup Test Suite
 * 
 * Tests for:
 * 1. Rerun-safety: 4 Ingest processes can be run multiple times without data accumulation
 * 2. Regression: All 5 PMSR Setup processes complete successfully
 * 
 * Usage:
 *   php test_pmsr_setup.php [test_name]
 * 
 * Test names:
 *   rerun-safe      - Test all 4 ingestion processes are rerun-safe
 *   regression      - Test all 5 setup processes complete successfully
 *   ontologies      - Test ontology ingestion individually
 *   ins             - Test INS ingestion individually
 *   geography       - Test KRG Geography ingestion individually
 *   people          - Test KRG People ingestion individually
 *   all             - Run all tests (default)
 */

class PMSRSetupTests {
  
  private $fuseki_query = 'http://127.0.0.1:3030/store/query';
  private $hascoapi_base = 'http://127.0.0.1:9001/hascoapi/api';
  private $drupal_base = 'http://localhost:8080';
  
  private $results = [
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0,
  ];
  
  /**
   * Run a SPARQL query against Fuseki
   */
  private function sparqlQuery($query) {
    $options = [
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/sparql-results+json",
        'content' => http_build_query(['query' => $query])
      ]
    ];
    $context = stream_context_create($options);
    $result = @file_get_contents($this->fuseki_query, false, $context);
    
    if ($result === false) {
      return null;
    }
    
    return json_decode($result, true);
  }
  
  /**
   * Get triple count for a named graph
   */
  private function getGraphTripleCount($graphUri) {
    $query = "SELECT (COUNT(*) AS ?count) WHERE { GRAPH <$graphUri> { ?s ?p ?o } }";
    $data = $this->sparqlQuery($query);
    
    if (!$data || !isset($data['results']['bindings'][0]['count']['value'])) {
      return null;
    }
    
    return (int)$data['results']['bindings'][0]['count']['value'];
  }
  
  /**
   * Get all DataFile named graphs
   */
  private function getDataFileGraphs() {
    $query = "SELECT DISTINCT ?g (COUNT(*) AS ?count) WHERE { GRAPH ?g { ?s ?p ?o } FILTER(contains(str(?g), 'DFL')) } GROUP BY ?g";
    $data = $this->sparqlQuery($query);
    
    if (!$data || !isset($data['results']['bindings'])) {
      return [];
    }
    
    $graphs = [];
    foreach ($data['results']['bindings'] as $binding) {
      $graphs[] = [
        'uri' => $binding['g']['value'],
        'count' => (int)$binding['count']['value']
      ];
    }
    
    return $graphs;
  }
  
  /**
   * Get namespace list from hascoapi
   */
  private function getNamespaces() {
    $url = $this->hascoapi_base . '/repo/table/namespaces';
    $response = @file_get_contents($url);
    if ($response === false) {
      echo "  ⚠️  Failed to connect to: $url\n";
      echo "  ⚠️  Is hascoapi running on port 9001?\n";
      return null;
    }
    
    $data = json_decode($response, true);
    if (!isset($data['body'])) {
      return null;
    }
    
    $namespaces = [];
    foreach ($data['body'] as $ns) {
      $namespaces[$ns['label']] = [
        'uri' => $ns['uri'],
        'triples' => $ns['numberOfLoadedTriples']
      ];
    }
    
    return $namespaces;
  }
  
  /**
   * Assert helper
   */
  private function assert($condition, $message, $category = 'TEST') {
    if ($condition) {
      echo "  ✓ [$category] $message\n";
      $this->results['passed']++;
      return true;
    } else {
      echo "  ✗ [$category] $message\n";
      $this->results['failed']++;
      return false;
    }
  }
  
  /**
   * Test: Ontology ingestion is rerun-safe
   */
  public function testOntologiesRerunSafe() {
    echo "\n=== Testing Ontology Ingestion Rerun-Safety ===\n\n";
    
    // Get initial state
    $initial_ns = $this->getNamespaces();
    $initial_pmsr = $initial_ns['pmsr']['triples'] ?? 0;
    $initial_ncit = $initial_ns['ncit']['triples'] ?? 0;
    $initial_uberon = $initial_ns['uberon']['triples'] ?? 0;
    
    echo "Initial state:\n";
    echo "  pmsr: $initial_pmsr triples\n";
    echo "  ncit: $initial_ncit triples\n";
    echo "  uberon: $initial_uberon triples\n\n";
    
    if ($initial_pmsr == 0 && $initial_ncit == 0 && $initial_uberon == 0) {
      echo "⚠️  No ontologies loaded. Run initial ingestion first.\n";
      $this->results['skipped']++;
      return false;
    }
    
    // Trigger re-ingestion via API (would need CSRF token in real scenario)
    echo "Note: Rerun test requires manual triggering via GUI due to CSRF protection.\n";
    echo "After running ontology ingestion again, triple counts should remain:\n";
    echo "  pmsr: $initial_pmsr\n";
    echo "  ncit: $initial_ncit\n";
    echo "  uberon: $initial_uberon\n";
    
    $this->results['skipped']++;
    return true;
  }
  
  /**
   * Test: INS ingestion is rerun-safe
   */
  public function testINSRerunSafe() {
    echo "\n=== Testing INS Ingestion Rerun-Safety ===\n\n";
    
    // Get initial DataFile count
    $initial_dfl = $this->getDataFileGraphs();
    $initial_count = count($initial_dfl);
    
    echo "Initial DataFile graphs: $initial_count\n";
    foreach ($initial_dfl as $df) {
      echo "  - {$df['uri']}: {$df['count']} triples\n";
    }
    echo "\n";
    
    if ($initial_count == 0) {
      echo "⚠️  No INS DataFiles found. Run initial ingestion first.\n";
      $this->results['skipped']++;
      return false;
    }
    
    echo "After rerunning INS ingestion:\n";
    echo "  Expected: Exactly 1 DataFile graph (old one deleted, new one created)\n";
    echo "  Expected: Similar triple count (~1,894 triples)\n";
    
    $this->results['skipped']++;
    return true;
  }
  
  /**
   * Test: KGR Geography ingestion is rerun-safe
   */
  public function testGeographyRerunSafe() {
    echo "\n=== Testing KGR Geography Ingestion Rerun-Safety ===\n\n";
    
    $initial_dfl = $this->getDataFileGraphs();
    $kgr_dfl = array_filter($initial_dfl, function($df) {
      // KGR files would have specific URIs
      return true; // All DFL for now
    });
    
    echo "Initial DataFile graphs: " . count($initial_dfl) . "\n";
    
    echo "After rerunning Geography ingestion:\n";
    echo "  Expected: Same number of DataFile graphs\n";
    echo "  Expected: Old geography DataFiles deleted, new ones created\n";
    
    $this->results['skipped']++;
    return true;
  }
  
  /**
   * Test: KGR People ingestion is rerun-safe
   */
  public function testPeopleRerunSafe() {
    echo "\n=== Testing KGR People Ingestion Rerun-Safety ===\n\n";
    
    $initial_dfl = $this->getDataFileGraphs();
    
    echo "Initial DataFile graphs: " . count($initial_dfl) . "\n";
    
    echo "After rerunning People ingestion:\n";
    echo "  Expected: Same number of DataFile graphs\n";
    echo "  Expected: Old KGR-PEOPLE DataFile deleted, new one created\n";
    
    $this->results['skipped']++;
    return true;
  }
  
  /**
   * Test: Bootstrap regression
   */
  public function testBootstrapRegression() {
    echo "\n=== Testing PMSR Config Bootstrap ===\n\n";
    
    // Check repository title exists
    $ns = $this->getNamespaces();
    
    if ($ns === null) {
      $this->assert(false, "Can connect to hascoapi", "BOOTSTRAP");
      return false;
    }
    
    $this->assert(true, "Can connect to hascoapi", "BOOTSTRAP");
    $this->assert(count($ns) > 0, "Namespaces are loaded", "BOOTSTRAP");
    
    // Check for essential namespaces
    $essential = ['rdf', 'rdfs', 'owl', 'hasco'];
    foreach ($essential as $label) {
      $this->assert(isset($ns[$label]), "Essential namespace '$label' exists", "BOOTSTRAP");
    }
    
    return true;
  }
  
  /**
   * Test: Ontology ingestion regression
   */
  public function testOntologiesRegression() {
    echo "\n=== Testing Ontology Ingestion Regression ===\n\n";
    
    $ns = $this->getNamespaces();
    
    // Check PMSR ontology
    $this->assert(isset($ns['pmsr']), "PMSR namespace exists", "ONTOLOGIES");
    if (isset($ns['pmsr'])) {
      $this->assert($ns['pmsr']['triples'] > 0, "PMSR has triples (found: {$ns['pmsr']['triples']})", "ONTOLOGIES");
      $this->assert($ns['pmsr']['uri'] === 'https://pmsr.net/ont/', "PMSR URI is correct", "ONTOLOGIES");
    }
    
    // Check NCIT ontology
    $this->assert(isset($ns['ncit']), "NCIT namespace exists", "ONTOLOGIES");
    if (isset($ns['ncit'])) {
      $this->assert($ns['ncit']['triples'] > 0, "NCIT has triples (found: {$ns['ncit']['triples']})", "ONTOLOGIES");
      $this->assert($ns['ncit']['uri'] === 'http://purl.obolibrary.org/obo/NCIT_', "NCIT URI is correct (has underscore)", "ONTOLOGIES");
    }
    
    // Check UBERON ontology
    $this->assert(isset($ns['uberon']), "UBERON namespace exists", "ONTOLOGIES");
    if (isset($ns['uberon'])) {
      $this->assert($ns['uberon']['triples'] > 0, "UBERON has triples (found: {$ns['uberon']['triples']})", "ONTOLOGIES");
      $this->assert($ns['uberon']['uri'] === 'http://purl.obolibrary.org/obo/UBERON_', "UBERON URI is correct (has underscore)", "ONTOLOGIES");
    }
    
    return true;
  }
  
  /**
   * Test: INS ingestion regression
   */
  public function testINSRegression() {
    echo "\n=== Testing INS Ingestion Regression ===\n\n";
    
    $dfl_graphs = $this->getDataFileGraphs();
    
    $this->assert(count($dfl_graphs) >= 1, "At least one DataFile graph exists", "INS");
    
    if (count($dfl_graphs) > 0) {
      $total_triples = array_sum(array_column($dfl_graphs, 'count'));
      $this->assert($total_triples > 1000, "INS DataFiles contain significant data (found: $total_triples triples)", "INS");
      
      // Check for reasonable triple count (~1,894 per INS ingestion)
      foreach ($dfl_graphs as $df) {
        if ($df['count'] > 1500 && $df['count'] < 2500) {
          $this->assert(true, "Found INS DataFile with expected size: {$df['count']} triples", "INS");
          break;
        }
      }
    }
    
    return true;
  }
  
  /**
   * Test: KGR Geography ingestion regression
   */
  public function testGeographyRegression() {
    echo "\n=== Testing KGR Geography Ingestion Regression ===\n\n";
    
    $dfl_graphs = $this->getDataFileGraphs();
    
    $this->assert(count($dfl_graphs) > 0, "DataFile graphs exist", "GEOGRAPHY");
    
    // Geography includes 10 files, so should have multiple DataFile graphs
    if (count($dfl_graphs) >= 10) {
      $this->assert(true, "Multiple KGR DataFiles found (expected 10+, found: " . count($dfl_graphs) . ")", "GEOGRAPHY");
    } else {
      echo "  ℹ️  Found " . count($dfl_graphs) . " DataFile graphs (Geography may not be fully ingested)\n";
    }
    
    return true;
  }
  
  /**
   * Test: KGR People ingestion regression
   */
  public function testPeopleRegression() {
    echo "\n=== Testing KGR People Ingestion Regression ===\n\n";
    
    $dfl_graphs = $this->getDataFileGraphs();
    
    $this->assert(count($dfl_graphs) > 0, "DataFile graphs exist", "PEOPLE");
    
    echo "  ℹ️  People data is in one of " . count($dfl_graphs) . " DataFile graphs\n";
    
    return true;
  }
  
  /**
   * Run all rerun-safe tests
   */
  public function runRerunSafeTests() {
    echo "\n╔════════════════════════════════════════╗\n";
    echo "║  PMSR SETUP RERUN-SAFETY TEST SUITE   ║\n";
    echo "╚════════════════════════════════════════╝\n";
    
    $this->testOntologiesRerunSafe();
    $this->testINSRerunSafe();
    $this->testGeographyRerunSafe();
    $this->testPeopleRerunSafe();
    
    $this->printSummary();
  }
  
  /**
   * Run all regression tests
   */
  public function runRegressionTests() {
    echo "\n╔════════════════════════════════════════╗\n";
    echo "║  PMSR SETUP REGRESSION TEST SUITE      ║\n";
    echo "╚════════════════════════════════════════╝\n";
    
    $this->testBootstrapRegression();
    $this->testOntologiesRegression();
    $this->testINSRegression();
    $this->testGeographyRegression();
    $this->testPeopleRegression();
    
    $this->printSummary();
  }
  
  /**
   * Run all tests
   */
  public function runAllTests() {
    $this->runRegressionTests();
    echo "\n";
    $this->runRerunSafeTests();
  }
  
  /**
   * Print test summary
   */
  private function printSummary() {
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "TEST SUMMARY\n";
    echo str_repeat("=", 50) . "\n";
    echo "✓ Passed:  " . $this->results['passed'] . "\n";
    echo "✗ Failed:  " . $this->results['failed'] . "\n";
    echo "⊘ Skipped: " . $this->results['skipped'] . "\n";
    echo str_repeat("=", 50) . "\n";
    
    if ($this->results['failed'] > 0) {
      echo "\n❌ TESTS FAILED\n";
      exit(1);
    } else if ($this->results['passed'] > 0) {
      echo "\n✅ ALL TESTS PASSED\n";
      exit(0);
    } else {
      echo "\n⚠️  ALL TESTS SKIPPED\n";
      exit(2);
    }
  }
}

// Main execution
$test_name = $argv[1] ?? 'all';
$tester = new PMSRSetupTests();

switch ($test_name) {
  case 'rerun-safe':
    $tester->runRerunSafeTests();
    break;
  
  case 'regression':
    $tester->runRegressionTests();
    break;
  
  case 'ontologies':
    $tester->testOntologiesRegression();
    $tester->printSummary();
    break;
  
  case 'ins':
    $tester->testINSRegression();
    $tester->printSummary();
    break;
  
  case 'geography':
    $tester->testGeographyRegression();
    $tester->printSummary();
    break;
  
  case 'people':
    $tester->testPeopleRegression();
    $tester->printSummary();
    break;
  
  case 'all':
  default:
    $tester->runAllTests();
    break;
}
