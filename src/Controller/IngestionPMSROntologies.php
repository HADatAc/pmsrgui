<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller dedicated to PMSR ontology ingestion.
 */
class IngestionPMSROntologies extends ControllerBase {

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
    $output .= '<li>PMSR Medical Simulation Process Stem -> WorkflowStemEntryPoint</li>';
    $output .= '<li>UBERON Anatomical Entity -> AnatomicalPartEntryPoint</li>';
    $output .= '<li>NCIT Manufactured Object -> MedicalDeviceEntryPoint</li>';
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

    $output .= '</div>';

    return [
      '#markup' => Markup::create($output),
      '#attached' => [
        'library' => [
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/pmsr/api/ingest/ontologies/process',
              'message' => 'Ingesting ontologies...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Validate and check namespace before ingestion.
   */
  private function validateNamespace($api, $abbrev, $expectedUri, $expectedMime) {
    $result = [
      'exists' => false,
      'errors' => [],
      'needs_mime_update' => false,
      'existing_ns' => null,
    ];

    $namespace_list_response = $api->namespaceList();
    $namespace_data = json_decode($namespace_list_response);

    if (!$namespace_data || !$namespace_data->isSuccessful || !is_array($namespace_data->body)) {
      $result['errors'][] = 'Failed to retrieve namespace list from hascoapi';
      return $result;
    }

    foreach ($namespace_data->body as $ns) {
      if ($ns->label === $abbrev) {
        $result['exists'] = true;
        $result['existing_ns'] = $ns;

        if ($ns->uri !== $expectedUri) {
          $result['errors'][] = "CRITICAL ERROR: Namespace '$abbrev' exists but URI MISMATCH";
          $result['errors'][] = "  Expected URI: $expectedUri";
          $result['errors'][] = "  Existing URI: {$ns->uri}";
          $result['errors'][] = "  ACTION: Delete namespace '$abbrev' or correct the expected URI";
          $result['errors'][] = '  INGESTION STOPPED - User must resolve conflict';
          return $result;
        }

        $existingMime = $ns->sourceMime ?? '';
        if (empty($existingMime) && !empty($expectedMime)) {
          $result['needs_mime_update'] = true;
        } elseif (!empty($existingMime) && !empty($expectedMime) && $existingMime !== $expectedMime) {
          $result['errors'][] = "CRITICAL ERROR: Namespace '$abbrev' has CONFLICTING MIME type";
          $result['errors'][] = "  Expected MIME: $expectedMime";
          $result['errors'][] = "  Existing MIME: $existingMime";
          $result['errors'][] = '  ACTION: User must decide which MIME type is correct';
          $result['errors'][] = '  INGESTION STOPPED - User must resolve conflict';
          return $result;
        }

        break;
      }
    }

    return $result;
  }

  /**
   * Detect unwanted namespaces created after ingestion.
   */
  private function detectUnwantedNamespaces($api, $abbrev, $expectedUri) {
    $errors = [];

    $namespace_list_response = $api->namespaceList();
    $namespace_data = json_decode($namespace_list_response);

    if (!$namespace_data || !$namespace_data->isSuccessful || !is_array($namespace_data->body)) {
      return $errors;
    }

    foreach ($namespace_data->body as $ns) {
      $nsLabel = $ns->label ?? '';
      $nsUri = $ns->uri ?? '';

      if ($abbrev === 'pmsr' && strpos($nsUri, 'pmsr.net') !== false) {
        if ($nsLabel !== $abbrev || $nsUri !== $expectedUri) {
          $errors[] = "CRITICAL ERROR: Unexpected namespace created: '$nsLabel' -> $nsUri";
          $errors[] = "  Expected only: '$abbrev' -> $expectedUri";
          $errors[] = '  CAUSE: hascoapi auto-created namespace from TTL metadata (rdfs:label or @prefix)';
          $errors[] = '  ROOT CAUSE: hascoapi should NOT create namespaces from TTL content';
          $errors[] = '  ACTION: Fix hascoapi to respect only explicitly created namespaces';
        }
      }

      if (($abbrev === 'uberon' || $abbrev === 'ncit') && $nsUri === $expectedUri) {
        if ($nsLabel !== $abbrev) {
          $errors[] = "CRITICAL ERROR: Namespace has WRONG abbreviation: '$nsLabel' (should be '$abbrev')";
          $errors[] = "  URI: $nsUri (correct)";
          $errors[] = '  CAUSE: hascoapi modified the abbreviation we provided';
          $errors[] = '  ROOT CAUSE: hascoapi should use exact abbreviation, not modify it';
          $errors[] = '  ACTION: Fix hascoapi to preserve exact abbreviation from namespace creation';
        }
      }
    }

    return $errors;
  }

  /**
   * Process PMSR ontology ingestion (AJAX endpoint).
   */
  public function processOntologyIngestion(Request $request) {
    $progress = [];
    $errors = [];
    $successfully_ingested = [];

    $ontologies = [
      'pmsr' => [
        'file' => 'pmsr.ttl',
        'label' => 'pmsr',
        'namespace' => 'https://pmsr.net/ont/',
        'mime' => 'text/turtle',
        'source' => 'https://hadatac.org/ont/pmsr/pmsr.ttl',
      ],
      'uberon' => [
        'file' => 'uberon.ttl',
        'label' => 'uberon',
        'namespace' => 'http://purl.obolibrary.org/obo/UBERON_',
        'mime' => 'text/turtle',
        'source' => '',
      ],
      'ncit' => [
        'file' => 'ncit-pmsr.ttl',
        'label' => 'ncit',
        'namespace' => 'http://purl.obolibrary.org/obo/NCIT_',
        'mime' => 'text/turtle',
        'source' => '',
      ],
    ];

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

    $api = \Drupal::service('rep.api_connector');

    $step = 0;
    $total_steps = (count($ontologies) * 4) + 1;

    foreach ($ontologies as $abbrev => $onto) {
      $ontology_progress = [];

      $step++;
      $ontology_progress[] = "[$step/$total_steps] Validating namespace for $abbrev...";

      $validation = $this->validateNamespace($api, $onto['label'], $onto['namespace'], $onto['mime']);

      if (!empty($validation['errors'])) {
        if ($validation['exists'] && isset($validation['existing_ns'])) {
          $existingUri = $validation['existing_ns']->uri ?? '';
          if ($existingUri !== $onto['namespace']) {
            $ontology_progress[] = "  ⚠ Namespace '$onto[label]' exists with wrong URI";
            $ontology_progress[] = "  Expected URI: $onto[namespace]";
            $ontology_progress[] = "  Existing URI: $existingUri";
            $ontology_progress[] = '  ⚙ Deleting old namespace...';

            $delete_response = $api->repoDeleteSelectedNamespace($onto['label'], 'pmsr-ingest-ontologies');
            $delete_data = json_decode($delete_response);

            if (!$delete_data || !$delete_data->isSuccessful) {
              $errors[] = "Failed to delete conflicting namespace '$abbrev': " . ($delete_data->body ?? 'Unknown error');
              $progress = array_merge($progress, $ontology_progress);
              continue;
            }

            $ontology_progress[] = '  ✓ Old namespace deleted';

            $api->repoResetNamespaces('pmsr-ingest-ontologies');
            sleep(1);

            $validation['errors'] = [];
            $validation['exists'] = false;
          }
        }

        if (!empty($validation['errors'])) {
          foreach ($validation['errors'] as $error) {
            $errors[] = $error;
          }
          $progress = array_merge($progress, $ontology_progress);
          continue;
        }
      }

      if (!$validation['exists']) {
        $json_payload = json_encode([
          'label' => $onto['label'],
          'uri' => $onto['namespace'],
          'source' => $onto['source'],
          'sourceMime' => $onto['mime'],
        ]);

        $create_response = $api->repoCreateNamespace($json_payload, 'pmsr-ingest-ontologies');
        $create_data = json_decode($create_response);

        if (!$create_data || !$create_data->isSuccessful) {
          $errors[] = "Failed to create namespace for $abbrev";
          $progress = array_merge($progress, $ontology_progress);
          continue;
        }

        $ontology_progress[] = "  ✓ Created namespace '$onto[label]' with URI: $onto[namespace]";
      } else {
        $ontology_progress[] = "  ✓ Namespace '$onto[label]' already exists with correct URI";

        if ($validation['needs_mime_update']) {
          $ontology_progress[] = "  ℹ Adding MIME type: $onto[mime]";
        }
      }

      $step++;
      $ontology_progress[] = "[$step/$total_steps] Checking for existing triples in $abbrev...";

      $namespace_list_response = $api->namespaceList();
      $namespace_data = json_decode($namespace_list_response);
      $has_triples = false;

      if ($namespace_data && $namespace_data->isSuccessful && is_array($namespace_data->body)) {
        foreach ($namespace_data->body as $ns) {
          if ($ns->label === $onto['label']) {
            if (isset($ns->numberOfLoadedTriples) && $ns->numberOfLoadedTriples > 0) {
              $has_triples = true;
              break;
            }
          }
        }
      }

      if ($has_triples) {
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

      $step++;
      $ontology_progress[] = "[$step/$total_steps] Loading triples from local file for $abbrev...";

      $file_path = $ontologies_dir . $onto['file'];
      $file_content = file_get_contents($file_path);

      if ($file_content === false) {
        $errors[] = 'Failed to read file: ' . $onto['file'];
        continue;
      }

      $ingest_result = $api->repoIngestNamespaceOntology($onto['label'], $onto['namespace'], $file_content, $onto['mime'], 'pmsr-ingest-ontologies');
      $ingest_data = json_decode($ingest_result);

      if (!$ingest_data || !$ingest_data->isSuccessful) {
        $errors[] = "Failed to load triples for $abbrev: " . ($ingest_data->body ?? 'Unknown error');
        continue;
      }

      $max_wait_seconds = 90;
      $poll_interval_seconds = 2;
      $elapsed_seconds = 0;
      $ready_for_validation = false;

      while ($elapsed_seconds < $max_wait_seconds) {
        try {
          $ns_poll_response = $api->namespaceList();
          $ns_poll_data = json_decode($ns_poll_response);
          if ($ns_poll_data && $ns_poll_data->isSuccessful && is_array($ns_poll_data->body)) {
            foreach ($ns_poll_data->body as $ns) {
              if (($ns->label ?? '') === $onto['label'] && ($ns->numberOfLoadedTriples ?? 0) > 0) {
                $ready_for_validation = true;
                break 2;
              }
            }
          }
        } catch (\Exception $e) {
        }

        sleep($poll_interval_seconds);
        $elapsed_seconds += $poll_interval_seconds;
      }

      if (!$ready_for_validation) {
        $errors[] = "Timed out waiting for ontology ingestion to complete for $abbrev";
        continue;
      }

      $step++;
      $ontology_progress[] = "[$step/$total_steps] Validating namespace integrity after ingestion...";

      $unwanted_ns_errors = $this->detectUnwantedNamespaces($api, $abbrev, $onto['namespace']);
      if (!empty($unwanted_ns_errors)) {
        foreach ($unwanted_ns_errors as $error) {
          $errors[] = $error;
        }
        $ontology_progress[] = '  ✗ CRITICAL: hascoapi created unexpected namespaces';
        $ontology_progress[] = '  ✗ INGESTION STOPPED - Fix hascoapi behavior';
        $progress = array_merge($progress, $ontology_progress);
        continue;
      }

      $ontology_progress[] = '  ✓ No unexpected namespaces detected';

      $triple_count = 0;
      try {
        $namespace_list_response = $api->namespaceList();
        $namespace_data = json_decode($namespace_list_response);
        if ($namespace_data && $namespace_data->isSuccessful && is_array($namespace_data->body)) {
          foreach ($namespace_data->body as $ns) {
            if ($ns->label === $onto['label']) {
              $triple_count = $ns->numberOfLoadedTriples ?? 0;
              break;
            }
          }
        }
      } catch (\Exception $e) {
      }

      if ($triple_count > 0) {
        $ontology_progress[] = '  ✓ Successfully loaded ' . number_format($triple_count) . " triples for $abbrev";
      } else {
        $ontology_progress[] = "  ✓ Successfully loaded triples for $abbrev";
      }

      $successfully_ingested[] = $abbrev;
      $progress = array_merge($progress, $ontology_progress);
    }

    if (empty($errors)) {
      $step++;
      $progress[] = "[$step/$total_steps] Creating entry point mappings in hasco.ttl...";

      $entry_point_result = $this->createEntryPointMappings();

      if ($entry_point_result['success']) {
        if ($entry_point_result['count'] > 0) {
          $progress[] = '  ✓ Created ' . $entry_point_result['count'] . ' new entry point mapping(s):';
          foreach ($entry_point_result['created'] as $mapping_info) {
            $progress[] = '    - ' . $mapping_info['label'] . ': ' . $mapping_info['bound_term'] . ' -> ' . $mapping_info['entry_point'];
          }
          $progress[] = '  ✓ Ingested hasco.ttl into Fuseki';
        }

        if (!empty($entry_point_result['existing'])) {
          $progress[] = '  ✓ Verified ' . count($entry_point_result['existing']) . ' existing entry point mapping(s):';
          foreach ($entry_point_result['existing'] as $mapping_info) {
            $progress[] = '    - ' . $mapping_info['label'] . ': ' . $mapping_info['bound_term'] . ' -> ' . $mapping_info['entry_point'];
          }
        }
      } else {
        $errors[] = 'Failed to create entry points: ' . $entry_point_result['message'];
      }
    }

    if (!empty($successfully_ingested)) {
      IngestionController::invalidateStatisticsCache($successfully_ingested);
      foreach ($successfully_ingested as $abbrev) {
        $progress[] = "  ✓ Invalidated statistics cache for $abbrev";
      }
    }

    return new JsonResponse([
      'success' => empty($errors),
      'message' => empty($errors) ? 'All ontologies ingested successfully!' : 'Ingestion completed with errors',
      'progress' => $progress,
      'errors' => $errors,
    ]);
  }

  /**
   * Create entry point mappings for PMSR ontologies.
   */
  private function createEntryPointMappings(): array {
    $mappings = [
      [
        'external_uri' => 'https://pmsr.net/ont/MedicalSimulationProcessStem',
        'parent_uri' => 'http://hadatac.org/ont/hasco/WorkflowStemEntryPoint',
        'label' => 'PMSR Medical Simulation Process Stem',
      ],
      [
        'external_uri' => 'http://purl.obolibrary.org/obo/UBERON_0001062',
        'parent_uri' => 'http://hadatac.org/ont/hasco/AnatomicalPartEntryPoint',
        'label' => 'UBERON Anatomical Entity',
      ],
      [
        'external_uri' => 'http://purl.obolibrary.org/obo/NCIT_C97325',
        'parent_uri' => 'http://hadatac.org/ont/hasco/MedicalDeviceEntryPoint',
        'label' => 'NCIT Manufactured Object',
      ],
    ];

    $fs = \Drupal::service('file_system');

    $ontRootDir = 'private://ont';
    $versionsDir = $ontRootDir . '/versions';
    $ttlUri = $ontRootDir . '/hasco.ttl';
    $ttlPath = $fs->realpath($ttlUri);

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

    if ($ttlPath === false || !file_exists($ttlPath)) {
      $complete_ttl = \Drupal\pmsr\Validation\HascoIntegrityValidator::generateCompleteHascoTtl();
      $saved_uri = $fs->saveData($complete_ttl, $ttlUri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
      if ($saved_uri === false) {
        return [
          'success' => false,
          'count' => 0,
          'existing' => [],
          'created' => [],
          'message' => 'Failed to create hasco.ttl file',
        ];
      }
      $ttlPath = $fs->realpath($ttlUri);
    }

    $backup_result = \Drupal\pmsr\Validation\HascoIntegrityValidator::createValidatedBackup($ttlPath);

    if (!$backup_result['success']) {
      return [
        'success' => false,
        'count' => 0,
        'existing' => [],
        'created' => [],
        'message' => 'Failed to create backup: ' . ($backup_result['error'] ?? 'Unknown error'),
      ];
    }

    $pre_validation = $backup_result['validation'];

    if (!$pre_validation['valid']) {
      $error_msg = 'CRITICAL: hasco.ttl failed validation before modification. Errors: ' . implode('; ', $pre_validation['errors']);

      \Drupal::logger('pmsr')->emergency($error_msg, [
        'validation' => $pre_validation,
        'backup_path' => $backup_result['backup_path'],
      ]);

      $restore_result = \Drupal\pmsr\Validation\HascoIntegrityValidator::restoreFromBackup($ttlPath);

      if ($restore_result['success']) {
        \Drupal::logger('pmsr')->notice('Auto-recovered hasco.ttl from valid backup: {path}', [
          'path' => $restore_result['restored_from'],
        ]);

        $ttlContent = file_get_contents($ttlPath);
        $post_restore_validation = \Drupal\pmsr\Validation\HascoIntegrityValidator::validateHascoTtl($ttlContent);

        if (!$post_restore_validation['valid']) {
          \Drupal::logger('pmsr')->emergency('Backup restore failed validation, regenerating from template');

          $complete_ttl = \Drupal\pmsr\Validation\HascoIntegrityValidator::generateCompleteHascoTtl();
          $saved_uri = $fs->saveData($complete_ttl, $ttlUri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);

          if ($saved_uri === false) {
            return [
              'success' => false,
              'count' => 0,
              'existing' => [],
              'created' => [],
              'message' => 'CRITICAL: Failed to regenerate hasco.ttl from template',
            ];
          }

          $ttlPath = $fs->realpath($ttlUri);
          \Drupal::logger('pmsr')->notice('Successfully regenerated hasco.ttl from complete template');
        }
      } else {
        \Drupal::logger('pmsr')->emergency('No valid backup found, regenerating from template');

        $complete_ttl = \Drupal\pmsr\Validation\HascoIntegrityValidator::generateCompleteHascoTtl();
        $saved_uri = $fs->saveData($complete_ttl, $ttlUri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);

        if ($saved_uri === false) {
          return [
            'success' => false,
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

    $ttlContent = (string) file_get_contents($ttlPath);

    try {
      $existingVersions = [];
      if (is_dir($fs->realpath($versionsDir))) {
        $dirs = scandir($fs->realpath($versionsDir));
        foreach ($dirs as $d) {
          if (preg_match('/^v(\d{4})$/', $d, $m)) {
            $existingVersions[] = (int) $m[1];
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
    }

    $append = '';
    $count = 0;
    $created_mappings = [];
    $existing_mappings = [];
    $timestamp = date('Y-m-d H:i:s');

    foreach ($mappings as $mapping) {
      $externalUri = $mapping['external_uri'];
      $parentUri = $mapping['parent_uri'];
      $label = $mapping['label'];

      $entry_point_name = substr($parentUri, strrpos($parentUri, '/') + 1);
      $bound_term_name = substr($externalUri, strrpos($externalUri, '#') !== false ? strrpos($externalUri, '#') + 1 : strrpos($externalUri, '/') + 1);

      $mapping_info = [
        'label' => $label,
        'bound_term' => $bound_term_name,
        'entry_point' => $entry_point_name,
      ];

      if (strpos($ttlContent, "<$externalUri>") !== false && strpos($ttlContent, "rdfs:subClassOf <$parentUri>") !== false) {
        $existing_mappings[] = $mapping_info;
        continue;
      }

      if (preg_match('/[\s<>"{}|^`\\\\]/', $externalUri) || preg_match('/[\s<>"{}|^`\\\\]/', $parentUri)) {
        continue;
      }

      $append .= "\n# --- Mapping for $label (created by PMSR Ontology Ingestion at $timestamp) ---\n";
      $append .= "<$externalUri>\n";
      $append .= "\ta rdfs:Class;\n";
      $append .= "\trdfs:subClassOf <$parentUri> .\n";

      $count++;
      $created_mappings[] = $mapping_info;
    }

    if ($count === 0) {
      return [
        'success' => true,
        'count' => 0,
        'existing' => $existing_mappings,
        'created' => [],
        'message' => 'Entry points already exist',
      ];
    }

    $bytes = file_put_contents($ttlPath, $append, FILE_APPEND | LOCK_EX);
    if ($bytes === false) {
      return [
        'success' => false,
        'count' => 0,
        'existing' => $existing_mappings,
        'created' => [],
        'message' => 'Failed to append to hasco.ttl',
      ];
    }

    $modified_content = file_get_contents($ttlPath);
    $post_validation = \Drupal\pmsr\Validation\HascoIntegrityValidator::validateHascoTtl($modified_content);

    if (!$post_validation['valid']) {
      $error_msg = 'CRITICAL: hasco.ttl became invalid after modification. Restoring from backup. Errors: ' . implode('; ', $post_validation['errors']);

      \Drupal::logger('pmsr')->emergency($error_msg, [
        'validation' => $post_validation,
        'backup_path' => $backup_result['backup_path'],
      ]);

      if (file_exists($backup_result['backup_path'])) {
        copy($backup_result['backup_path'], $ttlPath);
        \Drupal::logger('pmsr')->notice('Successfully restored hasco.ttl from backup');
      }

      return [
        'success' => false,
        'count' => $count,
        'existing' => $existing_mappings,
        'created' => $created_mappings,
        'message' => $error_msg . ' File restored from backup.',
        'validation' => $post_validation,
      ];
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $file_content = file_get_contents($ttlPath);

      if ($file_content === false) {
        return [
          'success' => false,
          'count' => $count,
          'existing' => $existing_mappings,
          'created' => $created_mappings,
          'message' => 'Failed to read hasco.ttl for ingestion',
        ];
      }

      $namespace_uri = 'http://hadatac.org/ont/hasco/';
      $ingest_result = $api->repoIngestNamespaceOntology('hasco', $namespace_uri, $file_content, 'text/turtle', 'pmsr-ingest-ontologies');
      $ingest_data = json_decode($ingest_result);

      if (!$ingest_data || !$ingest_data->isSuccessful) {
        return [
          'success' => false,
          'count' => $count,
          'existing' => $existing_mappings,
          'created' => $created_mappings,
          'message' => 'Failed to ingest hasco.ttl into Fuseki: ' . ($ingest_data->body ?? 'Unknown error'),
        ];
      }

      sleep(2);

      try {
        $verify_result = $api->getChildren('http://hadatac.org/ont/hasco/ClassEntryPoint');
        $verify_data = json_decode($verify_result);

        $entry_point_count = 0;
        if ($verify_data && $verify_data->isSuccessful && is_array($verify_data->body)) {
          $entry_point_count = count($verify_data->body);
        }

        if ($entry_point_count < 23) {
          \Drupal::logger('pmsr')->emergency('CRITICAL: Entry points lost after ingestion! Found only {count} of 23 required entry points', [
            'count' => $entry_point_count,
            'backup_path' => $backup_result['backup_path'],
          ]);

          if (file_exists($backup_result['backup_path'])) {
            $backup_content = file_get_contents($backup_result['backup_path']);
            $api->repoIngestNamespaceOntology('hasco', $namespace_uri, $backup_content, 'text/turtle', 'pmsr-ingest-ontologies');
            sleep(2);

            \Drupal::logger('pmsr')->notice('Emergency restore attempted from backup');
          }

          return [
            'success' => false,
            'count' => $count,
            'existing' => $existing_mappings,
            'created' => $created_mappings,
            'message' => "CRITICAL: Entry points lost after ingestion! Found only {$entry_point_count} of 23 required. Emergency restore attempted.",
            'entry_point_count' => $entry_point_count,
          ];
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->warning('Failed to verify entry points after ingestion: {msg}', ['msg' => $e->getMessage()]);
      }
    } catch (\Throwable $e) {
      return [
        'success' => false,
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
