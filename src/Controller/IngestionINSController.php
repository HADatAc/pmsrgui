<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\pmsr\Support\PmsrSetupTracker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Process\Process;
use Drupal\file\Entity\File;
use Drupal\rep\Vocabulary\VSTOI;

/**
 * Controller for INS (Instrument) ingestion operations.
 */
class IngestionINSController extends ControllerBase {

  private const INS_PRIMARY_FILENAME = 'INS-PMSR-V3.xlsx';

  private const INS_EXPECTED_INFOSHEET_KEYS = [
    'hasDependencies',
    'Instruments',
    'SlotElements',
    'ComponentStems',
    'Components',
    'CodeBooks',
    'CodeBookSlots',
    'ResponseOptions',
    'Annotations',
    'AnnotationStems',
  ];

  /**
   * Display the INS ingestion page.
   */
  public function ingestInstruments() {
    $output = '';
    $insFile = self::INS_PRIMARY_FILENAME;
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest INS (Instrument Specification) templates from the ' . $insFile . ' file into the system.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>INS Template to be Ingested</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<ul>';
    $output .= '<li><strong>File:</strong> ' . $insFile . '</li>';
    $output .= '<li><strong>Type:</strong> Instrument Specification (INS)</li>';
    $output .= '<li><strong>Location:</strong> mts/ directory</li>';
    $output .= '</ul>';
    $output .= '<div class="alert alert-info mt-2">';
    $output .= '<strong>Process:</strong> The file will be uploaded to HAScO API, processed as a metadata template, and ingested into the system. ';
    $output .= 'After successful ingestion, you can view the template in the INS Templates list with status "PROCESSED".<br><br>';
    $output .= '<strong>Note:</strong> If INS instance data already exists, it will be deleted from the INS named graph ';
    $output .= '(<code>http://hadatac.org/ont/ins</code>) before loading new data. ';
    $output .= '<strong>The VSTOI instrument class definitions (142 classes) are preserved</strong> - only instance data is replaced.';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-danger btn-lg btn-start-uningest ms-2">Start Uningestion</button>';
    $output .= '<a href="/pmsr/statistics/refresh" class="btn btn-success btn-lg ms-2">Refresh Statistics</a>';
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
              'endpoint' => '/pmsr/api/ingest/ins/process',
              'startEndpoint' => '/pmsr/api/ingest/ins/start',
              'statusEndpoint' => '/pmsr/api/ingest/ins/status',
              'message' => 'Ingesting INS template...',
            ],
            'uningest' => [
              'endpoint' => '/pmsr/api/uningest/ins/process',
              'message' => 'Uningesting INS template...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Start a tracked INS ingestion job and return a job ID for polling.
   */
  public function startINSIngestion(Request $request) {
    PmsrSetupTracker::markStageStarted('ingest_ins_instruments', 'INS ingestion job started');

    $jobId = 'ins-' . \Drupal::time()->getCurrentTime() . '-' . bin2hex(random_bytes(4));

    $progress = [
      '[0/10] Ingestion job created. Waiting for worker start...',
    ];

    $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, [], [
      'message' => 'INS ingestion started',
      'startedAt' => \Drupal::time()->getCurrentTime(),
      'currentStep' => 0,
      'totalSteps' => 10,
    ]);

    return new JsonResponse([
      'success' => true,
      'jobId' => $jobId,
      'message' => 'INS ingestion job started',
      'progress' => $progress,
    ]);
  }

  /**
   * Get current status for a tracked INS ingestion job.
   */
  public function getINSIngestionStatus(string $jobId) {
    $state = $this->getINSIngestionJobState($jobId);

    if ($state === NULL) {
      return new JsonResponse([
        'success' => false,
        'status' => 'UNKNOWN',
        'message' => 'Ingestion job not found',
        'jobId' => $jobId,
        'progress' => [],
        'errors' => ['Ingestion job not found or expired'],
      ], 404);
    }

    if (($state['status'] ?? 'UNKNOWN') === 'RUNNING') {
      $state = $this->refreshRunningINSIngestionState($state);
    }

    return new JsonResponse([
      'success' => ($state['status'] ?? 'UNKNOWN') === 'SUCCESS',
      'status' => $state['status'] ?? 'UNKNOWN',
      'message' => $state['message'] ?? '',
      'jobId' => $jobId,
      'progress' => $state['progress'] ?? [],
      'errors' => $state['errors'] ?? [],
      'currentStep' => $state['currentStep'] ?? 0,
      'totalSteps' => $state['totalSteps'] ?? 10,
      'insUri' => $state['insUri'] ?? NULL,
      'dataFileUri' => $state['dataFileUri'] ?? NULL,
      'finalTemplateStatus' => $state['finalTemplateStatus'] ?? NULL,
      'finalFileStatus' => $state['finalFileStatus'] ?? NULL,
      'updatedAt' => $state['updatedAt'] ?? NULL,
      'startedAt' => $state['startedAt'] ?? NULL,
      'finishedAt' => $state['finishedAt'] ?? NULL,
    ]);
  }

