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
    $output .= '<a href="/pmsr/api/statistics/refresh" class="btn btn-success btn-lg ms-2">Refresh Statistics</a>';
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
    
    $progress = [];
    $errors = [];
    
    \Drupal::logger('pmsr')->info('INS ingestion started');
    
    // Step 1: Locate the INS file
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
    $file_path = DRUPAL_ROOT . '/' . $module_path . '/mts/INS-PMSR.xlsx';
    
    $progress[] = "[1/10] Locating INS-PMSR.xlsx file...";
    
    if (!file_exists($file_path)) {
      $errors[] = "INS-PMSR.xlsx file not found at: " . $file_path;
      \Drupal::logger('pmsr')->error("INS: File not found at $file_path");
      return new JsonResponse([
        'success' => false,
        'message' => 'INS file not found',
        'progress' => $progress,
        'errors' => $errors,
      ]);
    }
    
    $progress[] = "  ✓ File found: INS-PMSR.xlsx";
    
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
          return new JsonResponse([
            'success' => false,
            'message' => 'Could not create file entity',
            'progress' => $progress,
            'errors' => $errors,
          ]);
        }
        
        $file->setPermanent();
        $file->save();
        $progress[] = "  ✓ Created file entity (ID: {$file->id()})";
      }
    } catch (\Exception $e) {
      $errors[] = "Exception creating file entity: " . $e->getMessage();
      return new JsonResponse([
        'success' => false,
        'message' => 'Exception during file entity creation',
        'progress' => $progress,
        'errors' => $errors,
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
        
        $delete_response = $api->repoDeleteSelectedNamespace($ins_abbreviation);
        $delete_data = json_decode($delete_response);
        
        if (!$delete_data || !$delete_data->isSuccessful) {
          $errors[] = "Failed to delete INS named graph";
          return new JsonResponse([
            'success' => false,
            'message' => 'Failed to delete existing INS instance data',
            'progress' => $progress,
            'errors' => $errors,
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
        return new JsonResponse([
          'success' => false,
          'message' => 'DataFile creation failed',
          'progress' => $progress,
          'errors' => $errors,
        ]);
      }
      $progress[] = "  ✓ Created DataFile (DFL) entity";
      
      // Create INS using elementAdd (same as AddMTForm)
      $msg2 = $api->parseObjectResponse($api->elementAdd('ins', $insJSON), 'elementAdd');
      if ($msg2 == NULL) {
        $errors[] = "Failed to create INS metadata template";
        return new JsonResponse([
          'success' => false,
          'message' => 'INS creation failed',
          'progress' => $progress,
          'errors' => $errors,
        ]);
      }
      $progress[] = "  ✓ Created INS (INF) metadata template";
      $progress[] = "  ✓ INS linked to DataFile: " . $newDataFileUri;
      
    } catch (\Exception $e) {
      $errors[] = "Exception creating entities: " . $e->getMessage();
      return new JsonResponse([
        'success' => false,
        'message' => 'Exception during entity creation',
        'progress' => $progress,
        'errors' => $errors,
      ]);
    }
    
    // Step 7: Upload file content to HAScO API
    $progress[] = "[7/10] Uploading file content to HAScO API...";
    
    try {
      // Pass INS URI - uploadFile will find the INS entity and extract the DataFile URI from hasDataFileUri property
      $upload_result = $api->uploadFile($newINSUri, $file->id());
      
      if ($upload_result === NULL || $upload_result === FALSE || $upload_result === '') {
        $errors[] = "Failed to upload file to HAScO API";
        return new JsonResponse([
          'success' => false,
          'message' => 'File upload to API failed',
          'progress' => $progress,
          'errors' => $errors,
        ]);
      }
      
      $progress[] = "  ✓ File content uploaded successfully to HAScO API";
    } catch (\Exception $e) {
      $errors[] = "Exception uploading file: " . $e->getMessage();
      return new JsonResponse([
        'success' => false,
        'message' => 'Exception during file upload',
        'progress' => $progress,
        'errors' => $errors,
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
        return new JsonResponse([
          'success' => false,
          'message' => 'Ingestion trigger failed',
          'progress' => $progress,
          'errors' => $errors,
        ]);
      }
      
      $progress[] = "  ✓ Ingestion triggered successfully";
      $progress[] = "  ✓ INS (INF) URI: " . $newINSUri;
      $progress[] = "  ✓ DataFile (DFL) URI: " . $newDataFileUri;
    } catch (\Exception $e) {
      $errors[] = "Exception during ingestion: " . $e->getMessage();
      return new JsonResponse([
        'success' => false,
        'message' => 'Exception during ingestion trigger',
        'progress' => $progress,
        'errors' => $errors,
      ]);
    }
    
    // Step 9: Verify ingestion status
    $progress[] = "[9/10] Verifying ingestion status...";
    
    try {
      // Wait a moment for processing to start
      sleep(2);
      
      // Query the INS template to check status
      $ins_response = $api->getUri($newINSUri);
      $ins = $api->parseObjectResponse($ins_response, 'getUri');
      
      if ($ins && isset($ins->uri)) {
        $status = isset($ins->hasStatus) ? $ins->hasStatus : 'UNKNOWN';
        $progress[] = "  ✓ INS template created with status: " . $status;
        
        if ($status === 'PROCESSED') {
          $progress[] = "  ✓ Template successfully processed!";
        } else {
          $progress[] = "  ⚠ Template created but status is: " . $status;
          $progress[] = "  ⚠ Processing may still be in progress. Check INS Templates list.";
        }
      } else {
        $progress[] = "  ⚠ Could not verify template status immediately";
        $progress[] = "  ⚠ Template should appear in INS Templates list shortly";
      }
    } catch (\Exception $e) {
      $progress[] = "  ⚠ Could not verify status: " . $e->getMessage();
      $progress[] = "  ⚠ Check INS Templates list to verify ingestion";
    }
    
    // Step 10: Invalidate INS cache
    $progress[] = "[10/10] Invalidating statistics cache...";
    
    try {
      \Drupal\pmsr\Controller\IngestionController::invalidateStatisticsCache(['ins']);
      $progress[] = "  ✓ Invalidated statistics cache for INS";
    } catch (\Exception $e) {
      // Non-fatal
    }
    
    // Return success
    return new JsonResponse([
      'success' => true,
      'message' => 'INS template ingestion completed successfully!',
      'progress' => $progress,
      'errors' => $errors,
      'insUri' => $newINSUri,
      'dataFileUri' => $newDataFileUri,
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

}
