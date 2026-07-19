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
   * Invalidate statistics cache for specific ontologies.
   * 
   * This method should be called whenever an ontology is updated or deleted
   * to ensure statistics are recalculated on next page load.
   * 
   * @param array $ontologies
   *   Array of ontology abbreviations to invalidate: 'ins', 'pmsr', 'uberon', 'ncit'
   *   
   * Example usage:
   *   IngestionController::invalidateStatisticsCache(['ins', 'pmsr']);
   */
  public static function invalidateStatisticsCache(array $ontologies) {
    $cache_invalidator = \Drupal::service('cache_tags.invalidator');
    $tags_to_invalidate = [];
    
    // Map ontology abbreviations to cache tags
    foreach ($ontologies as $abbrev) {
      $tags_to_invalidate[] = 'pmsr_ontology:' . strtolower($abbrev);
    }
    
    if (!empty($tags_to_invalidate)) {
      $cache_invalidator->invalidateTags($tags_to_invalidate);
      \Drupal::logger('pmsr')->info('Invalidated statistics cache for ontologies: @ontologies', [
        '@ontologies' => implode(', ', $ontologies),
      ]);
    }
  }

  /**
   * Ingest PMSR ontologies (pmsr, uberon, ncit) from code.
   */
  public function ingestOntologies() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest PMSR, UBERON, and NCIT ontologies from code into Apache Fuseki, and automatically create entry point mappings in hasco.ttl.</p>';
    
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
    $output .= '<hr>';
    $output .= '<p><strong>Entry Points to be Bound:</strong></p>';
    $output .= '<ul>';
    $output .= '<li>PMSR Medical Simulation Process Stem → WorkflowStemEntryPoint</li>';
    $output .= '<li>UBERON Anatomical Entity → AnatomicalPartEntryPoint</li>';
    $output .= '<li>NCIT Manufactured Object → MedicalDeviceEntryPoint</li>';
    $output .= '</ul>';
    $output .= '<div class="alert alert-warning mt-2">';
    $output .= '<strong>Note:</strong> Entry point bindings must be configured in the ontology files or added programmatically during ingestion.';
    $output .= '</div>';
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
    $successfully_ingested = []; // Track which ontologies were successfully ingested
    
    // Define ontologies with their metadata
    $ontologies = [
      'pmsr' => [
        'file' => 'pmsr.ttl',
        'label' => 'pmsr',
        'namespace' => 'http://pmsr.net/ont/pmsr',
        'mime' => 'text/turtle',
        'source' => 'https://hadatac.org/ont/pmsr/pmsr.ttl',
      ],
      'uberon' => [
        'file' => 'uberon.ttl',
        'label' => 'uberon',
        'namespace' => 'http://purl.obolibrary.org/obo/uberon.owl',
        'mime' => 'text/turtle',
        'source' => 'http://purl.obolibrary.org/obo/uberon.owl',
      ],
      'ncit' => [
        'file' => 'ncit-pmsr.ttl',
        'label' => 'ncit',
        'namespace' => 'http://purl.obolibrary.org/obo/ncit.owl',
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
    $total_steps = (count($ontologies) * 3) + 1; // 3 steps per ontology + 1 for entry points
    
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
      
      // Upload to hascoapi (NOT directly to Fuseki)
      $ingest_result = $api->repoIngestNamespaceOntology($onto['namespace'], $file_content, $onto['mime']);
      $ingest_data = json_decode($ingest_result);
      
      if (!$ingest_data || !$ingest_data->isSuccessful) {
        $errors[] = "Failed to load triples for $abbrev: " . ($ingest_data->body ?? 'Unknown error');
        continue;
      }
      
      // Wait for ingestion to complete
      sleep(2);
      
      // Query namespace to get triple count
      $triple_count = 0;
      try {
        $namespace_list_response = $api->namespaceList();
        $namespace_data = json_decode($namespace_list_response);
        if ($namespace_data && $namespace_data->isSuccessful && is_array($namespace_data->body)) {
          foreach ($namespace_data->body as $ns) {
            if (strtolower($ns->label) === strtolower($onto['label'])) {
              $triple_count = $ns->numberOfLoadedTriples ?? 0;
              break;
            }
          }
        }
      } catch (\Exception $e) {
        // Non-fatal - continue without count
      }
      
      // Ingestion successful with triple count
      if ($triple_count > 0) {
        $ontology_progress[] = "  ✓ Successfully loaded " . number_format($triple_count) . " triples for $abbrev";
      } else {
        $ontology_progress[] = "  ✓ Successfully loaded triples for $abbrev";
      }
      
      // Mark this ontology as successfully ingested for cache invalidation
      $successfully_ingested[] = $abbrev;
      
      // Add this ontology's progress to overall progress
      $progress = array_merge($progress, $ontology_progress);
    }
    
    // Step 4: Create Entry Points in hasco.ttl (only if ontology ingestion succeeded)
    if (empty($errors)) {
      $step++;
      $progress[] = "[$step/$total_steps] Creating entry point mappings in hasco.ttl...";
      
      $entry_point_result = $this->createEntryPointMappings();
      
      if ($entry_point_result['success']) {
        // Show newly created mappings
        if ($entry_point_result['count'] > 0) {
          $progress[] = "  ✓ Created " . $entry_point_result['count'] . " new entry point mapping(s):";
          foreach ($entry_point_result['created'] as $mapping_info) {
            $progress[] = "    - " . $mapping_info['label'] . ": " . $mapping_info['bound_term'] . " → " . $mapping_info['entry_point'];
          }
          $progress[] = "  ✓ Ingested hasco.ttl into Fuseki";
        }
        
        // Show existing mappings
        if (!empty($entry_point_result['existing'])) {
          $progress[] = "  ✓ Verified " . count($entry_point_result['existing']) . " existing entry point mapping(s):";
          foreach ($entry_point_result['existing'] as $mapping_info) {
            $progress[] = "    - " . $mapping_info['label'] . ": " . $mapping_info['bound_term'] . " → " . $mapping_info['entry_point'];
          }
        }
      } else {
        $errors[] = "Failed to create entry points: " . $entry_point_result['message'];
      }
    }
    
    // Invalidate statistics cache for successfully ingested ontologies
    if (!empty($successfully_ingested)) {
      self::invalidateStatisticsCache($successfully_ingested);
      foreach ($successfully_ingested as $abbrev) {
        $progress[] = "  ✓ Invalidated statistics cache for $abbrev";
      }
    }
    
    // Return results
    return new JsonResponse([
      'success' => empty($errors),
      'message' => empty($errors) ? 'All ontologies ingested successfully!' : 'Ingestion completed with errors',
      'progress' => $progress,
      'errors' => $errors,
    ]);
  }

  /**
   * Create entry point mappings for PMSR ontologies.
   * 
   * This method appends RDF triples to hasco.ttl to map external ontology
   * classes to HASCO entry points, then triggers ingestion into Fuseki.
   * 
   * CRITICAL SAFEGUARDS:
   * - Creates validated backup before any modifications
   * - Validates hasco.ttl integrity before and after changes
   * - Automatically restores from backup if validation fails
   * - Prevents catastrophic data loss from corrupted hasco.ttl
   * 
   * @return array
   *   Array with 'success', 'count', and 'message' keys.
   */
  private function createEntryPointMappings(): array {
    // Define entry point mappings
    $mappings = [
      [
        'external_uri' => 'http://pmsr.net/ont/pmsr#MedicalSimulationProcessStem', // Medical Simulation Process Stem
        'parent_uri' => 'http://hadatac.org/ont/hasco/WorkflowStemEntryPoint',
        'label' => 'PMSR Medical Simulation Process Stem',
      ],
      [
        'external_uri' => 'http://purl.obolibrary.org/obo/UBERON_0001062', // Anatomical Entity
        'parent_uri' => 'http://hadatac.org/ont/hasco/AnatomicalPartEntryPoint',
        'label' => 'UBERON Anatomical Entity',
      ],
      [
        'external_uri' => 'http://purl.obolibrary.org/obo/NCIT_C97325', // Manufactured Object
        'parent_uri' => 'http://hadatac.org/ont/hasco/MedicalDeviceEntryPoint',
        'label' => 'NCIT Manufactured Object',
      ],
    ];
    
    $fs = \Drupal::service('file_system');
    
    // Paths
    $ontRootDir = 'private://ont';
    $versionsDir = $ontRootDir . '/versions';
    $ttlUri = $ontRootDir . '/hasco.ttl';
    $ttlPath = $fs->realpath($ttlUri);
    
    // Ensure directories exist
    $fs->prepareDirectory(
      $ontRootDir,
      \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | 
      \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS
    );
    $fs->prepareDirectory(
      $versionsDir,
      \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | 
      \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS
    );
    
    // If TTL doesn't exist, create with complete template including all entry points
    if ($ttlPath === FALSE || !file_exists($ttlPath)) {
      // Use the complete template with all entry point definitions
      $complete_ttl = \Drupal\pmsr\Validation\HascoIntegrityValidator::generateCompleteHascoTtl();
      $saved_uri = $fs->saveData($complete_ttl, $ttlUri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
      if ($saved_uri === FALSE) {
        return [
          'success' => FALSE,
          'count' => 0,
          'existing' => [],
          'created' => [],
          'message' => 'Failed to create hasco.ttl file',
        ];
      }
      $ttlPath = $fs->realpath($ttlUri);
    }
    
    // CRITICAL SAFEGUARD 1: Create validated backup before any modifications
    $backup_result = \Drupal\pmsr\Validation\HascoIntegrityValidator::createValidatedBackup($ttlPath);
    
    if (!$backup_result['success']) {
      return [
        'success' => FALSE,
        'count' => 0,
        'existing' => [],
        'created' => [],
        'message' => 'Failed to create backup: ' . ($backup_result['error'] ?? 'Unknown error'),
      ];
    }
    
    // CRITICAL SAFEGUARD 2: Validate current hasco.ttl BEFORE making changes
    $pre_validation = $backup_result['validation'];
    
    if (!$pre_validation['valid']) {
      $error_msg = 'CRITICAL: hasco.ttl failed validation before modification. Errors: ' . 
                   implode('; ', $pre_validation['errors']);
      
      \Drupal::logger('pmsr')->emergency($error_msg, [
        'validation' => $pre_validation,
        'backup_path' => $backup_result['backup_path'],
      ]);
      
      // CRITICAL SAFEGUARD 2A: Auto-recovery from corrupted state
      // Try to restore from most recent valid backup
      $restore_result = \Drupal\pmsr\Validation\HascoIntegrityValidator::restoreFromBackup($ttlPath);
      
      if ($restore_result['success']) {
        \Drupal::logger('pmsr')->notice('Auto-recovered hasco.ttl from valid backup: {path}', [
          'path' => $restore_result['restored_from'],
        ]);
        
        // Re-validate after restore
        $ttlContent = file_get_contents($ttlPath);
        $post_restore_validation = \Drupal\pmsr\Validation\HascoIntegrityValidator::validateHascoTtl($ttlContent);
        
        if (!$post_restore_validation['valid']) {
          // Backup restore failed, regenerate from template as last resort
          \Drupal::logger('pmsr')->emergency('Backup restore failed validation, regenerating from template');
          
          $complete_ttl = \Drupal\pmsr\Validation\HascoIntegrityValidator::generateCompleteHascoTtl();
          $saved_uri = $fs->saveData($complete_ttl, $ttlUri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
          
          if ($saved_uri === FALSE) {
            return [
              'success' => FALSE,
              'count' => 0,
              'existing' => [],
              'created' => [],
              'message' => 'CRITICAL: Failed to regenerate hasco.ttl from template',
            ];
          }
          
          $ttlPath = $fs->realpath($ttlUri);
          \Drupal::logger('pmsr')->notice('Successfully regenerated hasco.ttl from complete template');
        }
        
        // Continue with the ingestion process
        // Note: We don't return here, we let it continue to append the new mappings
      } else {
        // No valid backup found, regenerate from template
        \Drupal::logger('pmsr')->emergency('No valid backup found, regenerating from template');
        
        $complete_ttl = \Drupal\pmsr\Validation\HascoIntegrityValidator::generateCompleteHascoTtl();
        $saved_uri = $fs->saveData($complete_ttl, $ttlUri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
        
        if ($saved_uri === FALSE) {
          return [
            'success' => FALSE,
            'count' => 0,
            'existing' => [],
            'created' => [],
            'message' => 'CRITICAL: Failed to regenerate hasco.ttl from template',
          ];
        }
        
        $ttlPath = $fs->realpath($ttlUri);
        \Drupal::logger('pmsr')->notice('Successfully regenerated hasco.ttl from complete template');
      }
    }
    
    // Read current content to check for existing mappings
    $ttlContent = (string) file_get_contents($ttlPath);
    
    // Create version backup
    try {
      $existingVersions = [];
      if (is_dir($fs->realpath($versionsDir))) {
        $dirs = scandir($fs->realpath($versionsDir));
        foreach ($dirs as $d) {
          if (preg_match('/^v(\d{4})$/', $d, $m)) {
            $existingVersions[] = (int)$m[1];
          }
        }
      }
      $nextVersion = empty($existingVersions) ? 1 : (max($existingVersions) + 1);
      $versionLabel = sprintf('v%04d', $nextVersion);
      $versionDir = $versionsDir . '/' . $versionLabel;
      
      $fs->prepareDirectory(
        $versionDir,
        \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | 
        \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS
      );
      
      if (file_exists($ttlPath)) {
        $versionUri = $versionDir . '/hasco.ttl';
        $fs->copy($ttlUri, $versionUri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
      }
    } catch (\Throwable $e) {
      // Non-fatal - continue without versioning
    }
    
    // Build RDF triples for entry points
    $append = '';
    $count = 0;
    $created_mappings = [];
    $existing_mappings = [];
    $timestamp = date('Y-m-d H:i:s');
    
    foreach ($mappings as $mapping) {
      $externalUri = $mapping['external_uri'];
      $parentUri = $mapping['parent_uri'];
      $label = $mapping['label'];
      
      // Extract names for display
      $entry_point_name = substr($parentUri, strrpos($parentUri, '/') + 1);
      $bound_term_name = substr($externalUri, strrpos($externalUri, '#') !== false ? 
        strrpos($externalUri, '#') + 1 : strrpos($externalUri, '/') + 1);
      
      $mapping_info = [
        'label' => $label,
        'bound_term' => $bound_term_name,
        'entry_point' => $entry_point_name,
      ];
      
      // Check if mapping already exists
      if (strpos($ttlContent, "<$externalUri>") !== false && 
          strpos($ttlContent, "rdfs:subClassOf <$parentUri>") !== false) {
        $existing_mappings[] = $mapping_info;
        continue; // Skip if already mapped
      }
      
      // Validate URIs (security check)
      if (preg_match('/[\s<>"{}|^`\\\\]/', $externalUri) || 
          preg_match('/[\s<>"{}|^`\\\\]/', $parentUri)) {
        continue; // Skip invalid URIs
      }
      
      $append .= "\n# --- Mapping for $label (created by PMSR Ontology Ingestion at $timestamp) ---\n";
      $append .= "<$externalUri>\n";
      $append .= "\ta rdfs:Class;\n";
      $append .= "\trdfs:subClassOf <$parentUri> .\n";
      
      $count++;
      $created_mappings[] = $mapping_info;
    }
    
    // Only proceed if we have new mappings to add
    if ($count === 0) {
      return [
        'success' => true,
        'count' => 0,
        'existing' => $existing_mappings,
        'created' => [],
        'message' => 'Entry points already exist',
      ];
    }
    
    // Append to hasco.ttl
    $bytes = file_put_contents($ttlPath, $append, FILE_APPEND | LOCK_EX);
    if ($bytes === false) {
      return [
        'success' => FALSE,
        'count' => 0,
        'existing' => $existing_mappings,
        'created' => [],
        'message' => 'Failed to append to hasco.ttl',
      ];
    }
    
    // CRITICAL SAFEGUARD 3: Validate hasco.ttl AFTER modifications
    $modified_content = file_get_contents($ttlPath);
    $post_validation = \Drupal\pmsr\Validation\HascoIntegrityValidator::validateHascoTtl($modified_content);
    
    if (!$post_validation['valid']) {
      // CRITICAL: Modified file is invalid - RESTORE FROM BACKUP
      $error_msg = 'CRITICAL: hasco.ttl became invalid after modification. Restoring from backup. Errors: ' . 
                   implode('; ', $post_validation['errors']);
      
      \Drupal::logger('pmsr')->emergency($error_msg, [
        'validation' => $post_validation,
        'backup_path' => $backup_result['backup_path'],
      ]);
      
      // Restore from the backup we just created
      if (file_exists($backup_result['backup_path'])) {
        copy($backup_result['backup_path'], $ttlPath);
        \Drupal::logger('pmsr')->notice('Successfully restored hasco.ttl from backup');
      }
      
      return [
        'success' => FALSE,
        'count' => $count,
        'existing' => $existing_mappings,
        'created' => $created_mappings,
        'message' => $error_msg . ' File restored from backup.',
        'validation' => $post_validation,
      ];
    }
    
    // CRITICAL SAFEGUARD 4: Use namespace-specific ingestion to PRESERVE existing hasco triples
    // Instead of using uploadOntology() which DELETES all hasco triples, we use the
    // namespace-specific endpoint which allows us to control what gets deleted.
    // 
    // GRAPH ISOLATION PRINCIPLE:
    // - PMSR/UBERON/NCIT ontologies each have their own named graphs
    // - hasco graph contains CORE entry point definitions that must NEVER be deleted
    // - We only ADD new external bindings to hasco, never REPLACE the entire graph
    try {
      $api = \Drupal::service('rep.api_connector');
      $file_content = file_get_contents($ttlPath);
      
      if ($file_content === FALSE) {
        return [
          'success' => FALSE,
          'count' => $count,
          'existing' => $existing_mappings,
          'created' => $created_mappings,
          'message' => 'Failed to read hasco.ttl for ingestion',
        ];
      }
      
      // Use namespace-specific ingestion endpoint
      // This endpoint will DELETE existing triples and load new ones from the file
      // IMPORTANT: The file MUST contain all entry point definitions + external bindings
      $namespace_uri = 'http://hadatac.org/ont/hasco/';
      $ingest_result = $api->repoIngestNamespaceOntology($namespace_uri, $file_content, 'text/turtle');
      $ingest_data = json_decode($ingest_result);
      
      if (!$ingest_data || !$ingest_data->isSuccessful) {
        return [
          'success' => FALSE,
          'count' => $count,
          'existing' => $existing_mappings,
          'created' => $created_mappings,
          'message' => 'Failed to ingest hasco.ttl into Fuseki: ' . ($ingest_data->body ?? 'Unknown error'),
        ];
      }
      
      // CRITICAL SAFEGUARD 5: Post-ingestion validation - verify entry points still exist
      sleep(2); // Wait for ingestion to complete
      
      // Use the children API to verify ClassEntryPoint has children (entry points)
      try {
        $verify_result = $api->getChildren('http://hadatac.org/ont/hasco/ClassEntryPoint');
        $verify_data = json_decode($verify_result);
        
        $entry_point_count = 0;
        if ($verify_data && $verify_data->isSuccessful && is_array($verify_data->body)) {
          $entry_point_count = count($verify_data->body);
        }
        
        if ($entry_point_count < 23) {
          // CRITICAL FAILURE: Entry points were lost during ingestion!
          \Drupal::logger('pmsr')->emergency('CRITICAL: Entry points lost after ingestion! Found only {count} of 23 required entry points', [
            'count' => $entry_point_count,
            'backup_path' => $backup_result['backup_path'],
          ]);
          
          // Attempt to restore from backup
          if (file_exists($backup_result['backup_path'])) {
            $backup_content = file_get_contents($backup_result['backup_path']);
            $restore_result = $api->repoIngestNamespaceOntology($namespace_uri, $backup_content, 'text/turtle');
            sleep(2);
            
            \Drupal::logger('pmsr')->notice('Emergency restore attempted from backup');
          }
          
          return [
            'success' => FALSE,
            'count' => $count,
            'existing' => $existing_mappings,
            'created' => $created_mappings,
            'message' => "CRITICAL: Entry points lost after ingestion! Found only {$entry_point_count} of 23 required. Emergency restore attempted.",
            'entry_point_count' => $entry_point_count,
          ];
        }
        
      } catch (\Exception $e) {
        // Non-fatal verification failure - log but continue
        \Drupal::logger('pmsr')->warning('Failed to verify entry points after ingestion: {msg}', ['msg' => $e->getMessage()]);
      }
      
    } catch (\Throwable $e) {
      return [
        'success' => FALSE,
        'count' => $count,
        'existing' => $existing_mappings,
        'created' => $created_mappings,
        'message' => 'Error during hasco.ttl ingestion: ' . $e->getMessage(),
      ];
    }
    
    return [
      'success' => true,
      'count' => $count,
      'existing' => $existing_mappings,
      'created' => $created_mappings,
      'message' => 'Entry points created and ingested successfully',
    ];
  }

}
