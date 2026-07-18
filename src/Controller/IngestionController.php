<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for PMSR data ingestion operations.
 */
class IngestionController extends ControllerBase {

  /**
   * Ingest PMSR ontologies (pmsr, uberon, ncit) from code.
   */
  public function ingestOntologies() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest PMSR, UBERON, and NCIT ontologies from code into Apache Fuseki.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>Ontologies to be ingested:</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<ul>';
    $output .= '<li><strong>PMSR Ontology</strong> - Medical Simulation Process ontology</li>';
    $output .= '<li><strong>UBERON Ontology</strong> - Anatomical structures and entities</li>';
    $output .= '<li><strong>NCIT Ontology</strong> - NCI Thesaurus subset for medical devices</li>';
    $output .= '</ul>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">Cancel</button>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    return [
      '#markup' => Markup::create($output),
      '#attached' => [
        'library' => [
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/hascoapi/api/pmsr/ingest/ontologies',
              'message' => 'Ingesting ontologies...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Ingest INS instruments.
   */
  public function ingestInstruments() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest instrument definitions from INS templates.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>Instrument Ingestion</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<p>This will process all INS (Instrument Specification) templates and create instrument instances in the system.</p>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">Cancel</button>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    return [
      '#markup' => Markup::create($output),
      '#attached' => [
        'library' => [
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/hascoapi/api/pmsr/ingest/instruments',
              'message' => 'Ingesting instruments...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Ingest KRG geography and organizations.
   */
  public function ingestGeography() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest geography data and organizational structures from KRG templates.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>KRG Geography & Organizations</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<p>This will process KRG (Knowledge Representation for Geography) templates including:</p>';
    $output .= '<ul>';
    $output .= '<li>Geographic locations and regions</li>';
    $output .= '<li>Organizational hierarchies</li>';
    $output .= '<li>Institutional affiliations</li>';
    $output .= '</ul>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">Cancel</button>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    return [
      '#markup' => Markup::create($output),
      '#attached' => [
        'library' => [
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/hascoapi/api/pmsr/ingest/geography',
              'message' => 'Ingesting geography data...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Ingest KGR people.
   */
  public function ingestPeople() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest people data from KGR templates.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>KGR People Data</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<p>This will process KGR (Knowledge Representation for Resources) templates including:</p>';
    $output .= '<ul>';
    $output .= '<li>Person profiles</li>';
    $output .= '<li>Roles and affiliations</li>';
    $output .= '<li>Contact information</li>';
    $output .= '</ul>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">Cancel</button>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    return [
      '#markup' => Markup::create($output),
      '#attached' => [
        'library' => [
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/hascoapi/api/pmsr/ingest/people',
              'message' => 'Ingesting people data...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Process PMSR ontology ingestion (AJAX endpoint).
   */
  public function processOntologyIngestion(Request $request) {
    $progress = [];
    $errors = [];
    
    // Define ontologies with their metadata
    $ontologies = [
      'pmsr' => [
        'file' => 'pmsr.ttl',
        'label' => 'pmsr',
        'namespace' => 'https://pmsr.net/ont/pmsr#',
        'mime' => 'text/turtle',
        'source' => 'https://hadatac.org/ont/pmsr/pmsr.ttl',
      ],
      'uberon' => [
        'file' => 'uberon.ttl',
        'label' => 'uberon',
        'namespace' => 'http://purl.obolibrary.org/obo/uberon.owl#',
        'mime' => 'text/turtle',
        'source' => 'http://purl.obolibrary.org/obo/uberon.owl',
      ],
      'ncit' => [
        'file' => 'ncit-pmsr.ttl',
        'label' => 'ncit',
        'namespace' => 'http://purl.obolibrary.org/obo/ncit.owl#',
        'mime' => 'text/turtle',
        'source' => 'https://hadatac.org/ont/ncit/ncit-pmsr.ttl',
      ],
    ];
    
    // Step 1: Check if all TTL files exist
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
    $ontologies_dir = DRUPAL_ROOT . '/' . $module_path . '/ontologies/';
    
    $missing_files = [];
    foreach ($ontologies as $abbrev => $onto) {
      $file_path = $ontologies_dir . $onto['file'];
      if (!file_exists($file_path)) {
        $missing_files[] = $onto['file'];
      }
    }
    
    if (!empty($missing_files)) {
      return new JsonResponse([
        'success' => false,
        'message' => 'Missing ontology files: ' . implode(', ', $missing_files),
        'errors' => ['Please place the required TTL files in: ' . $ontologies_dir],
      ]);
    }
    
    // Get API connector service
    $api = \Drupal::service('rep.api_connector');
    
    // Get current namespace list
    $namespace_list_response = $api->namespaceList();
    $namespace_data = json_decode($namespace_list_response);
    $existing_namespaces = [];
    
    if ($namespace_data && $namespace_data->isSuccessful && is_array($namespace_data->body)) {
      foreach ($namespace_data->body as $ns) {
        $existing_namespaces[strtolower($ns->label)] = $ns;
      }
    }
    
    // Process each ontology
    $step = 0;
    $total_steps = count($ontologies) * 3; // 3 steps per ontology
    
    foreach ($ontologies as $abbrev => $onto) {
      $ontology_progress = [];
      
      // Step (a): Verify/Add namespace
      $step++;
      $ontology_progress[] = "[$step/$total_steps] Checking if $abbrev ontology is registered...";
      
      if (!isset($existing_namespaces[strtolower($onto['label'])])) {
        // Create namespace
        $json_payload = json_encode([
          'label' => $onto['label'],
          'uri' => $onto['namespace'],
          'source' => $onto['source'],
          'sourceMime' => $onto['mime'],
        ]);
        
        $create_response = $api->repoCreateNamespace($json_payload);
        $create_data = json_decode($create_response);
        
        if (!$create_data || !$create_data->isSuccessful) {
          $errors[] = "Failed to create namespace for $abbrev";
          continue;
        }
        
        $ontology_progress[] = "  ✓ Created namespace for $abbrev";
      } else {
        $ontology_progress[] = "  ✓ Namespace for $abbrev already exists";
      }
      
      // Step (b): Check and clear existing triples
      $step++;
      $ontology_progress[] = "[$step/$total_steps] Checking for existing triples in $abbrev...";
      
      // Refresh namespace list to get triple counts
      $namespace_list_response = $api->namespaceList();
      $namespace_data = json_decode($namespace_list_response);
      $has_triples = false;
      
      if ($namespace_data && $namespace_data->isSuccessful && is_array($namespace_data->body)) {
        foreach ($namespace_data->body as $ns) {
          if (strtolower($ns->label) === strtolower($onto['label'])) {
            if (isset($ns->numberOfLoadedTriples) && $ns->numberOfLoadedTriples > 0) {
              $has_triples = true;
              break;
            }
          }
        }
      }
      
      if ($has_triples) {
        // Delete existing triples
        $delete_response = $api->repoDeleteSelectedNamespaceTriples([$onto['namespace']]);
        $delete_data = json_decode($delete_response);
        
        if (!$delete_data || !$delete_data->isSuccessful) {
          $errors[] = "Failed to clear triples for $abbrev";
          continue;
        }
        
        $ontology_progress[] = "  ✓ Cleared existing triples from $abbrev";
      } else {
        $ontology_progress[] = "  ✓ No existing triples found in $abbrev";
      }
      
      // Step (c): Load triples from local file
      $step++;
      $ontology_progress[] = "[$step/$total_steps] Loading triples from local file for $abbrev...";
      
      $file_path = $ontologies_dir . $onto['file'];
      $file_content = file_get_contents($file_path);
      
      if ($file_content === false) {
        $errors[] = "Failed to read file: " . $onto['file'];
        continue;
      }
      
      // Upload to Fuseki using Graph Store Protocol
      $put_result = $api->fusekiPutGraph($onto['namespace'], $file_content, $onto['mime']);
      
      if (empty($put_result['ok'])) {
        $errors[] = "Failed to load triples for $abbrev: " . ($put_result['message'] ?? 'Unknown error');
        continue;
      }
      
      $ontology_progress[] = "  ✓ Successfully loaded triples for $abbrev";
      
      // Add this ontology's progress to overall progress
      $progress = array_merge($progress, $ontology_progress);
    }
    
    // Return results
    return new JsonResponse([
      'success' => empty($errors),
      'message' => empty($errors) ? 'All ontologies ingested successfully!' : 'Ingestion completed with errors',
      'progress' => $progress,
      'errors' => $errors,
    ]);
  }

}