  /**
   * Process the INS ingestion.
   * 
   * Follows the same pattern as AddMTForm (rep module) for consistency:
  * 1. Locates the INS-PMSR-V3.xlsx file in the mts/ directory
   * 2. Creates a Drupal file entity for tracking
   * 3. Generates unique URIs for DataFile (DFL) and INS (INF)
   * 4. Checks for and cleans existing INS instance data (if any)
   * 5. Creates DataFile entity using datafileAdd()
   * 6. Creates INS entity using elementAdd() - links to DataFile via hasDataFileUri
   * 7. Uploads file content to HAScO API
   * 8. Triggers ingestion via uploadTemplate()
   * 9. Verifies ingestion status
   * 10. Invalidates statistics cache
   * 
   * Key Concepts:
   * - INS = Metadata Template type for ingesting instruments (simulators in PMSR context)
   * - DFL = DataFile entity that encodes file metadata
   * - INF = Prefix for INS entities (not INS prefix which is for instrument instances)
   * - Each ingestion creates NEW entities with unique generated URIs
   * - INS instances are stored in http://hadatac.org/ont/ins named graph
   * - VSTOI class definitions remain in separate ontology graph
   */
  public function processINSIngestion(Request $request) {
    PmsrSetupTracker::markStageStarted('ingest_ins_instruments', 'INS ingestion worker started');

    // Increase execution time limit for long-running ingestion (5 minutes)
    set_time_limit(300);

    $payload = json_decode($request->getContent(), TRUE);
    $jobId = NULL;
    if (is_array($payload) && isset($payload['jobId']) && is_string($payload['jobId'])) {
      $jobId = trim($payload['jobId']);
      if ($jobId === '') {
        $jobId = NULL;
      }
    }
    
    $progress = [];
    $errors = [];

    if ($jobId !== NULL) {
      $progress[] = "[0/10] Worker started for job {$jobId}";
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'INS ingestion worker started',
        'currentStep' => 0,
        'totalSteps' => 10,
      ]);
    }
    
    \Drupal::logger('pmsr')->info('INS ingestion started');
    
    // Step 1: Locate the INS file
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
    $primary_file_path = DRUPAL_ROOT . '/' . $module_path . '/mts/' . self::INS_PRIMARY_FILENAME;
    $selected_filename = self::INS_PRIMARY_FILENAME;
    $file_path = $primary_file_path;

    $progress[] = "[1/10] Locating " . self::INS_PRIMARY_FILENAME . " file...";

    if (!file_exists($file_path)) {
      $errors[] = self::INS_PRIMARY_FILENAME . " file not found at: " . $primary_file_path;
      \Drupal::logger('pmsr')->error("INS: File not found at $file_path");
      return $this->insIngestionResponse(false, 'INS file not found', $progress, $errors, $jobId);
    }

    $progress[] = "  ✓ File found: " . $selected_filename;

    // Strict INS-SPEC conformance: reject any unexpected InfoSheet key.
    $infoSheetValidation = $this->validateINSInfoSheetKeySet($file_path);
    if (!$infoSheetValidation['success']) {
      $errors[] = $infoSheetValidation['message'];
      \Drupal::logger('pmsr')->error('INS preflight failed: ' . $infoSheetValidation['message']);
      return $this->insIngestionResponse(false, 'INS InfoSheet validation failed', $progress, $errors, $jobId);
    }
    if (!empty($infoSheetValidation['warnings'])) {
      foreach ($infoSheetValidation['warnings'] as $warning) {
        $progress[] = '  ⚠ ' . $warning;
      }
    }
    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'INS file located',
        'currentStep' => 1,
        'totalSteps' => 10,
      ]);
    }
    
    // Step 2: Create Drupal file entity
    $progress[] = "[2/10] Creating Drupal file entity...";
    
    try {
      // Always refresh the Drupal file from current module mts content.
      // Reusing an existing file entity by filename can preserve stale binary content.
      $destination = 'public://mts/' . $selected_filename;
      $directory = dirname($destination);
      \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

      $file_contents = file_get_contents($file_path);
      $file = \Drupal::service('file.repository')->writeData($file_contents, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);

      if (!$file) {
        $errors[] = "Failed to create/update file entity";
        return $this->insIngestionResponse(false, 'Could not create/update file entity', $progress, $errors, $jobId);
      }

      $file->setPermanent();
      $file->save();
      $progress[] = "  ✓ Refreshed file entity from mts/{$selected_filename} (ID: {$file->id()})";
    } catch (\Exception $e) {
      $errors[] = "Exception creating file entity: " . $e->getMessage();
      return $this->insIngestionResponse(false, 'Exception during file entity creation', $progress, $errors, $jobId);
    }

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'Drupal file entity ready',
        'currentStep' => 2,
        'totalSteps' => 10,
      ]);
    }
    
    // Step 3: Generate URIs
    $progress[] = "[3/10] Generating URIs...";
    
    $api = \Drupal::service('rep.api_connector');
    
    // Generate DataFile URI (DFL prefix) with fallback when repoInfo is transiently unavailable.
    $newDataFileUri = $this->generateDataFileUriWithFallback($api, $progress);

    // Generate INS URI linked to the same identifier as the DataFile URI.
    $newINSUri = $this->buildLinkedTemplateUri($newDataFileUri, 'ins');

    if ($newDataFileUri === '' || $newINSUri === '') {
      $errors[] = 'Could not generate stable DataFile/INS URIs for ingestion.';
      return $this->insIngestionResponse(false, 'URI generation failed', $progress, $errors, $jobId);
    }
    
    $progress[] = "  ✓ DataFile (DFL) URI: " . $newDataFileUri;
    $progress[] = "  ✓ INS (INF) URI: " . $newINSUri;

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'Generated DataFile and INS URIs',
        'currentStep' => 3,
        'totalSteps' => 10,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }

    
    // Step 4: Check for and delete existing INS instance data
    $progress[] = "[4/10] Checking for existing INS instance data...";
    
    $ins_namespace = 'http://hadatac.org/ont/ins';
    $ins_abbreviation = 'ins';
    
    try {
      // Get namespace list to check if INS namespace exists (would contain instance data)
      $namespace_list_response = $api->namespaceList();
      $namespace_data = json_decode($namespace_list_response);
      $namespace_exists = false;
      $triple_count = 0;
      
      if ($namespace_data && $namespace_data->isSuccessful && is_array($namespace_data->body)) {
        foreach ($namespace_data->body as $ns) {
          if (strtolower($ns->label) === $ins_abbreviation || $ns->uri === $ins_namespace) {
            $namespace_exists = true;
            $triple_count = $ns->numberOfLoadedTriples ?? 0;
            $progress[] = "  → Found INS namespace with {$triple_count} instance triples";
            break;
          }
        }
      }
      
      if ($namespace_exists && $triple_count > 0) {
        // Delete the INS named graph (contains instrument instances, NOT the VSTOI class definitions)
        $progress[] = "  → Deleting INS named graph (instrument instances only, VSTOI classes preserved)...";
        
        $delete_response = $api->repoDeleteSelectedNamespaceTriples([$ins_namespace]);
        $delete_data = json_decode($delete_response);
        
        if (!$delete_data || !$delete_data->isSuccessful) {
          $errors[] = "Failed to delete INS named graph";
          return $this->insIngestionResponse(false, 'Failed to delete existing INS instance data', $progress, $errors, $jobId, [
            'insUri' => $newINSUri,
            'dataFileUri' => $newDataFileUri,
          ]);
        }
        
        $progress[] = "  ✓ Deleted INS instance data ({$triple_count} triples removed)";
        $progress[] = "  ✓ VSTOI instrument class definitions (142 classes) preserved";
      } else {
        $progress[] = "  ✓ No existing INS instance data found (first ingestion)";
        $progress[] = "  ✓ VSTOI instrument class definitions (142 classes) already in triplestore";
      }
    } catch (\Exception $e) {
      $progress[] = "  ⚠ Could not check/delete INS data: " . $e->getMessage();
      $progress[] = "  → Proceeding with ingestion...";
    }

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'INS instance graph cleanup completed',
        'currentStep' => 4,
        'totalSteps' => 10,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }
    
    // Step 5: Check for and delete existing INS metadata templates
    $progress[] = "[5/10] Checking for existing INS metadata templates...";
    
    try {
      $response = $api->listByKeyword('ins', 'INS-PMSR-V3', 500, 0);
      $data = json_decode($response);
      
      $existingINS = [];
      if ($data && $data->isSuccessful && !empty($data->body)) {
        foreach ($data->body as $ins) {
          $label = isset($ins->label) ? trim((string) $ins->label) : '';
          $filename = isset($ins->hasDataFile->filename) ? trim((string) $ins->hasDataFile->filename) : '';

          // Keep strict matching to canonical PMSR INS V3 template only.
          if ($label === 'INS-PMSR-V3' || $filename === self::INS_PRIMARY_FILENAME) {
            $existingINS[] = [
              'uri' => $ins->uri,
              'label' => $label,
              'dataFileUri' => $ins->hasDataFile->uri ?? null,
            ];
            $progress[] = "  → Found existing INS template: " . $ins->uri;
          }
        }
      }
      
      // Delete existing INS templates (which also deletes their DataFile graphs)
      if (!empty($existingINS)) {
        $progress[] = "  → Deleting " . count($existingINS) . " existing INS template(s)...";
        $deletionFailures = [];
        
        foreach ($existingINS as $ins) {
          try {
            $uningest_response = $api->uningestMT($ins['uri']);
            $uningest_data = json_decode($uningest_response);
            
            if ($uningest_data && $uningest_data->isSuccessful) {
              $progress[] = "    ✓ Deleted INS template and DataFile graph: " . $ins['uri'];
            } else {
              $failure = "Failed to delete " . $ins['uri'] . ": " . ($uningest_data->message ?? 'Unknown error');
              $deletionFailures[] = $failure;
              $progress[] = "    ⚠ " . $failure;
            }
          } catch (\Exception $e) {
            $failure = "Exception deleting " . $ins['uri'] . ": " . $e->getMessage();
            $deletionFailures[] = $failure;
            $progress[] = "    ⚠ " . $failure;
          }
        }

        if (!empty($deletionFailures)) {
          $errors = array_merge($errors, $deletionFailures);
          return $this->insIngestionResponse(false, 'Could not fully clean previous INS templates', $progress, $errors, $jobId, [
            'insUri' => $newINSUri,
            'dataFileUri' => $newDataFileUri,
          ]);
        }

        $progress[] = "  ✓ Cleaned up existing INS template data (rerun-safe)";
      } else {
        $progress[] = "  ✓ No existing INS templates found (first run)";
      }
    } catch (\Exception $e) {
      $progress[] = "  ⚠ Could not check for existing templates: " . $e->getMessage();
      $progress[] = "  → Proceeding with ingestion...";
    }

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'Previous INS templates cleanup completed',
        'currentStep' => 5,
        'totalSteps' => 10,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }
    
    // Step 6: Create DataFile (DFL) and INS metadata template in HAScO
    $progress[] = "[6/10] Creating DataFile (DFL) and INS metadata template...";
    
    try {
      $useremail = \Drupal::currentUser()->getEmail();
      
      // Build DATAFILE JSON (following AddMTForm pattern)
      $datafileJSON = json_encode([
        "uri" => $newDataFileUri,
        "typeUri" => \Drupal\rep\Vocabulary\HASCO::DATAFILE,
        "hascoTypeUri" => \Drupal\rep\Vocabulary\HASCO::DATAFILE,
        "label" => 'INS-PMSR-V3',
        "filename" => $selected_filename,
        "fileStatus" => \Drupal\rep\Constant::FILE_STATUS_UNPROCESSED,
        "hasSIRManagerEmail" => $useremail,
        "id" => $file->id(),
      ]);
      
      // Build INS JSON (following AddMTForm pattern)
      $insJSON = json_encode([
        "uri" => $newINSUri,
        "typeUri" => \Drupal\rep\Vocabulary\HASCO::INS,
        "hascoTypeUri" => \Drupal\rep\Vocabulary\HASCO::INS,
        "label" => 'INS-PMSR-V3',
        "hasDataFileUri" => $newDataFileUri,
        "hasVersion" => '1.0',
        "comment" => 'INS metadata template for PMSR simulator models',
        "hasSIRManagerEmail" => $useremail,
      ]);
      
      // Create DataFile first (same as AddMTForm)
      $datafileRawResponse = $api->datafileAdd($datafileJSON);
      $msg1 = $api->parseObjectResponse($datafileRawResponse, 'datafileAdd');
      if ($msg1 == NULL) {
        $errors[] = "Failed to create DataFile (DFL) entity. " . $this->extractApiFailureDetail($datafileRawResponse);
        return $this->insIngestionResponse(false, 'DataFile creation failed', $progress, $errors, $jobId, [
          'insUri' => $newINSUri,
          'dataFileUri' => $newDataFileUri,
        ]);
      }
      $progress[] = "  ✓ Created DataFile (DFL) entity";
      
      // Create INS using elementAdd (same as AddMTForm)
      $insRawResponse = $api->elementAdd('ins', $insJSON);
      $msg2 = $api->parseObjectResponse($insRawResponse, 'elementAdd');
      if ($msg2 == NULL) {
        $errors[] = "Failed to create INS metadata template. " . $this->extractApiFailureDetail($insRawResponse);
        return $this->insIngestionResponse(false, 'INS creation failed', $progress, $errors, $jobId, [
          'insUri' => $newINSUri,
          'dataFileUri' => $newDataFileUri,
        ]);
      }
      $progress[] = "  ✓ Created INS (INF) metadata template";
      $progress[] = "  ✓ INS linked to DataFile: " . $newDataFileUri;
      
    } catch (\Exception $e) {
      $errors[] = "Exception creating entities: " . $e->getMessage();
      return $this->insIngestionResponse(false, 'Exception during entity creation', $progress, $errors, $jobId, [
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'DataFile and INS entities created',
        'currentStep' => 6,
        'totalSteps' => 10,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }
    
    // Step 7: Upload file content to HAScO API
    $progress[] = "[7/10] Uploading file content to HAScO API...";
    
    try {
      // Pass INS URI - uploadFile will find the INS entity and extract the DataFile URI from hasDataFileUri property
      $upload_result = $api->uploadFile($newINSUri, $file->id());
      
      if ($upload_result === NULL || $upload_result === FALSE || $upload_result === '') {
        $errors[] = "Failed to upload file to HAScO API";
        return $this->insIngestionResponse(false, 'File upload to API failed', $progress, $errors, $jobId, [
          'insUri' => $newINSUri,
          'dataFileUri' => $newDataFileUri,
        ]);
      }
      
      $progress[] = "  ✓ File content uploaded successfully to HAScO API";
    } catch (\Exception $e) {
      $errors[] = "Exception uploading file: " . $e->getMessage();
      return $this->insIngestionResponse(false, 'Exception during file upload', $progress, $errors, $jobId, [
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'INS file content uploaded to HAScO API',
        'currentStep' => 7,
        'totalSteps' => 10,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }
    
    // Step 8: Trigger template ingestion
    $progress[] = "[8/10] Triggering template ingestion...";
    
    try {
      // Create template object for ingestion
      $template = new \stdClass();
      $template->uri = $newINSUri;
      $template->hasDataFileUri = $newDataFileUri;
      $template->hasDataFile = new \stdClass();
      $template->hasDataFile->id = $file->id();
      $template->hasDataFile->filename = $selected_filename;
      
      // Trigger ingestion with 'ins' concept and '_' status (auto-detect)
      $ingest_result = $api->uploadTemplate('ins', $template, '_');
      
      if ($ingest_result === NULL || $ingest_result === FALSE || $ingest_result === '') {
        $errors[] = "Failed to trigger ingestion in HAScO API";
        $progress[] = "  ✗ Ingestion trigger failed";
        return $this->insIngestionResponse(false, 'Ingestion trigger failed', $progress, $errors, $jobId, [
          'insUri' => $newINSUri,
          'dataFileUri' => $newDataFileUri,
        ]);
      }
      
      $progress[] = "  ✓ Ingestion triggered successfully";
      $progress[] = "  ✓ INS (INF) URI: " . $newINSUri;
      $progress[] = "  ✓ DataFile (DFL) URI: " . $newDataFileUri;
    } catch (\Exception $e) {
      $errors[] = "Exception during ingestion: " . $e->getMessage();
      return $this->insIngestionResponse(false, 'Exception during ingestion trigger', $progress, $errors, $jobId, [
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'Template ingestion submitted to backend',
        'currentStep' => 8,
        'totalSteps' => 10,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }
    
    // Step 9: Verify ingestion status
    $progress[] = "[9/10] Verifying ingestion status...";

    $ingestionVerified = false;
    $ingestionFailed = false;
    $ingestionFailureReason = '';
    $finalTemplateStatus = 'UNKNOWN';
    $finalFileStatus = 'UNKNOWN';
    
    try {
      // Poll up to ~300s because ingest runs asynchronously in hascoapi.
      $maxAttempts = 100;
      $sleepSeconds = 3;

      for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ins_response = $api->getUri($newINSUri);
        $ins = $api->parseObjectResponse($ins_response, 'getUri');

        // Always try to resolve DataFile directly as fallback because INS->hasDataFile
        // can be absent/stale during async ingestion windows.
        $dataFile = NULL;

        if ($ins && isset($ins->uri)) {
          if (isset($ins->hasDataFile) && is_object($ins->hasDataFile)) {
            $dataFile = $ins->hasDataFile;
          }

          if ($dataFile === NULL && isset($ins->hasDataFileUri) && is_string($ins->hasDataFileUri) && trim($ins->hasDataFileUri) !== '') {
            $df_from_ins_uri = $api->parseObjectResponse($api->getUri(trim($ins->hasDataFileUri)), 'getUri');
            if ($df_from_ins_uri && is_object($df_from_ins_uri)) {
              $dataFile = $df_from_ins_uri;
            }
          }

          $templateStatusRaw = isset($ins->hasStatus) ? (string) $ins->hasStatus : '';
          $finalTemplateStatus = $this->normalizeStatusToken($templateStatusRaw, 'UNKNOWN');
        }

        if ($dataFile === NULL && is_string($newDataFileUri) && trim($newDataFileUri) !== '') {
          $df_direct = $api->parseObjectResponse($api->getUri($newDataFileUri), 'getUri');
          if ($df_direct && is_object($df_direct)) {
            $dataFile = $df_direct;
          }
        }

        $fileStatusRaw = '';
        if ($dataFile && is_object($dataFile)) {
          if (isset($dataFile->fileStatus)) {
            $fileStatusRaw = (string) $dataFile->fileStatus;
          } elseif (isset($dataFile->hasStatus)) {
            $fileStatusRaw = (string) $dataFile->hasStatus;
          }
        }
        $finalFileStatus = $this->normalizeStatusToken($fileStatusRaw, 'UNKNOWN');

        // Detect backend processing errors from DataFile logs.
        $logText = '';
        if ($dataFile && is_object($dataFile)) {
          if (isset($dataFile->log) && is_string($dataFile->log)) {
            $logText = $dataFile->log;
          } else if (isset($dataFile->hasLog) && is_string($dataFile->hasLog)) {
            $logText = $dataFile->hasLog;
          }
        }

        // Either object can lead completion, depending on when hascoapi persists each resource.
        if ($finalFileStatus === 'PROCESSED' || $finalTemplateStatus === 'PROCESSED') {
          $ingestionVerified = true;
          break;
        }

        if ($finalFileStatus === 'FAILED' || $finalFileStatus === 'ERROR' || $finalTemplateStatus === 'FAILED' || $finalTemplateStatus === 'ERROR') {
          $ingestionFailed = true;
          $ingestionFailureReason = 'Backend status indicates failure. INS=' . $finalTemplateStatus . ', DataFile=' . $finalFileStatus;
          break;
        }

        if ($logText !== '' && preg_match('/Error in INSGenerator|Not a valid \(absolute\) IRI|\[ERROR\]/i', $logText)) {
          $ingestionFailed = true;
          $cleanLog = preg_replace('/\s+/', ' ', $logText);
          $ingestionFailureReason = 'Backend log reports ingestion error: ' . substr($cleanLog, 0, 300);
          break;
        }

        if ($attempt < $maxAttempts) {
          sleep($sleepSeconds);
        }
      }

      $progress[] = "  ✓ INS template status: " . $finalTemplateStatus;
      $progress[] = "  ✓ DataFile processing status: " . $finalFileStatus;

      if ($ingestionVerified) {
        $progress[] = "  ✓ Template successfully processed!";
        if ($finalFileStatus === 'PROCESSED' && $finalTemplateStatus !== 'PROCESSED') {
          $progress[] = "  ✓ Backend terminal status reached via DataFile processing.";
        } else {
          $progress[] = "  ✓ Backend terminal status reached for INS ingestion.";
        }
      } else if ($ingestionFailed) {
        $errors[] = $ingestionFailureReason !== ''
          ? $ingestionFailureReason
          : 'Ingestion failed according to backend processing status.';
        $progress[] = "  ✗ Ingestion failed during backend processing.";
      } else {
        $progress[] = "  → Backend is still processing (latest DataFile status: " . $finalFileStatus . ").";
        $progress[] = "  → Live status polling will continue until terminal status is reached.";
      }
    } catch (\Exception $e) {
      $ingestionFailed = true;
      $ingestionFailureReason = 'Could not verify ingestion status: ' . $e->getMessage();
      $errors[] = $ingestionFailureReason;
      $progress[] = "  ✗ " . $ingestionFailureReason;
    }

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'Backend status verification completed',
        'currentStep' => 9,
        'totalSteps' => 10,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
        'finalTemplateStatus' => $finalTemplateStatus,
        'finalFileStatus' => $finalFileStatus,
      ]);
    }
    
    // Step 10: Invalidate INS cache
    $progress[] = "[10/10] Invalidating statistics cache...";
    
    try {
      \Drupal\pmsr\Controller\IngestionController::invalidateStatisticsCache(['ins']);
      $progress[] = "  ✓ Invalidated statistics cache for INS";
    } catch (\Exception $e) {
      // Non-fatal
    }

    if ($jobId !== NULL) {
      $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
        'message' => 'INS statistics cache invalidated',
        'currentStep' => 10,
        'totalSteps' => 10,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
        'finalTemplateStatus' => $finalTemplateStatus,
        'finalFileStatus' => $finalFileStatus,
      ]);
    }
    
    if ($ingestionVerified && empty($errors)) {
      return $this->insIngestionResponse(true, 'INS template ingestion completed successfully!', $progress, $errors, $jobId, [
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
      ]);
    }

    if (!$ingestionFailed && empty($errors)) {
      if ($jobId !== NULL) {
        $this->persistINSIngestionJobState($jobId, 'RUNNING', $progress, $errors, [
          'message' => 'INS ingestion still running in backend',
          'currentStep' => 9,
          'totalSteps' => 10,
          'insUri' => $newINSUri,
          'dataFileUri' => $newDataFileUri,
          'finalTemplateStatus' => $finalTemplateStatus,
          'finalFileStatus' => $finalFileStatus,
        ]);
      }

      return new JsonResponse([
        'success' => true,
        'running' => true,
        'message' => 'INS ingestion is still processing in backend. Continue polling status endpoint.',
        'progress' => $progress,
        'errors' => $errors,
        'jobId' => $jobId,
        'insUri' => $newINSUri,
        'dataFileUri' => $newDataFileUri,
        'finalTemplateStatus' => $finalTemplateStatus,
        'finalFileStatus' => $finalFileStatus,
      ]);
    }

    return $this->insIngestionResponse(false, 'INS template ingestion failed during backend processing.', $progress, $errors, $jobId, [
      'insUri' => $newINSUri,
      'dataFileUri' => $newDataFileUri,
      'finalTemplateStatus' => $finalTemplateStatus,
      'finalFileStatus' => $finalFileStatus,
    ]);
  }

  /**
   * Process INS uningest request.
   * 
   * This method finds the most recent INS metadata template and uningest it,
   * which will delete the DataFile's named graph containing all INS-created
   * instrument classes.
   */
  public function processINSUningest(Request $request) {
    $progress = [];
    $errors = [];
    
    $progress[] = "Starting INS Uningestion Process...";
    $progress[] = "";
    
    // Step 1: Find the current INS metadata template
    $progress[] = "[1/4] Finding INS metadata template...";
    
    try {
      $api = \Drupal::service('rep.api_connector');
      
      // Get current user email
      $useremail = \Drupal::currentUser()->getEmail();
      
      // Get list of INS templates using listByManagerEmail
      $response = $api->listByManagerEmail('ins', $useremail, 100, 0);
      $data = json_decode($response);
      
      if (!$data || !$data->isSuccessful || empty($data->body)) {
        return new JsonResponse([
          'success' => false,
          'message' => 'No INS metadata template found to uningest',
          'progress' => $progress,
          'errors' => ['No INS metadata template exists in the system'],
        ]);
      }
      
      // Find the INS template (there should only be one active INS PMSR template)
      $insUri = null;
      foreach ($data->body as $ins) {
        if (isset($ins->uri) && strpos($ins->uri, 'INF') !== false) {
          $insUri = $ins->uri;
          $progress[] = "  ✓ Found INS template: " . $insUri;
          if (isset($ins->label)) {
            $progress[] = "    Label: " . $ins->label;
          }
          break;
        }
      }
      
      if (!$insUri) {
        return new JsonResponse([
          'success' => false,
          'message' => 'Could not find INS template URI',
          'progress' => $progress,
          'errors' => ['INS template URI not found in response'],
        ]);
      }
      
    } catch (\Exception $e) {
      $errors[] = "Exception finding INS template: " . $e->getMessage();
      return new JsonResponse([
        'success' => false,
        'message' => 'Exception during INS lookup',
        'progress' => $progress,
        'errors' => $errors,
      ]);
    }
    
    // Step 2: Call uningest API
    $progress[] = "[2/4] Calling HAScO API to uningest INS...";
    
    try {
      $uningest_response = $api->uningestMT($insUri);
      $uningest_data = json_decode($uningest_response);
      
      if (!$uningest_data || !$uningest_data->isSuccessful) {
        $error_msg = isset($uningest_data->message) ? $uningest_data->message : 'Unknown error';
        $errors[] = "Uningest API call failed: " . $error_msg;
        return new JsonResponse([
          'success' => false,
          'message' => 'Uningest API call failed',
          'progress' => $progress,
          'errors' => $errors,
        ]);
      }
      
      $progress[] = "  ✓ INS metadata template uningested successfully";
      $progress[] = "  ✓ DataFile named graph deleted (all INS-created instrument classes removed)";
      $progress[] = "  ✓ INS and DataFile entities deleted from HAScO";
      
    } catch (\Exception $e) {
      $errors[] = "Exception during uningest: " . $e->getMessage();
      return new JsonResponse([
        'success' => false,
        'message' => 'Exception during uningest',
        'progress' => $progress,
        'errors' => $errors,
      ]);
    }
    
    // Step 3: Invalidate statistics cache
    $progress[] = "[3/4] Invalidating statistics cache...";
    
    try {
      \Drupal\pmsr\Controller\IngestionController::invalidateStatisticsCache(['ins']);
      $progress[] = "  ✓ Statistics cache invalidated";
    } catch (\Exception $e) {
      $progress[] = "  ⚠ Could not invalidate cache: " . $e->getMessage();
    }
    
    // Step 4: Success
    $progress[] = "[4/4] Uningestion completed successfully!";
    $progress[] = "";
    $progress[] = "✓ INS metadata template has been removed from the system";
    $progress[] = "✓ All INS-created instrument classes have been deleted";
    $progress[] = "✓ Instrument count should now be back to baseline (80: 77 VSTOI + 3 PMSR)";
    
    return new JsonResponse([
      'success' => true,
      'message' => 'INS uningestion completed successfully',
      'progress' => $progress,
      'errors' => $errors,
    ]);
  }

  /**
   * Refresh statistics cache.
   * 
   * This method clears all cached statistics values and redirects to the
   * statistics page, forcing fresh data to be fetched from the triplestore.
   */
  public function refreshStatistics() {
    // Invalidate all statistics caches
    $ontologies = ['ins', 'pmsr', 'uberon', 'ncit'];
    
    try {
      \Drupal\pmsr\Controller\IngestionController::invalidateStatisticsCache($ontologies);
      
      \Drupal::messenger()->addStatus(
        'Statistics cache refreshed successfully. The page will now show current data from the triplestore.'
      );
    } catch (\Exception $e) {
      \Drupal::messenger()->addError(
        'Error refreshing statistics cache: ' . $e->getMessage()
      );
    }
    
    // Redirect to statistics page
    return new RedirectResponse('/pmsr/statistics');
  }

  /**
   * Convert status strings (including URI forms) to normalized tokens.
   */
  private function normalizeStatusToken($rawStatus, string $default = 'UNKNOWN'): string {
    if (!is_string($rawStatus)) {
      return $default;
    }

    $value = trim($rawStatus);
    if ($value === '') {
      return $default;
    }

    if (strpos($value, '#') !== FALSE) {
      $value = substr($value, strrpos($value, '#') + 1);
    } elseif (strpos($value, '/') !== FALSE) {
      $value = substr($value, strrpos($value, '/') + 1);
    }

    $token = strtoupper(trim($value));
    if ($token === '') {
      return $default;
    }

    if (strpos($token, 'PROCESS') !== FALSE) {
      return 'PROCESSED';
    }
    if (strpos($token, 'WORK') !== FALSE || strpos($token, 'INGEST') !== FALSE || strpos($token, 'RUN') !== FALSE) {
      return 'WORKING';
    }
    if (strpos($token, 'FAIL') !== FALSE || strpos($token, 'ERROR') !== FALSE) {
      return 'ERROR';
    }

    return $token;
  }

  /**
   * Build and persist a consistent INS ingestion response.
   */
  private function insIngestionResponse(bool $success, string $message, array $progress, array $errors, ?string $jobId = NULL, array $extra = []): JsonResponse {
    PmsrSetupTracker::markStageResult('ingest_ins_instruments', $success, $message);

    if ($success) {
      $progress[] = '[final] Running namespace policy regression...';
      PmsrSetupTracker::recordTestResult('ingest_ins_instruments', 'namespace-policy', 'running', 'Executing run-tests.sh namespace-policy');

      try {
        $testsDir = DRUPAL_ROOT . '/modules/custom/pmsrgui/tests';
        $process = new Process(['./run-tests.sh', 'namespace-policy'], $testsDir, NULL, NULL, 900);
        $process->run();

        $combinedOutput = trim($process->getOutput() . "\n" . $process->getErrorOutput());
        $summary = $combinedOutput === '' ? 'No output from namespace policy regression.' : substr($combinedOutput, -500);

        if ($process->isSuccessful()) {
          PmsrSetupTracker::recordTestResult('ingest_ins_instruments', 'namespace-policy', 'pass', $summary);
          $progress[] = '  ✓ Namespace policy regression passed.';
        }
        else {
          $exitCode = $process->getExitCode();
          PmsrSetupTracker::recordTestResult('ingest_ins_instruments', 'namespace-policy', 'fail', 'Exit code ' . $exitCode . '. ' . $summary);
          $progress[] = '  ✗ Namespace policy regression failed (exit code ' . $exitCode . ').';
          $errors[] = 'Namespace policy regression failed after INS ingestion.';
        }
      }
      catch (\Throwable $e) {
        PmsrSetupTracker::recordTestResult('ingest_ins_instruments', 'namespace-policy', 'fail', 'Exception: ' . $e->getMessage());
        $progress[] = '  ✗ Namespace policy regression could not be executed.';
        $errors[] = 'Namespace policy regression execution error: ' . $e->getMessage();
      }
    }

    if ($jobId !== NULL) {
      $resolvedStep = isset($extra['currentStep'])
        ? (int) $extra['currentStep']
        : $this->extractCurrentStepFromProgress($progress);

      $this->persistINSIngestionJobState(
        $jobId,
        $success ? 'SUCCESS' : 'FAILED',
        $progress,
        $errors,
        array_merge($extra, [
          'message' => $message,
          'currentStep' => $resolvedStep,
          'totalSteps' => 10,
          'finishedAt' => \Drupal::time()->getCurrentTime(),
        ])
      );
    }

    return new JsonResponse(array_merge([
      'success' => $success,
      'message' => $message,
      'progress' => $progress,
      'errors' => $errors,
      'jobId' => $jobId,
    ], $extra));
  }

  /**
   * Persist INS ingestion state for live polling.
   */
  private function persistINSIngestionJobState(string $jobId, string $status, array $progress, array $errors, array $extra = []): void {
    $existing = $this->getINSIngestionJobState($jobId);

    $record = [
      'jobId' => $jobId,
      'status' => strtoupper($status),
      'message' => $extra['message'] ?? ($existing['message'] ?? ''),
      'progress' => $progress,
      'errors' => $errors,
      'currentStep' => $extra['currentStep'] ?? ($existing['currentStep'] ?? 0),
      'totalSteps' => $extra['totalSteps'] ?? ($existing['totalSteps'] ?? 10),
      'startedAt' => $existing['startedAt'] ?? ($extra['startedAt'] ?? \Drupal::time()->getCurrentTime()),
      'updatedAt' => \Drupal::time()->getCurrentTime(),
      'finishedAt' => $extra['finishedAt'] ?? ($existing['finishedAt'] ?? NULL),
      'insUri' => $extra['insUri'] ?? ($existing['insUri'] ?? NULL),
      'dataFileUri' => $extra['dataFileUri'] ?? ($existing['dataFileUri'] ?? NULL),
      'finalTemplateStatus' => $extra['finalTemplateStatus'] ?? ($existing['finalTemplateStatus'] ?? NULL),
      'finalFileStatus' => $extra['finalFileStatus'] ?? ($existing['finalFileStatus'] ?? NULL),
    ];

    \Drupal::state()->set($this->getINSIngestionJobStateKey($jobId), $record);
  }

  /**
   * Load persisted INS ingestion job state.
   */
  private function getINSIngestionJobState(string $jobId): ?array {
    $record = \Drupal::state()->get($this->getINSIngestionJobStateKey($jobId));
    return is_array($record) ? $record : NULL;
  }

  /**
   * Refresh a RUNNING job by querying current INS/DataFile statuses from HAScO.
   */
  private function refreshRunningINSIngestionState(array $state): array {
    $insUri = $state['insUri'] ?? NULL;
    $dataFileUri = $state['dataFileUri'] ?? NULL;

    if ((!is_string($insUri) || trim($insUri) === '') && (!is_string($dataFileUri) || trim($dataFileUri) === '')) {
      return $state;
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $templateStatus = 'UNKNOWN';
      $fileStatus = 'UNKNOWN';
      $dataFile = NULL;

      if (is_string($insUri) && trim($insUri) !== '') {
        $ins = $api->parseObjectResponse($api->getUri($insUri), 'getUri');
        if ($ins && isset($ins->hasStatus)) {
          $templateStatus = $this->normalizeStatusToken((string) $ins->hasStatus, 'UNKNOWN');
        }
        if ($ins && isset($ins->hasDataFile) && is_object($ins->hasDataFile)) {
          $dataFile = $ins->hasDataFile;
        }
      }

      if ($dataFile === NULL && is_string($dataFileUri) && trim($dataFileUri) !== '') {
        $df = $api->parseObjectResponse($api->getUri($dataFileUri), 'getUri');
        if ($df && is_object($df)) {
          $dataFile = $df;
        }
      }

      if ($dataFile && isset($dataFile->fileStatus)) {
        $fileStatus = $this->normalizeStatusToken((string) $dataFile->fileStatus, 'UNKNOWN');
      } elseif ($dataFile && isset($dataFile->hasStatus)) {
        $fileStatus = $this->normalizeStatusToken((string) $dataFile->hasStatus, 'UNKNOWN');
      }

      $state['finalTemplateStatus'] = $templateStatus;
      $state['finalFileStatus'] = $fileStatus;

      if ($templateStatus === 'PROCESSED' || $fileStatus === 'PROCESSED') {
        $state['status'] = 'SUCCESS';
        $state['message'] = 'INS template ingestion completed successfully.';
        $state['currentStep'] = 10;
        $state['finishedAt'] = \Drupal::time()->getCurrentTime();
      } elseif ($templateStatus === 'ERROR' || $templateStatus === 'FAILED' || $fileStatus === 'ERROR' || $fileStatus === 'FAILED') {
        $state['status'] = 'FAILED';
        $state['message'] = 'INS template ingestion failed during backend processing.';
        $state['currentStep'] = 9;
        $state['finishedAt'] = \Drupal::time()->getCurrentTime();
        if (empty($state['errors'])) {
          $state['errors'] = [];
        }
        $state['errors'][] = 'Backend status indicates failure. INS=' . $templateStatus . ', DataFile=' . $fileStatus;
      } else {
        $state['status'] = 'RUNNING';
        $state['message'] = 'Backend processing in progress. INS=' . $templateStatus . ', DataFile=' . $fileStatus;
        $state['currentStep'] = max((int) ($state['currentStep'] ?? 9), 9);
      }

      $state['updatedAt'] = \Drupal::time()->getCurrentTime();
      $this->persistINSIngestionJobState($state['jobId'], $state['status'], $state['progress'] ?? [], $state['errors'] ?? [], [
        'message' => $state['message'],
        'currentStep' => $state['currentStep'],
        'totalSteps' => $state['totalSteps'] ?? 10,
        'insUri' => $state['insUri'] ?? NULL,
        'dataFileUri' => $state['dataFileUri'] ?? NULL,
        'finalTemplateStatus' => $state['finalTemplateStatus'] ?? NULL,
        'finalFileStatus' => $state['finalFileStatus'] ?? NULL,
        'startedAt' => $state['startedAt'] ?? \Drupal::time()->getCurrentTime(),
        'finishedAt' => $state['finishedAt'] ?? NULL,
      ]);

      return $this->getINSIngestionJobState($state['jobId']) ?? $state;
    } catch (\Exception $e) {
      return $state;
    }
  }

  /**
   * Generate DataFile URI with a deterministic fallback if Utils::uriGen fails.
   */
  private function generateDataFileUriWithFallback($api, array &$progress): string {
    $generated = trim((string) \Drupal\rep\Utils::uriGen('datafile'));
    if ($generated !== '') {
      return $generated;
    }

    $progress[] = '  ⚠ Primary URI generator returned empty value, applying fallback strategy';

    $repoNamespace = '';
    try {
      $repoInfoRaw = $api->repoInfo();
      $repoObj = json_decode((string) $repoInfoRaw);
      if ($repoObj && !empty($repoObj->isSuccessful) && isset($repoObj->body->hasDefaultNamespaceURL)) {
        $repoNamespace = \Drupal\rep\Utils::normalizeRepositoryNamespace((string) $repoObj->body->hasDefaultNamespaceURL);
      }
    } catch (\Throwable $t) {
      // Keep fallback path below.
    }

    if ($repoNamespace === '') {
      $repoNamespace = 'https://pmsr.net/ont/';
      $progress[] = '  ⚠ Using canonical PMSR namespace fallback for URI generation';
    }

    $prefix = (string) \Drupal\rep\Utils::elementPrefix('datafile');
    if ($prefix === '') {
      $prefix = 'DFL';
    }

    $uid = (string) \Drupal::currentUser()->id();
    $suffix = (string) \Drupal::time()->getCurrentTime() . (string) random_int(10000, 99999) . $uid;
    return $repoNamespace . $prefix . $suffix;
  }

  /**
   * Build a template URI (for example INS/INF) that shares identifier with DataFile URI.
   */
  private function buildLinkedTemplateUri(string $dataFileUri, string $templateType): string {
    $base = trim($dataFileUri);
    if ($base === '') {
      return '';
    }

    $dataFilePrefix = (string) \Drupal\rep\Utils::elementPrefix('datafile');
    if ($dataFilePrefix === '') {
      $dataFilePrefix = 'DFL';
    }

    $templatePrefix = (string) \Drupal\rep\Utils::elementPrefix($templateType);
    if ($templatePrefix === '') {
      return '';
    }

    if (strpos($base, $dataFilePrefix) !== FALSE) {
      return str_replace($dataFilePrefix, $templatePrefix, $base);
    }

    return $base . '-' . $templatePrefix;
  }

  /**
   * Return compact human-readable error detail from raw API response.
   */
  private function extractApiFailureDetail($rawResponse): string {
    if ($rawResponse === NULL || $rawResponse === FALSE || $rawResponse === '') {
      return 'No response returned by API.';
    }

    $rawText = is_string($rawResponse) ? $rawResponse : json_encode($rawResponse);
    if (!is_string($rawText) || $rawText === '') {
      return 'API response could not be stringified.';
    }

    $decoded = json_decode($rawText);
    if ($decoded && isset($decoded->body)) {
      if (is_string($decoded->body)) {
        $body = trim($decoded->body);
      } else {
        $body = json_encode($decoded->body);
      }
      if (is_string($body) && $body !== '') {
        return 'API message: ' . substr($body, 0, 400);
      }
    }

    return 'Raw API response: ' . substr(preg_replace('/\s+/', ' ', $rawText), 0, 400);
  }

  /**
   * Validate INS InfoSheet keys strictly against implemented INS contract.
   */
  private function validateINSInfoSheetKeySet(string $filePath): array {
    if (!is_readable($filePath)) {
      return [
        'success' => FALSE,
        'message' => 'INS workbook is not readable: ' . $filePath,
        'warnings' => [],
      ];
    }

    $zip = new \ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
      return [
        'success' => FALSE,
        'message' => 'Unable to open INS workbook: ' . $filePath,
        'warnings' => [],
      ];
    }

    try {
      $workbookXml = $zip->getFromName('xl/workbook.xml');
      $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
      if (!is_string($workbookXml) || !is_string($relsXml)) {
        return [
          'success' => FALSE,
          'message' => 'Workbook metadata is invalid for INS file: ' . $filePath,
          'warnings' => [],
        ];
      }

      $infoSheetPath = $this->findSheetPathInWorkbook($workbookXml, $relsXml, 'InfoSheet');
      if ($infoSheetPath === NULL) {
        return [
          'success' => FALSE,
          'message' => 'INS workbook is missing InfoSheet.',
          'warnings' => [],
        ];
      }

      $sheetXml = $zip->getFromName($infoSheetPath);
      if (!is_string($sheetXml) || $sheetXml === '') {
        return [
          'success' => FALSE,
          'message' => 'INS InfoSheet is empty or unreadable.',
          'warnings' => [],
        ];
      }

      $sharedStrings = [];
      $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
      if (is_string($sharedXml) && $sharedXml !== '') {
        $sharedStrings = $this->parseSharedStrings($sharedXml);
      }

      $foundKeys = [];
      preg_replace_callback('/<row\b[^>]*r="(\d+)"[^>]*>.*?<\/row>/s', function (array $matches) use (&$foundKeys, $sharedStrings) {
        $rowNum = (int) $matches[1];
        $rowXml = $matches[0];

        if ($rowNum <= 1) {
          return $rowXml;
        }

        if (!preg_match('/<c\b[^>]*r="A\d+"[^>]*>.*?<\/c>/s', $rowXml, $cellMatch)) {
          return $rowXml;
        }

        $key = $this->extractCellText($cellMatch[0], $sharedStrings);
        if ($key !== '') {
          $foundKeys[] = $key;
        }

        return $rowXml;
      }, $sheetXml);

      $foundKeys = array_values(array_unique(array_map('trim', $foundKeys)));
      $expected = self::INS_EXPECTED_INFOSHEET_KEYS;

      $missing = array_values(array_diff($expected, $foundKeys));
      $extra = array_values(array_diff($foundKeys, $expected));

      if (!empty($missing) || !empty($extra)) {
        $parts = [];
        if (!empty($missing)) {
          $parts[] = 'missing keys: ' . implode(', ', $missing);
        }
        if (!empty($extra)) {
          $parts[] = 'unexpected keys: ' . implode(', ', $extra);
        }

        return [
          'success' => FALSE,
          'message' => 'INS InfoSheet key-set does not match INS-SPEC (' . implode(' | ', $parts) . ')',
          'warnings' => [],
        ];
      }

      return [
        'success' => TRUE,
        'message' => 'INS InfoSheet keys validated.',
        'warnings' => [],
      ];
    }
    finally {
      $zip->close();
    }
  }

  /**
   * Resolve sheet XML path by sheet name.
   */
  private function findSheetPathInWorkbook(string $workbookXml, string $relsXml, string $sheetName): ?string {
    $relMap = [];
    if (preg_match_all('/<Relationship[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/i', $relsXml, $relMatches, PREG_SET_ORDER)) {
      foreach ($relMatches as $m) {
        $relMap[$m[1]] = $m[2];
      }
    }

    if (!preg_match_all('/<sheet[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"/i', $workbookXml, $sheetMatches, PREG_SET_ORDER)) {
      return NULL;
    }

    foreach ($sheetMatches as $m) {
      if ($m[1] !== $sheetName) {
        continue;
      }

      $target = $relMap[$m[2]] ?? '';
      if ($target === '') {
        return NULL;
      }

      if (strpos($target, 'worksheets/') === 0) {
        return 'xl/' . $target;
      }

      return 'xl/worksheets/' . basename($target);
    }

    return NULL;
  }

  /**
   * Parse shared strings table into an index map.
   */
  private function parseSharedStrings(string $sharedXml): array {
    $strings = [];
    if (!preg_match_all('/<si[^>]*>(.*?)<\/si>/s', $sharedXml, $siMatches, PREG_SET_ORDER)) {
      return $strings;
    }

    foreach ($siMatches as $si) {
      $text = '';
      if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si[1], $tMatches)) {
        $text = implode('', $tMatches[1]);
      }
      $strings[] = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    return $strings;
  }

  /**
   * Extract text value from a worksheet cell XML fragment.
   */
  private function extractCellText(string $cellXml, array $sharedStrings): string {
    $cellType = '';
    if (preg_match('/\bt="([^"]+)"/', $cellXml, $typeMatch)) {
      $cellType = $typeMatch[1];
    }

    if ($cellType === 's' && preg_match('/<v>(.*?)<\/v>/s', $cellXml, $vMatch)) {
      $idx = (int) trim($vMatch[1]);
      return isset($sharedStrings[$idx]) ? trim($sharedStrings[$idx]) : '';
    }

    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $cellXml, $tMatches)) {
      return trim(html_entity_decode(implode('', $tMatches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    if (preg_match('/<v>(.*?)<\/v>/s', $cellXml, $vMatch)) {
      return trim(html_entity_decode($vMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    return '';
  }

  /**
   * Build state key for INS ingestion job tracking.
   */
  private function getINSIngestionJobStateKey(string $jobId): string {
    return 'pmsr.ins_ingestion_job.' . $jobId;
  }

  /**
   * Extract the latest [n/10] step number from progress lines.
   */
  private function extractCurrentStepFromProgress(array $progress): int {
    $step = 0;
    foreach ($progress as $line) {
      if (!is_string($line)) {
        continue;
      }
      if (preg_match('/^\[(\d+)\/10\]/', $line, $matches)) {
        $candidate = (int) $matches[1];
        if ($candidate > $step) {
          $step = $candidate;
        }
      }
    }
    if ($step < 0) {
      return 0;
    }
    if ($step > 10) {
      return 10;
    }
    return $step;
  }

}
