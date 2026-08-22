<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\pmsr\Support\PmsrSetupTracker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Drupal\Core\Url;

/**
 * Bootstrap Controller for PMSR configuration.
 */
class BootstrapController extends ControllerBase {

  /**
   * Localhost configuration values.
   */
  const LOCALHOST_CONFIG = [
    'rep_home' => TRUE,
    'sagres_conf' => FALSE,
    'social_conf' => TRUE,
    'pmsr_new_landing_enabled' => TRUE,
    'social_initiative_uri' => 'https://pmsr.net/ont/P1T742783481383251',
    'site_name' => 'Portuguese Medical Simulation Repository',
    'site_label' => 'PMSR',
    'repository_domain_url' => 'http://pmsr.net',
    'repository_namespace_prefix' => 'pmsr',
    'repository_namespace_url' => 'https://pmsr.net/ont/',
    'repository_description' => 'Portuguese Medical Simulation Repository',
    'sagres_base_url' => 'https://52.214.194.214',
  ];

  /**
   * Display the localhost bootstrap page with live progress.
   */
  public function bootstrapLocalhostPage() {
    return $this->buildBootstrapPage(
      'pmsr.bootstrap_execute_localhost',
      'PMSR Localhost Bootstrap',
      'Bootstrapping PMSR configuration and ontologies...'
    );
  }

  /**
   * Display the cloud bootstrap page with live progress.
   */
  public function bootstrapCloudPage() {
    return $this->buildBootstrapPage(
      'pmsr.bootstrap_execute_cloud',
      'PMSR Cloud Bootstrap',
      'Bootstrapping PMSR configuration and ontologies using configured REP API Base URL...'
    );
  }

  /**
   * Build a bootstrap progress page.
   */
  private function buildBootstrapPage(string $executeRoute, string $title, string $subtitle): array {
    $build = [];

    $build['#attached']['library'][] = 'pmsr/bootstrap-progress';
    
    // Pass the API URL to JavaScript
    $api_url = Url::fromRoute($executeRoute)->setAbsolute()->toString();
    $build['#attached']['drupalSettings']['pmsr']['bootstrapApiUrl'] = $api_url;

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => '<div class="bootstrap-header">
        <h1>' . $this->t($title) . '</h1>
        <p>' . $this->t($subtitle) . '</p>
      </div>',
    ];

    $build['progress'] = [
      '#type' => 'markup',
      '#markup' => '<div id="bootstrap-live-progress">
        <div class="progress-step" id="step-api-check">
          <span class="step-icon">⏳</span>
          <span class="step-text">' . $this->t('Checking API connectivity...') . '</span>
        </div>
      </div>',
    ];

