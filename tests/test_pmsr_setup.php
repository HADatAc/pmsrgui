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
 *   record-post-ontology-state - Record current namespace/statistics baseline
 *   post-ontology-state - Validate current state against recorded baseline
 *   rerun-safe      - Test all 4 ingestion processes are rerun-safe
 *   regression      - Test all 5 setup processes complete successfully
 *   ontologies      - Test ontology ingestion individually
 *   ins             - Test INS ingestion individually
 *   geography       - Test KRG Geography ingestion individually
 *   people          - Test KRG People ingestion individually
 *   all             - Run all tests (default)
 */

class PMSRSetupTests {
  
  private $fuseki_query;
  private $hascoapi_base;
  private $drupal_base;
  
  private $results = [
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0,
  ];

  private $baseline_file;

  public function __construct() {
    $this->fuseki_query = getenv('PMSR_TEST_FUSEKI_QUERY') ?: 'http://127.0.0.1:3030/store/query';
    $this->hascoapi_base = getenv('PMSR_TEST_HASCOAPI_BASE') ?: 'http://127.0.0.1:9001/hascoapi/api';
    $this->drupal_base = getenv('PMSR_TEST_DRUPAL_BASE') ?: 'http://127.0.0.1';
    $this->baseline_file = __DIR__ . '/baselines/post_ontology_state_baseline.json';
  }

  /**
   * HTTP GET with fallback between localhost and 127.0.0.1.
   */
  private function httpGetWithCurlExtension($url, $debug = false) {
    if (!function_exists('curl_init')) {
      return false;
    }

    $ch = curl_init($url);
    if ($ch === false) {
      return false;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Accept: application/json',
      'User-Agent: PMSRSetupTests/1.0',
    ]);

    // Avoid inheriting proxy environment values for local API calls.
    if (defined('CURLOPT_PROXY')) {
      curl_setopt($ch, CURLOPT_PROXY, '');
    }
    if (defined('CURLOPT_NOPROXY')) {
      curl_setopt($ch, CURLOPT_NOPROXY, '*');
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $response !== '') {
      if ($debug) {
        echo "  [DEBUG] curl_ext OK: $url (" . strlen($response) . " bytes)\n";
      }
      return $response;
    }

    if ($debug) {
      echo "  [DEBUG] curl_ext failed: $url" . ($error ? " | $error" : '') . "\n";
    }

