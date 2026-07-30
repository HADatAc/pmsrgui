<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\file\Entity\File;
use Drupal\rep\Vocabulary\VSTOI;

/**
 * Controller for INS (Instrument) ingestion operations.
 */
class IngestionINSController extends ControllerBase {

  /**
   * Display the INS ingestion page.
   */
  public function ingestInstruments() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest INS (Instrument Specification) templates from the INS-PMSR.xlsx file into the system.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>INS Template to be Ingested</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<ul>';
    $output .= '<li><strong>File:</strong> INS-PMSR.xlsx</li>';
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
   * 1. Locates the INS-PMSR.xlsx file in the mts/ directory
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
    $file_path = DRUPAL_ROOT . '/' . $module_path . '/mts/INS-PMSR.xlsx';
    
    $progress[] = "[1/10] Locating INS-PMSR.xlsx file...";
    
    if (!file_exists($file_path)) {
      $errors[] = "INS-PMSR.xlsx file not found at: " . $file_path;
      \Drupal::logger('pmsr')->error("INS: File not found at $file_path");
      return $this->insIngestionResponse(false, 'INS file not found', $progress, $errors, $jobId);
    }
    
    $progress[] = "  ✓ File found: INS-PMSR.xlsx";
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
      // Check if file already exists
      $existing_file = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['filename' => 'INS-PMSR.xlsx']);
      
      if (!empty($existing_file)) {
        $file = reset($existing_file);
        $progress[] = "  ✓ Using existing file entity (ID: {$file->id()})";
      } else {
        // Copy file to public directory
        $destination = 'public://mts/INS-PMSR.xlsx';
        $directory = dirname($destination);
        \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
        
        $file_contents = file_get_contents($file_path);
        $file = \Drupal::service('file.repository')->writeData($file_contents, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
        
        if (!$file) {
          $errors[] = "Failed to create file entity";
          return $this->insIngestionResponse(false, 'Could not create file entity', $progress, $errors, $jobId);
        }
        
        $file->setPermanent();
        $file->save();
        $progress[] = "  ✓ Created file entity (ID: {$file->id()})";
      }
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
    
    // Generate DataFile URI (DFL prefix)
    $newDataFileUri = \Drupal\rep\Utils::uriGen('datafile');
    
    // Generate INS URI by replacing DFL with INF prefix
    $newINSUri = str_replace("DFL", \Drupal\rep\Utils::elementPrefix('ins'), $newDataFileUri);
    
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
    
    // Step 5: Check for and delete existing INS-PMSR metadata templates
    $progress[] = "[5/10] Checking for existing INS-PMSR metadata templates...";
    
    try {
      $useremail = \Drupal::currentUser()->getEmail();
      
      // Get list of INS templates managed by current user
      $response = $api->listByManagerEmail('ins', $useremail, 100, 0);
      $data = json_decode($response);
      
      $existingINS = [];
      if ($data && $data->isSuccessful && !empty($data->body)) {
        foreach ($data->body as $ins) {
          // Find INS-PMSR templates (check label or filename)
          if (isset($ins->label) && $ins->label === 'INS-PMSR') {
            $existingINS[] = [
              'uri' => $ins->uri,
              'label' => $ins->label,
              'dataFileUri' => $ins->hasDataFile->uri ?? null,
            ];
            $progress[] = "  → Found existing INS-PMSR: " . $ins->uri;
          }
        }
      }
      
      // Delete existing INS-PMSR templates (which also deletes their DataFile graphs)
      if (!empty($existingINS)) {
        $progress[] = "  → Deleting " . count($existingINS) . " existing INS-PMSR template(s)...";
        
        foreach ($existingINS as $ins) {
          try {
            $uningest_response = $api->uningestMT($ins['uri']);
            $uningest_data = json_decode($uningest_response);
            
            if ($uningest_data && $uningest_data->isSuccessful) {
              $progress[] = "    ✓ Deleted INS template and DataFile graph: " . $ins['uri'];
            } else {
              $progress[] = "    ⚠ Failed to delete " . $ins['uri'] . ": " . ($uningest_data->message ?? 'Unknown error');
            }
          } catch (\Exception $e) {
            $progress[] = "    ⚠ Exception deleting " . $ins['uri'] . ": " . $e->getMessage();
          }
        }
        
        $progress[] = "  ✓ Cleaned up existing INS-PMSR data (rerun-safe)";
      } else {
        $progress[] = "  ✓ No existing INS-PMSR templates found (first run)";
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
        "label" => 'INS-PMSR',
        "filename" => 'INS-PMSR.xlsx',
        "fileStatus" => \Drupal\rep\Constant::FILE_STATUS_UNPROCESSED,
        "hasSIRManagerEmail" => $useremail,
        "id" => $file->id(),
      ]);
      
      // Build INS JSON (following AddMTForm pattern)
      $insJSON = json_encode([
        "uri" => $newINSUri,
        "typeUri" => \Drupal\rep\Vocabulary\HASCO::INS,
        "hascoTypeUri" => \Drupal\rep\Vocabulary\HASCO::INS,
        "label" => 'INS-PMSR',
        "hasDataFileUri" => $newDataFileUri,
        "hasVersion" => '1.0',
        "comment" => 'INS metadata template for PMSR simulator models',
        "hasSIRManagerEmail" => $useremail,
      ]);
      
      // Create DataFile first (same as AddMTForm)
      $msg1 = $api->parseObjectResponse($api->datafileAdd($datafileJSON), 'datafileAdd');
      if ($msg1 == NULL) {
        $errors[] = "Failed to create DataFile (DFL) entity";
        return $this->insIngestionResponse(false, 'DataFile creation failed', $progress, $errors, $jobId, [
          'insUri' => $newINSUri,
          'dataFileUri' => $newDataFileUri,
        ]);
      }
      $progress[] = "  ✓ Created DataFile (DFL) entity";
      
      // Create INS using elementAdd (same as AddMTForm)
      $msg2 = $api->parseObjectResponse($api->elementAdd('ins', $insJSON), 'elementAdd');
      if ($msg2 == NULL) {
        $errors[] = "Failed to create INS metadata template";
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
      $template->hasDataFile->filename = 'INS-PMSR.xlsx';
      
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
      
      // Find the INS template (there should only be one INS-PMSR)
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