    return $build;
  }

  /**
   * Execute the localhost bootstrap process with streaming updates.
   */
  public function executeLocalhostBootstrap() {
    return $this->executeBootstrap();
  }

  /**
   * Execute the cloud bootstrap process with streaming updates.
   */
  public function executeCloudBootstrap() {
    return $this->executeBootstrap();
  }

  /**
   * Execute a bootstrap process with streaming updates.
   */
  private function executeBootstrap() {
    $response = new StreamedResponse();
    
    $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
    $response->headers->set('X-Accel-Buffering', 'no');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('Connection', 'keep-alive');
    
    $response->setCallback(function() {
      @ini_set('zlib.output_compression', '0');
      @ini_set('output_buffering', 'off');
      @ini_set('implicit_flush', '1');
      @set_time_limit(0);

      while (ob_get_level() > 0) {
        @ob_end_flush();
      }
      @ob_implicit_flush(TRUE);

      $this->performBootstrap();
    });
    
    return $response;
  }

  /**
   * Perform the actual bootstrap process.
   */
  private function performBootstrap() {
    $api = \Drupal::service('rep.api_connector');
    $config = \Drupal::service('config.factory')->getEditable('rep.settings');
    $original_api_url = trim((string) $config->get('api_url'));
    $api_url = $original_api_url !== '' ? $original_api_url : 'http://localhost:9001';

    // Bootstrap is the KG reset phase for setup flows; clear tests dashboard state.
    PmsrSetupTracker::resetAll('bootstrap');
    PmsrSetupTracker::markStageStarted('pmsr_config_bootstrap', 'Bootstrap started');

    // Step 0: Reset PMSR-specific non-Drupal caches/state once per bootstrap run.
    $this->sendProgress([
      'type' => 'step',
      'step' => 'pre-bootstrap-reset',
      'message' => 'Resetting PMSR bootstrap caches/state...',
    ]);

    $resetReport = $this->resetPmsrBootstrapCaches();
    if ($resetReport['status'] === 'error') {
      $this->sendProgress([
        'type' => 'step',
        'step' => 'pre-bootstrap-reset-error',
        'status' => 'error',
        'message' => 'Failed to reset PMSR bootstrap caches/state.',
        'details' => implode(' | ', $resetReport['messages']),
      ]);
      PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', false, 'Failed to reset PMSR bootstrap caches/state.');
      return;
    }

    $this->sendProgress([
      'type' => 'step',
      'step' => 'pre-bootstrap-reset-success',
      'status' => $resetReport['status'],
      'message' => 'PMSR bootstrap caches/state reset complete.',
      'details' => implode(' | ', $resetReport['messages']),
    ]);
    
    // Ensure downstream API calls use the configured REP API Base URL.
    $config->set('api_url', $api_url)->save();

    // Step 1: Check API connectivity
    $this->sendProgress([
      'type' => 'step',
      'step' => 'api-check',
      'message' => 'Checking API connectivity to ' . $api_url . '...',
    ]);

    try {
      $repo = $api->repoInfoNewIP($api_url);
      $obj = json_decode($repo);
      
      if (!isset($obj->isSuccessful)) {
        $this->sendProgress([
          'type' => 'step',
          'step' => 'api-error',
          'status' => 'error',
          'message' => 'Cannot connect to API. Please verify that hascoapi is running on ' . $api_url,
        ]);
        // Restore original API URL
        $config->set('api_url', $original_api_url)->save();
        PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', false, 'Cannot connect to API during bootstrap.');
        return;
      }
      
      $this->sendProgress([
        'type' => 'step',
        'step' => 'api-success',
        'status' => 'success',
        'message' => 'API connection successful',
      ]);
    } catch (\Exception $e) {
      $this->sendProgress([
        'type' => 'step',
        'step' => 'api-error',
        'status' => 'error',
        'message' => 'API connection failed: ' . $e->getMessage(),
      ]);
      // Restore original API URL
      $config->set('api_url', $original_api_url)->save();
      PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', false, 'API connection failed: ' . $e->getMessage());
      return;
    }

    // Step 2: Check if triplestore is empty
    $this->sendProgress([
      'type' => 'step',
      'step' => 'triplestore-check',
      'message' => 'Checking triplestore status...',
    ]);

    try {
      // First, do a direct SPARQL query to count all triples
      $this->sendProgress([
        'type' => 'step',
        'step' => 'sparql-count-start',
        'status' => 'info',
        'message' => 'Running SPARQL query to count all triples: SELECT (COUNT(*) AS ?count) WHERE { ?s ?p ?o }',
      ]);
      
      $countQuery = 'SELECT (COUNT(*) AS ?count) WHERE { ?s ?p ?o }';
      $countResult = $api->sparqlQueryWithTimeout($countQuery, 20, 5);
      $actualTripleCount = 0;
      
      if ($countResult) {
        $countObj = json_decode($countResult);
        if (isset($countObj->results->bindings[0]->count->value)) {
          $actualTripleCount = (int) $countObj->results->bindings[0]->count->value;
        }
      }
      
      $this->sendProgress([
        'type' => 'step',
        'step' => 'sparql-count-result',
        'status' => $actualTripleCount > 3 ? 'error' : 'info',
        'message' => 'Direct SPARQL count: ' . number_format($actualTripleCount) . ' triples in entire triplestore',
      ]);
      
      // NOTE FOR COPILOT: These 3 default repository triples are automatically created by hascoapi
      // when it first connects to an empty Fuseki triplestore. This happens in the OnStart class
      // during application startup, which calls RepositoryInstance.getInstance(). If no repository
      // exists, it creates a new Repository() object and saves it, inserting exactly 3 triples:
      //   1. <http://hadatac.org/kb/default/repository> rdf:type hasco:Repository
      //   2. <http://hadatac.org/kb/default/repository> hasco:hascoType hasco:Repository  
      //   3. <http://hadatac.org/kb/default/repository> vstoi:hasVersion "X.X" (version varies)
      // See: hascoapi/app/module/OnStart.java and hascoapi/app/org/hascoapi/RepositoryInstance.java
      
        // If we have exactly 3 triples, verify by exact string matching only.
        // No namespace inference/validation is performed here.
      if ($actualTripleCount === 3) {
        $this->sendProgress([
          'type' => 'step',
          'step' => 'verify-default-triples',
          'status' => 'info',
            'message' => 'Found exactly 3 triples. Verifying exact triple strings...',
        ]);

          // Fetch full triple content as raw strings for deterministic comparison.
          $allTriplesQuery = '
            SELECT ?s ?p ?o WHERE {
              ?s ?p ?o .
            }
          ';
          $allTriplesResult = $api->sparqlQuery($allTriplesQuery);

          $actualTriples = [];
          if ($allTriplesResult) {
            $allTriplesObj = json_decode($allTriplesResult);
            if (isset($allTriplesObj->results->bindings) && is_array($allTriplesObj->results->bindings)) {
              foreach ($allTriplesObj->results->bindings as $binding) {
                $s = $binding->s->value ?? '';
                $p = $binding->p->value ?? '';
                $oType = $binding->o->type ?? '';
                $oValue = $binding->o->value ?? '';

                if ($s === '' || $p === '' || $oValue === '') {
                  continue;
                }

                if ($oType === 'uri') {
                  $actualTriples[] = '<' . $s . '> <' . $p . '> <' . $oValue . '>';
                }
                else {
                  $escapedLiteral = addcslashes($oValue, "\\\"");
                  $actualTriples[] = '<' . $s . '> <' . $p . '> "' . $escapedLiteral . '"';
                }
              }
            }
          }

          sort($actualTriples);

          // Version literal can vary; accept known hasVersion predicate variants.
          $expectedFixedTriples = [
            '<http://hadatac.org/kb/default/repository> <http://hadatac.org/ont/hasco/hascoType> <http://hadatac.org/ont/hasco/Repository>',
          ];

          $expectedRdfTypeVariants = [
            '<http://hadatac.org/kb/default/repository> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://hadatac.org/ont/hasco/Repository>',
            '<http://hadatac.org/kb/default/repository> <http://www.w3.org/1999/02/22-rdf-syntax-ns#/type> <http://hadatac.org/ont/hasco/Repository>',
          ];

          $hasVersionLine = false;
          $versionNumber = '';
          foreach ($actualTriples as $line) {
            if (preg_match('/^<http:\/\/hadatac\.org\/kb\/default\/repository> <http:\/\/hadatac\.org\/ont\/vstoi#\/?hasVersion> "([^"]+)"$/', $line, $m)) {
              $hasVersionLine = true;
              $versionNumber = $m[1];
              break;
            }
          }

          $fixedMatches = 0;
          foreach ($expectedFixedTriples as $expectedLine) {
            if (in_array($expectedLine, $actualTriples, true)) {
              $fixedMatches++;
            }
          }

          $hasRdfTypeLine = false;
          foreach ($expectedRdfTypeVariants as $rdfTypeLine) {
            if (in_array($rdfTypeLine, $actualTriples, true)) {
              $hasRdfTypeLine = true;
              break;
            }
          }

          $isValidDefault = (count($actualTriples) === 3 && $fixedMatches === 1 && $hasRdfTypeLine && $hasVersionLine);

          if ($isValidDefault) {
            $this->sendProgress([
              'type' => 'step',
              'step' => 'default-triples-confirmed',
              'status' => 'success',
              'message' => 'Confirmed: Triplestore contains only default repository metadata (version ' . $versionNumber . ')',
              'details' => 'Verified by exact triple string matching only. Safe to proceed.',
            ]);
            // Treat as empty - continue with bootstrap
            $actualTripleCount = 0;
          }

          if (!$isValidDefault) {
            $this->sendProgress([
              'type' => 'step',
              'step' => 'unexpected-triples',
              'status' => 'error',
              'message' => 'Found 3 triples but they do not match expected default repository pattern',
              'details' => 'Expected exact strings for rdf:type + hascoType + hasVersion. Actual: ' . implode(' | ', $actualTriples),
            ]);
            // Restore original API URL
            $config->set('api_url', $original_api_url)->save();
            PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', false, 'Unexpected triples found in triplestore default state check.');
            return;
          }

          // Old semantic check kept disabled intentionally in favor of strict string-match.
          /*

          // Query to verify the default repository structure (accept any version number)
        $verifyQuery = '
          SELECT ?p ?o WHERE {
            <http://hadatac.org/kb/default/repository> ?p ?o .
          }
        ';
        $verifyResult = $api->sparqlQuery($verifyQuery);
        $isValidDefault = false;
        
        if ($verifyResult) {
          $verifyObj = json_decode($verifyResult);
          if (isset($verifyObj->results->bindings) && count($verifyObj->results->bindings) === 3) {
            $hasType = false;
            $hasHascoType = false;
            $hasVersion = false;
            
            foreach ($verifyObj->results->bindings as $binding) {
              $predicate = $binding->p->value ?? '';
              $object = $binding->o->value ?? '';
              
              if ($predicate === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type' && 
                  $object === 'http://hadatac.org/ont/hasco/Repository') {
                $hasType = true;
              }
              if ($predicate === 'http://hadatac.org/ont/hasco/hascoType' && 
                  $object === 'http://hadatac.org/ont/hasco/Repository') {
                $hasHascoType = true;
              }
                $isHasVersionPredicate = (
                  $predicate === 'http://hadatac.org/ont/vstoi#hasVersion' ||
                  $predicate === 'http://hadatac.org/ont/vstoi#/hasVersion' ||
                  preg_match('/[#\/]hasVersion$/', $predicate)
                );

                if ($isHasVersionPredicate && !empty($object)) {
                $hasVersion = true;
                $versionNumber = $object;
              }
            }
            
            $isValidDefault = $hasType && $hasHascoType && $hasVersion;
            
            if ($isValidDefault) {
              $this->sendProgress([
                'type' => 'step',
                'step' => 'default-triples-confirmed',
                'status' => 'success',
                'message' => 'Confirmed: Triplestore contains only default repository metadata (version ' . $versionNumber . ')',
                'details' => 'These 3 triples were auto-created by hascoapi on first startup. Safe to proceed.',
              ]);
              // Treat as empty - continue with bootstrap
              $actualTripleCount = 0;
            }
          }
        }
        
        if (!$isValidDefault) {
          $this->sendProgress([
            'type' => 'step',
            'step' => 'unexpected-triples',
            'status' => 'error',
            'message' => 'Found 3 triples but they do not match expected default repository pattern',
            'details' => 'Expected: default repository with rdf:type, hascoType, and hasVersion. Bootstrap aborted.',
          ]);
          // Restore original API URL
          $config->set('api_url', $original_api_url)->save();
          return;
        }

          */
      }
      
      // Check for existing ontologies
      $namespaces = $api->namespaceList();
      $nsObj = json_decode($namespaces);
      
      $namespaceCount = 0;
      $totalTriples = 0;
      $allNamespaces = [];
      
      if (isset($nsObj->isSuccessful) && $nsObj->isSuccessful && 
          isset($nsObj->body) && count($nsObj->body) > 0) {
        $namespaceCount = count($nsObj->body);
        
        // Debug: Show structure of first namespace object
        if (isset($nsObj->body[0])) {
          $firstNs = $nsObj->body[0];
          $props = [];
          foreach ($firstNs as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
              $props[] = $key . '=' . substr((string)$value, 0, 50);
            } else {
              $props[] = $key . '=[' . gettype($value) . ']';
            }
          }
          
          $this->sendProgress([
            'type' => 'step',
            'step' => 'namespace-structure-debug',
            'status' => 'info',
            'message' => 'Sample namespace object properties',
            'details' => implode(', ', array_slice($props, 0, 10)),
          ]);
        }
        
        // Check if any have triples
        $hasTriples = false;
        $loadedOntologies = [];
        foreach ($nsObj->body as $onto) {
          // Try multiple possible property names for the prefix/identifier
          $prefix = $onto->label ?? $onto->hasPrefix ?? $onto->prefix ?? 
                    $onto->namespace ?? 'unknown';
          
          // If still unknown, try extracting from URI
          if ($prefix === 'unknown') {
            if (isset($onto->uri)) {
              $prefix = basename(rtrim($onto->uri, '/'));
            } elseif (isset($onto->hasNamespaceURL)) {
              $prefix = basename(rtrim($onto->hasNamespaceURL, '/'));
            }
          }
          
          $tripleCount = $onto->numberOfLoadedTriples ?? $onto->hasNumberOfTriples ?? 
                        $onto->triples ?? $onto->numberOfTriples ?? $onto->tripleCount ?? 0;
          $totalTriples += $tripleCount;
          
          $allNamespaces[] = $prefix . ': ' . number_format($tripleCount);
          
          if ($tripleCount > 0) {
            $hasTriples = true;
            $loadedOntologies[] = $prefix . ' (' . number_format($tripleCount) . ' triples)';
          }
        }
        
        // Show detailed namespace analysis
        $this->sendProgress([
          'type' => 'step',
          'step' => 'namespace-metadata-analysis',
          'status' => 'info',
          'message' => 'Namespace metadata: ' . $namespaceCount . ' namespace(s), reported total: ' . number_format($totalTriples) . ' triples',
          'details' => 'First 10: ' . implode(', ', array_slice($allNamespaces, 0, 10)) . 
                      (count($allNamespaces) > 10 ? ' ... and ' . (count($allNamespaces) - 10) . ' more' : ''),
        ]);
        
        // Use the actual SPARQL count to determine if empty
        // Note: actualTripleCount has been set to 0 if we found only the 3 expected default repository triples
        if ($actualTripleCount > 0) {
          $this->sendProgress([
            'type' => 'step',
            'step' => 'triplestore-not-empty',
            'status' => 'error',
            'message' => 'Triplestore is NOT empty! Found ' . number_format($actualTripleCount) . ' triples via SPARQL.',
            'details' => 'The triplestore contains data beyond the expected default repository metadata (3 triples). You must erase the triplestore volume and restart hascoapi before bootstrapping. Bootstrap aborted.',
          ]);
          // Restore original API URL
          $config->set('api_url', $original_api_url)->save();
          PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', false, 'Triplestore is not empty; bootstrap aborted.');
          return;
        }
      } else {
        // No namespaces reported by API, but run SPARQL count to be sure
        $this->sendProgress([
          'type' => 'step',
          'step' => 'no-namespaces',
          'status' => 'info',
          'message' => 'API reports 0 namespaces. Running SPARQL count to verify...',
        ]);
        
        $actualCount = 0;
        try {
          $sparql = 'SELECT (COUNT(*) as ?count) WHERE { ?s ?p ?o }';
          $result = $api->sparqlQueryWithTimeout($sparql, 20, 5);
          $resultObj = json_decode($result);
          
          if (isset($resultObj->results->bindings[0]->count->value)) {
            $actualCount = (int)$resultObj->results->bindings[0]->count->value;
          }
          
          if ($actualCount > 0) {
            $this->sendProgress([
              'type' => 'step',
              'step' => 'triplestore-not-empty',
              'status' => 'error',
              'message' => 'SPARQL count found ' . number_format($actualCount) . ' triples despite API showing 0 namespaces!',
              'details' => 'The triplestore is NOT empty. Bootstrap aborted.',
            ]);
            // Restore original API URL
            $config->set('api_url', $original_api_url)->save();
            PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', false, 'Triplestore not empty by SPARQL verification; bootstrap aborted.');
            return;
          }
          
          $this->sendProgress([
            'type' => 'step',
            'step' => 'triplestore-empty',
            'status' => 'success',
            'message' => 'SPARQL count confirms: 0 triples - triplestore is empty',
          ]);
        } catch (\Exception $e) {
          $this->sendProgress([
            'type' => 'step',
            'step' => 'triplestore-empty',
            'status' => 'warning',
            'message' => 'Could not run SPARQL count. Assuming empty based on API report.',
          ]);
        }
      }
    } catch (\Exception $e) {
      $this->sendProgress([
        'type' => 'step',
        'step' => 'triplestore-check-error',
        'status' => 'warning',
        'message' => 'Could not check triplestore status: ' . $e->getMessage(),
        'details' => 'Proceeding with caution. If ontologies are already loaded, this may cause issues.',
      ]);
    }

    // Step 3: Configure repository in API
    $this->sendProgress([
      'type' => 'step',
      'step' => 'api-config',
      'message' => 'Configuring repository in API...',
    ]);

    try {
      $api->repoUpdateLabel($api_url, self::LOCALHOST_CONFIG['site_label']);
      $api->repoUpdateTitle($api_url, self::LOCALHOST_CONFIG['site_name']);
      $api->repoUpdateURL($api_url, self::LOCALHOST_CONFIG['repository_domain_url']);
      $api->repoUpdateDescription($api_url, self::LOCALHOST_CONFIG['repository_description']);
      $api->repoUpdateNamespace(
        $api_url,
        self::LOCALHOST_CONFIG['repository_namespace_prefix'],
        self::LOCALHOST_CONFIG['repository_namespace_url'],
        '',
        '',
        'pmsr-config-bootstrap'
      );
      
      $this->sendProgress([
        'type' => 'step',
        'step' => 'api-config-success',
        'status' => 'success',
        'message' => 'Repository configuration saved to API',
      ]);
    } catch (\Exception $e) {
      $this->sendProgress([
        'type' => 'step',
        'step' => 'api-config-error',
        'status' => 'error',
        'message' => 'Failed to configure repository in API: ' . $e->getMessage(),
      ]);
      PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', false, 'Failed to configure repository in API: ' . $e->getMessage());
      return;
    }

    // Step 4: Update local Drupal configuration
    $this->sendProgress([
      'type' => 'step',
      'step' => 'local-config',
      'message' => 'Updating local Drupal configuration...',
    ]);

    foreach (self::LOCALHOST_CONFIG as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();

    // Update Drupal site name
    $siteConfig = \Drupal::service('config.factory')->getEditable('system.site');
    $siteConfig->set('name', self::LOCALHOST_CONFIG['site_name']);
    $siteConfig->save();

    $this->sendProgress([
      'type' => 'step',
      'step' => 'local-config-success',
      'status' => 'success',
      'message' => 'Local configuration updated',
    ]);

    // Step 5: Load general purpose ontologies (hasco, vstoi, sio, owl, rdf, rdfs, etc.)
    // Define expected general purpose ontologies with metadata
    // IMPORTANT: Labels must match exactly what hascoapi assigns (see NameSpaces.java)
    $generalPurposeOntologies = [
      ['label' => 'schema', 'comment' => 'Schema.org vocabulary', 'url' => 'https://raw.githubusercontent.com/schemaorg/schemaorg/main/data/releases/25.0/schemaorg-all-https.ttl'],
      ['label' => 'hasco', 'comment' => 'Human-Aware Science Ontology', 'url' => 'https://hadatac.org/ont/hasco/'],
      ['label' => 'owl', 'comment' => 'Web Ontology Language', 'url' => 'https://www.w3.org/2002/07/owl#'],
      ['label' => 'vstoi', 'comment' => 'Virtual Solar-Terrestrial Observatory Instruments', 'url' => 'https://hadatac.org/ont/vstoi/'],
      ['label' => 'lcc-639-1', 'comment' => 'ISO 639-1 Language Codes', 'url' => 'https://www.omg.org/spec/LCC/20211101/Languages/ISO639-1-LanguageCodes.ttl'],
      ['label' => 'rdfs', 'comment' => 'RDF Schema', 'url' => 'https://www.w3.org/2000/01/rdf-schema#'],
      ['label' => 'bfo', 'comment' => 'Basic Formal Ontology', 'url' => 'http://purl.obolibrary.org/obo/bfo.owl'],
      ['label' => 'unit', 'comment' => 'QUDT Units', 'url' => 'http://qudt.org/vocab/unit/'],
      ['label' => 'rdf', 'comment' => 'Resource Description Framework', 'url' => 'https://www.w3.org/1999/02/22-rdf-syntax-ns#'],
      ['label' => 'sio', 'comment' => 'Semanticscience Integrated Ontology', 'url' => 'https://raw.githubusercontent.com/micheldumontier/semanticscience/master/ontology/sio/release/sio-release.owl'],
      ['label' => 'dcterms', 'comment' => 'Dublin Core Terms', 'url' => 'http://purl.org/dc/terms/'],
      ['label' => 'prov', 'comment' => 'PROV Ontology', 'url' => 'https://hadatac.org/ont/prov/'],
        ['label' => 'foaf', 'comment' => 'Friend of a Friend', 'url' => 'https://xmlns.com/foaf/spec/index.rdf'],
    ];

      // FOAF availability may vary by remote host/network; treat it as non-blocking.
      $optionalOntologyLabels = ['foaf'];

    // Send ontology list to create all cards upfront
    $this->sendProgress([
      'type' => 'ontology-list',
      'ontologies' => array_map(function($ont) {
        return [
          'label' => $ont['label'],
          'comment' => $ont['comment'],
          'status' => 'pending'
        ];
      }, $generalPurposeOntologies)
    ]);

    $this->sendProgress([
      'type' => 'step',
      'step' => 'ontologies-loading',
      'message' => 'Loading general purpose ontologies...',
    ]);

    try {
      // Mark all cards as loading
      foreach ($generalPurposeOntologies as $ont) {
        $this->sendProgress([
          'type' => 'ontology',
          'ontology' => $ont['label'],
          'status' => 'loading',
          'message' => 'Loading...'
        ]);
      }

      // Call hascoapi to load its ontologies
      $loadResult = $api->repoLoadOntologies();
      $loadObj = json_decode($loadResult);
      
      if (!$loadObj || !$loadObj->isSuccessful) {
        $loadMessage = (string) ($loadObj->body ?? 'Unknown error');
        $isPolicyBlocked = stripos($loadMessage, 'Ontology mutation is disabled') !== false;
        $isTimeoutDuringLoad = stripos($loadMessage, 'cURL error 28') !== false
          || stripos($loadMessage, 'Operation timed out') !== false;

        if ($isPolicyBlocked) {
          foreach ($generalPurposeOntologies as $ont) {
            $this->sendProgress([
              'type' => 'ontology',
              'ontology' => $ont['label'],
              'status' => 'warning',
              'message' => 'Skipped by policy'
            ]);
          }

          $this->sendProgress([
            'type' => 'step',
            'step' => 'ontologies-skipped-policy',
            'status' => 'warning',
            'message' => 'General ontology loading is disabled by current hascoapi policy.',
          ]);

          $this->sendProgress([
            'type' => 'complete',
            'status' => 'success',
            'message' => 'Bootstrap completed with policy restrictions.',
            'details' => 'Repository/local configuration was updated. General ontology loading via /repo/ont/load is disabled. Next step: use "Ingest PMSR Ontologies" to load pmsr, ncit, and uberon.',
          ]);
          return;
        }

        // In cloud environments, hascoapi can keep loading ontologies even if
        // this HTTP call times out. Continue with namespace verification first.
        if ($isTimeoutDuringLoad) {
          $this->sendProgress([
            'type' => 'step',
            'step' => 'ontologies-timeout-verify',
            'status' => 'warning',
            'message' => 'Ontology load request timed out. Verifying loaded namespaces before reporting failure...',
          ]);
        }
        else {
          // Mark all cards as error
          foreach ($generalPurposeOntologies as $ont) {
            $this->sendProgress([
              'type' => 'ontology',
              'ontology' => $ont['label'],
              'status' => 'error',
              'message' => 'Failed to load'
            ]);
          }
          
          $this->sendProgress([
            'type' => 'step',
            'step' => 'ontologies-error',
            'status' => 'error',
            'message' => 'Failed to load ontologies: ' . $loadMessage,
          ]);
          PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', false, 'Failed to load ontologies: ' . $loadMessage);
          return;
        }
      }
      
      $this->sendProgress([
        'type' => 'step',
        'step' => 'ontologies-started',
        'status' => 'info',
        'message' => 'Ontology loading initiated. Verifying...',
      ]);
      
      // Wait for ontologies to load (they load asynchronously)
      // Longer wait to ensure triple counts are updated
      sleep(10);
      
      // Force refresh of namespace cache in hascoapi
      $api->repoResetNamespaces('pmsr-config-bootstrap');
      sleep(2);
      
      // Get loaded namespaces from hascoapi
      $namespaces = $api->namespaceList();
      $nsObj = json_decode($namespaces);
      
      $loadedNamespaces = [];
      if (isset($nsObj->isSuccessful) && $nsObj->isSuccessful && isset($nsObj->body)) {
        foreach ($nsObj->body as $ns) {
          $label = $ns->label ?? '';
          $triples = $ns->numberOfLoadedTriples ?? 0;
          // Store by exact label (no case conversion)
          $loadedNamespaces[$label] = [
            'label' => $label,
            'triples' => $triples
          ];
        }
      }
      
      // Update each card based on what was actually loaded
      $successCount = 0;
      $failedCount = 0;
      $optionalWarningCount = 0;
      
      foreach ($generalPurposeOntologies as $ont) {
        // Exact label match (case-sensitive)
        if (isset($loadedNamespaces[$ont['label']]) && $loadedNamespaces[$ont['label']]['triples'] > 0) {
          // Success
          $this->sendProgress([
            'type' => 'ontology',
            'ontology' => $ont['label'],
            'status' => 'success',
            'message' => 'Loaded successfully',
            'triples' => $loadedNamespaces[$ont['label']]['triples']
          ]);
          $successCount++;
        } else {
          // Warning or error - ontology not found or has no triples
          $this->sendProgress([
            'type' => 'ontology',
            'ontology' => $ont['label'],
            'status' => 'warning',
            'message' => 'Not loaded or no triples'
          ]);

            if (in_array($ont['label'], $optionalOntologyLabels, true)) {
              $optionalWarningCount++;
            } else {
              $failedCount++;
            }
        }
        
        // Small delay to make the UI updates visible
        usleep(100000); // 0.1 second
      }
      
        if ($failedCount > 0) {
        $this->sendProgress([
          'type' => 'step',
          'step' => 'ontologies-warning',
          'status' => 'warning',
            'message' => "Loaded $successCount ontologies, $failedCount required ontologies had issues",
        ]);
        
        // Bootstrap failed - not all ontologies loaded
        $this->sendProgress([
          'type' => 'complete',
          'status' => 'error',
          'message' => 'Bootstrap failed!',
            'details' => "Only $successCount of " . count($generalPurposeOntologies) . " general purpose ontologies loaded successfully. $failedCount required ontologies had issues. Please check hascoapi logs and retry.",
        ]);
        return;
      } else {
          $successDetails = "All required ontologies loaded successfully";
          if ($optionalWarningCount > 0) {
            $successDetails .= "; $optionalWarningCount optional ontology warnings (non-blocking)";
          }

        $this->sendProgress([
          'type' => 'step',
          'step' => 'ontologies-success',
          'status' => 'success',
            'message' => $successDetails,
        ]);
        
        // Bootstrap complete - configuration is ready
        $this->sendProgress([
          'type' => 'complete',
          'status' => 'success',
          'message' => 'Bootstrap completed successfully!',
          'details' => 'Repository configuration and general purpose ontologies loaded. Next step: Use "Ingest PMSR Ontologies" to load pmsr, ncit, and uberon domain ontologies.',
        ]);
      }
    } catch (\Exception $e) {
      // Mark all cards as error
      foreach ($generalPurposeOntologies as $ont) {
        $this->sendProgress([
          'type' => 'ontology',
          'ontology' => $ont['label'],
          'status' => 'error',
          'message' => 'Error'
        ]);
      }
      
      $this->sendProgress([
        'type' => 'complete',
        'status' => 'error',
        'message' => 'Bootstrap failed!',
        'details' => 'Failed to load ontologies: ' . $e->getMessage(),
      ]);
      return;
    }
  }

  /**
   * Send progress update to the client.
   */
  private function sendProgress($data) {
    if (is_array($data) && ($data['type'] ?? '') === 'complete') {
      $status = (string) ($data['status'] ?? '');
      $message = (string) ($data['message'] ?? 'Bootstrap completed.');
      PmsrSetupTracker::markStageResult('pmsr_config_bootstrap', $status === 'success', $message);
    }

    echo json_encode($data) . "\n";
    @flush();
  }

  /**
   * Reset PMSR caches/state that live outside Drupal cache bins.
   *
   * @return array{status:string,messages:array}
   *   status: success|warning|error
   */
  private function resetPmsrBootstrapCaches() {
    $messages = [];
    $hasErrors = FALSE;
    $hasWarnings = FALSE;

    // 1) Clear UI/session state keys used by PMSR-related forms.
    try {
      $session = \Drupal::request()->getSession();
      $keys = array_keys((array) $session->all());
      $removed = 0;
      foreach ($keys as $key) {
        $normalized = (string) $key;
        if (
          strpos($normalized, 'rep_select_') === 0 ||
          strpos($normalized, 'dpl_select_') === 0 ||
          strpos($normalized, 'dpl_manage_streams_') === 0 ||
          $normalized === 'social_view_type'
        ) {
          $session->remove($normalized);
          $removed++;
        }
      }
      $messages[] = 'Session state reset: removed ' . $removed . ' key(s).';
    }
    catch (\Throwable $e) {
      $hasWarnings = TRUE;
      $messages[] = 'Session state reset warning: ' . $e->getMessage();
    }

    // 2) Clear hasco.ttl emergency backup cache files.
    $fs = \Drupal::service('file_system');
    $backupUri = 'private://ont/emergency-backups';
    $backupPath = $fs->realpath($backupUri);
    if ($backupPath && is_dir($backupPath)) {
      $result = $this->clearDirectoryContents($backupPath);
      if ($result['ok']) {
        $messages[] = 'Cleared emergency backup cache (' . $result['deleted'] . ' item(s)).';
      }
      else {
        $hasWarnings = TRUE;
        $messages[] = 'Emergency backup cache clear warning: ' . $result['message'];
      }
    }
    else {
      $messages[] = 'Emergency backup cache not present (nothing to clear).';
    }

    // 3) Clear hasco.ttl version snapshots cache files.
    $versionsUri = 'private://ont/versions';
    $versionsPath = $fs->realpath($versionsUri);
    if ($versionsPath && is_dir($versionsPath)) {
      $result = $this->clearDirectoryContents($versionsPath);
      if ($result['ok']) {
        $messages[] = 'Cleared ontology version snapshots (' . $result['deleted'] . ' item(s)).';
      }
      else {
        $hasWarnings = TRUE;
        $messages[] = 'Version snapshots clear warning: ' . $result['message'];
      }
    }
    else {
      $messages[] = 'Ontology version snapshots not present (nothing to clear).';
    }

    // 4) In-process memoization caches are request-scoped and naturally reset.
    $messages[] = 'In-memory request caches reset automatically per request.';

    return [
      'status' => $hasErrors ? 'error' : ($hasWarnings ? 'warning' : 'success'),
      'messages' => $messages,
    ];
  }

  /**
   * Remove all files/subdirectories under a directory while preserving root.
   *
   * @return array{ok:bool,deleted:int,message:string}
   */
  private function clearDirectoryContents(string $directoryPath): array {
    $deleted = 0;
    try {
      $items = scandir($directoryPath);
      if (!is_array($items)) {
        return ['ok' => FALSE, 'deleted' => 0, 'message' => 'Cannot read directory.'];
      }

      foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
          continue;
        }
        $fullPath = $directoryPath . DIRECTORY_SEPARATOR . $item;
        $this->deletePathRecursive($fullPath, $deleted);
      }

      return ['ok' => TRUE, 'deleted' => $deleted, 'message' => ''];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'deleted' => $deleted, 'message' => $e->getMessage()];
    }
  }

  /**
   * Recursively delete a file or directory.
   */
  private function deletePathRecursive(string $path, int &$deletedCount): void {
    if (is_dir($path) && !is_link($path)) {
      $children = scandir($path);
      if (is_array($children)) {
        foreach ($children as $child) {
          if ($child === '.' || $child === '..') {
            continue;
          }
          $this->deletePathRecursive($path . DIRECTORY_SEPARATOR . $child, $deletedCount);
        }
      }
      if (@rmdir($path)) {
        $deletedCount++;
      }
      return;
    }

    if (@unlink($path)) {
      $deletedCount++;
    }
  }

}