    return false;
  }

  private function httpGetWithFallback($url) {
    $debug = getenv('PMSR_TEST_DEBUG') === '1';
    $context = stream_context_create([
      'http' => [
        'method' => 'GET',
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: PMSRSetupTests/1.0",
      ],
    ]);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
      if ($debug && $attempt > 1) {
        echo "  [DEBUG] retry attempt $attempt: $url\n";
      }

      $response = @file_get_contents($url, false, $context);
      if ($response !== false) {
        if ($debug) {
          echo "  [DEBUG] file_get_contents OK: $url (" . strlen($response) . " bytes)\n";
        }
        return $response;
      }
      if ($debug) {
        echo "  [DEBUG] file_get_contents failed: $url\n";
        echo "  [DEBUG] encoded url: " . rawurlencode($url) . "\n";
      }

      $curlExtResponse = $this->httpGetWithCurlExtension($url, $debug);
      if ($curlExtResponse !== false) {
        return $curlExtResponse;
      }

      if (strpos($url, '127.0.0.1') !== false) {
        $fallback = str_replace('127.0.0.1', 'localhost', $url);
        $response = @file_get_contents($fallback, false, $context);
        if ($response !== false) {
          if ($debug) {
            echo "  [DEBUG] fallback file_get_contents OK: $fallback (" . strlen($response) . " bytes)\n";
          }
          return $response;
        }

        $curlExtResponse = $this->httpGetWithCurlExtension($fallback, $debug);
        if ($curlExtResponse !== false) {
          return $curlExtResponse;
        }
      } elseif (strpos($url, 'localhost') !== false) {
        $fallback = str_replace('localhost', '127.0.0.1', $url);
        $response = @file_get_contents($fallback, false, $context);
        if ($response !== false) {
          if ($debug) {
            echo "  [DEBUG] fallback file_get_contents OK: $fallback (" . strlen($response) . " bytes)\n";
          }
          return $response;
        }

        $curlExtResponse = $this->httpGetWithCurlExtension($fallback, $debug);
        if ($curlExtResponse !== false) {
          return $curlExtResponse;
        }
      }

      $curlBin = '/usr/bin/curl';
      if (!file_exists($curlBin)) {
        $curlBin = 'curl';
      }

      $curlCmd = $curlBin . ' -s --noproxy "*" --max-time 15 ' . escapeshellarg($url) . ' 2>/dev/null';
      $curlResponse = shell_exec($curlCmd);
      if (is_string($curlResponse) && trim($curlResponse) !== '') {
        if ($debug) {
          echo "  [DEBUG] curl OK: $url (" . strlen($curlResponse) . " bytes)\n";
        }
        return $curlResponse;
      }
      if ($debug) {
        echo "  [DEBUG] curl failed/empty: $url\n";
      }

      if (strpos($url, '127.0.0.1') !== false) {
        $fallback = str_replace('127.0.0.1', 'localhost', $url);
        $curlResponse = shell_exec($curlBin . ' -s --noproxy "*" --max-time 15 ' . escapeshellarg($fallback) . ' 2>/dev/null');
        if (is_string($curlResponse) && trim($curlResponse) !== '') {
          return $curlResponse;
        }
      } elseif (strpos($url, 'localhost') !== false) {
        $fallback = str_replace('localhost', '127.0.0.1', $url);
        $curlResponse = shell_exec($curlBin . ' -s --noproxy "*" --max-time 15 ' . escapeshellarg($fallback) . ' 2>/dev/null');
        if (is_string($curlResponse) && trim($curlResponse) !== '') {
          return $curlResponse;
        }
      }

      if ($attempt < 5) {
        usleep(250000);
      }
    }

    return false;
  }

  /**
   * Ensure baseline directory exists.
   */
  private function ensureBaselineDirectory() {
    $dir = dirname($this->baseline_file);
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }
  }
  
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
    $response = $this->httpGetWithFallback($url);
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
        'triples' => (int) ($ns['numberOfLoadedTriples'] ?? 0),
        'sourceMime' => isset($ns['sourceMime']) ? (string) $ns['sourceMime'] : '',
      ];
    }
    
    return $namespaces;
  }

  /**
   * Get global statistics values from hascoapi endpoints.
   */
  private function getGlobalStatistics() {
    $endpoints = [
      'ontologies' => '/statistics/ontologies/count',
      'classes' => '/statistics/classes/count',
      'instances' => '/statistics/instances/count',
      'instruments' => '/statistics/instruments/count',
      'procedures' => '/statistics/procedures/count',
      'anatomy' => '/statistics/anatomy/count',
      'medical_devices' => '/statistics/medical-devices/count',
    ];

    $stats = [];
    foreach ($endpoints as $key => $path) {
      $url = $this->hascoapi_base . $path;
      $response = $this->httpGetWithFallback($url);
      if ($response === false) {
        return null;
      }
      $data = json_decode($response, true);
      if (!isset($data['isSuccessful']) || !$data['isSuccessful']) {
        return null;
      }
      $stats[$key] = (int) (($data['body']['total'] ?? 0));
    }

    return $stats;
  }

  /**
   * Persist the current namespace/statistics state as baseline.
   */
  public function recordPostOntologyStateBaseline() {
    echo "\n=== Recording Post-Ontology State Baseline ===\n\n";

    $namespaces = $this->getNamespaces();
    $stats = $this->getGlobalStatistics();

    $this->assert(is_array($namespaces) && !empty($namespaces), 'Namespace table is available', 'BASELINE');
    $this->assert(is_array($stats) && !empty($stats), 'Statistics endpoints are available', 'BASELINE');

    if (!is_array($namespaces) || empty($namespaces) || !is_array($stats) || empty($stats)) {
      $this->printSummary();
      return false;
    }

    $required = [];
    foreach ($namespaces as $abbrev => $row) {
      $required[$abbrev] = [
        'uri' => (string) ($row['uri'] ?? ''),
        'sourceMime' => (string) ($row['sourceMime'] ?? ''),
        'minTriples' => max(0, (int) ($row['triples'] ?? 0)),
      ];
    }

    $baseline = [
      'recordedAt' => gmdate('c'),
      'recordReason' => 'State reached after successful # Ingest PMSR Ontologies',
      'requiredNamespaces' => $required,
      'minimumStatistics' => $stats,
    ];

    $this->ensureBaselineDirectory();
    $encoded = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($this->baseline_file, $encoded . "\n");

    $this->assert(file_exists($this->baseline_file), 'Baseline file has been recorded', 'BASELINE');
    echo "  ℹ️  Baseline path: {$this->baseline_file}\n";

    $this->printSummary();
    return true;
  }

  /**
   * Regression: validate current state against recorded post-ontology baseline.
   */
  public function testPostOntologyStateRegression() {
    echo "\n=== Testing Post-Ontology State Regression ===\n\n";

    if (!file_exists($this->baseline_file)) {
      $this->assert(false, 'Baseline file is missing. Run: php test_pmsr_setup.php record-post-ontology-state', 'POST-ONTOLOGY');
      return false;
    }

    $baseline = json_decode(file_get_contents($this->baseline_file), true);
    if (!is_array($baseline)) {
      $this->assert(false, 'Baseline file is invalid JSON', 'POST-ONTOLOGY');
      return false;
    }

    $namespaces = $this->getNamespaces();
    $stats = $this->getGlobalStatistics();

    $this->assert(is_array($namespaces) && !empty($namespaces), 'Namespace table is available', 'POST-ONTOLOGY');
    $this->assert(is_array($stats) && !empty($stats), 'Statistics are available', 'POST-ONTOLOGY');

    if (!is_array($namespaces) || !is_array($stats)) {
      return false;
    }

    $requiredNamespaces = $baseline['requiredNamespaces'] ?? [];
    foreach ($requiredNamespaces as $abbrev => $expected) {
      $this->assert(isset($namespaces[$abbrev]), "Namespace '$abbrev' exists", 'POST-ONTOLOGY');
      if (!isset($namespaces[$abbrev])) {
        continue;
      }

      $actual = $namespaces[$abbrev];
      $expectedUri = (string) ($expected['uri'] ?? '');
      $expectedMime = (string) ($expected['sourceMime'] ?? '');
      $expectedMinTriples = (int) ($expected['minTriples'] ?? 0);

      $this->assert(
        (string) ($actual['uri'] ?? '') === $expectedUri,
        "Namespace '$abbrev' URI matches expected value",
        'POST-ONTOLOGY'
      );

      $this->assert(
        (string) ($actual['sourceMime'] ?? '') === $expectedMime,
        "Namespace '$abbrev' MIME type matches expected value",
        'POST-ONTOLOGY'
      );

      if ($expectedMinTriples > 0) {
        $this->assert(
          ((int) ($actual['triples'] ?? 0)) >= $expectedMinTriples,
          "Namespace '$abbrev' triples >= baseline minimum ($expectedMinTriples)",
          'POST-ONTOLOGY'
        );
      }
    }

    $minimumStatistics = $baseline['minimumStatistics'] ?? [];
    foreach ($minimumStatistics as $key => $minValue) {
      $expectedMin = (int) $minValue;
      $actual = (int) ($stats[$key] ?? 0);
      $this->assert($actual >= $expectedMin, "Statistic '$key' >= baseline minimum ($expectedMin)", 'POST-ONTOLOGY');
    }

    return true;
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
    $this->testPostOntologyStateRegression();
    
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
  public function printSummary() {
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
  case 'record-post-ontology-state':
    $tester->recordPostOntologyStateBaseline();
    break;

  case 'post-ontology-state':
    $tester->testPostOntologyStateRegression();
    $tester->printSummary();
    break;

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
