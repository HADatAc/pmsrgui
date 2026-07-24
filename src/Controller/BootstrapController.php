<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
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
    'api_url' => 'http://localhost:9001',
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
    $build = [];

    $build['#attached']['library'][] = 'pmsr/bootstrap-progress';
    
    // Pass the API URL to JavaScript
    $api_url = Url::fromRoute('pmsr.bootstrap_execute_localhost')->setAbsolute()->toString();
    $build['#attached']['drupalSettings']['pmsr']['bootstrapApiUrl'] = $api_url;

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => '<div class="bootstrap-header">
        <h1>' . $this->t('PMSR Localhost Bootstrap') . '</h1>
        <p>' . $this->t('Bootstrapping PMSR configuration and ontologies...') . '</p>
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
    $response = new StreamedResponse();
    
    $response->headers->set('Content-Type', 'application/json');
    $response->headers->set('X-Accel-Buffering', 'no');
    $response->headers->set('Cache-Control', 'no-cache');
    
    $response->setCallback(function() {
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
    $api_url = self::LOCALHOST_CONFIG['api_url'];
    
    // Temporarily set the API URL in config so API connector methods work
    $original_api_url = $config->get('api_url');
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
      $countResult = $api->sparqlQuery($countQuery);
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
      
      // If we have exactly 3 triples, verify they are the expected default repository metadata
      if ($actualTripleCount === 3) {
        $this->sendProgress([
          'type' => 'step',
          'step' => 'verify-default-triples',
          'status' => 'info',
          'message' => 'Found exactly 3 triples. Verifying they are default repository metadata...',
        ]);
        
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
              if ($predicate === 'http://hadatac.org/ont/vstoi#hasVersion' && 
                  !empty($object)) {
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
          $result = $api->sparqlQuery($sparql);
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
        ''
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
      ['label' => 'foaf', 'comment' => 'Friend of a Friend', 'url' => 'http://xmlns.com/foaf/spec/index.rdf'],
    ];

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
          'message' => 'Failed to load ontologies: ' . ($loadObj->body ?? 'Unknown error'),
        ]);
        return;
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
      $api->repoResetNamespaces();
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
          $failedCount++;
        }
        
        // Small delay to make the UI updates visible
        usleep(100000); // 0.1 second
      }
      
      if ($failedCount > 0) {
        $this->sendProgress([
          'type' => 'step',
          'step' => 'ontologies-warning',
          'status' => 'warning',
          'message' => "Loaded $successCount ontologies, $failedCount had issues",
        ]);
        
        // Bootstrap failed - not all ontologies loaded
        $this->sendProgress([
          'type' => 'complete',
          'status' => 'error',
          'message' => 'Bootstrap failed!',
          'details' => "Only $successCount of " . count($generalPurposeOntologies) . " general purpose ontologies loaded successfully. $failedCount ontologies had issues. Please check hascoapi logs and retry.",
        ]);
        return;
      } else {
        $this->sendProgress([
          'type' => 'step',
          'step' => 'ontologies-success',
          'status' => 'success',
          'message' => "All $successCount general purpose ontologies loaded successfully",
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
    echo json_encode($data) . "\n";
    ob_flush();
    flush();
  }

}
