<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for KGR People ingestion operations.
 */
class IngestionKgrPeopleController extends ControllerBase {

  /**
   * Ingest KGR people data.
   */
  public function ingestPeople() {
    // Generate CSRF token for GUI-only access control
    $csrf_token = \Drupal::csrfToken()->get('kgr_people_ingestion');
    
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest people data from KGR templates.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>KRG People</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<p>This will process KRG (Knowledge Representation for Geography) people templates including:</p>';
    $output .= '<ul>';
    $output .= '<li>Person profiles with names and identities</li>';
    $output .= '<li>Organization memberships (foaf:member)</li>';
    $output .= '<li>Email addresses (foaf:mbox)</li>';
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
              'endpoint' => '/pmsr/api/ingest/people/process',
              'message' => 'Ingesting people data...',
              'token' => $csrf_token,
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Process KRG people ingestion.
   * 
   * Ingests KGR-PEOPLE.xlsx file with person data.
   */
  public function processPeopleIngestion(Request $request) {
    // Increase execution time limit for long-running ingestion (5 minutes)
    set_time_limit(300);
    
    $progress = [];
    $errors = [];
    
    // SECURITY: Validate CSRF token to prevent programmatic calls
    $requestData = json_decode($request->getContent(), true);
    $provided_token = isset($requestData['token']) ? $requestData['token'] : null;
    
    // Validate CSRF token
    if (!$provided_token || !\Drupal::csrfToken()->validate($provided_token, 'kgr_people_ingestion')) {
      \Drupal::logger('pmsr')->error('KGR People ingestion blocked: Invalid or missing CSRF token');
      return new JsonResponse([
        'success' => false,
        'message' => '🔒 Security Error: This endpoint can only be called from the GUI interface.',
        'errors' => ['Invalid or missing CSRF token. Please use the GUI to start ingestion.'],
      ]);
    }
    
    // Log start of ingestion
    \Drupal::logger('pmsr')->info('KGR People ingestion started (CSRF token validated)');
    
    $progress[] = "Starting KRG People Ingestion Process...";
    $progress[] = "";
    
    // Define KGR file to ingest
    $kgrFile = 'KGR-PEOPLE.xlsx';
    
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
    $mts_dir = DRUPAL_ROOT . '/' . $module_path . '/mts';
    
    $progress[] = "=== Ingesting People Data ===";
    $progress[] = "";
    
    // Initialize counters
    $kgr_success_count = 0;
    $kgr_error_count = 0;
    
    $api = \Drupal::service('rep.api_connector');
    
    // Process the KGR file
    $progress[] = "[Step 1/3] Processing $kgrFile...";
    $progress[] = "";
    
    $filename = $kgrFile;
    $kgr_label = str_replace('.xlsx', '', $filename);
    $filePath = $mts_dir . '/' . $filename;
    
    if (!file_exists($filePath)) {
      $errors[] = "File not found: $filename at $filePath";
      $progress[] = "  ✗ File not found: $filePath";
      $kgr_error_count++;
      
      return new JsonResponse([
        'success' => false,
        'message' => 'Required KGR file not found',
        'progress' => $progress,
        'errors' => $errors,
      ]);
    }
    
    $filesize = filesize($filePath);
    $progress[] = "  ✓ Found $filename (" . round($filesize / 1024, 2) . " KB)";
    
    try {
      // STEP 0: Delete existing DataFile and KGR entity if they exist
      $progress[] = "";
      $progress[] = "[Step 2/3] Checking for existing $kgr_label...";
      
      try {
        // Search for existing KGR with matching label (filename without extension)
        // Use listByKeyword to find KGRs matching this filename
        $existing_kgr_response = $api->listByKeyword('kgr', $kgr_label, 100, 0);
        $existing_kgr_data = json_decode($existing_kgr_response);
        
        if ($existing_kgr_data && isset($existing_kgr_data->body) && is_array($existing_kgr_data->body) && count($existing_kgr_data->body) > 0) {
          foreach ($existing_kgr_data->body as $existing_kgr) {
            // Check if label matches exactly to avoid false positives
            if (isset($existing_kgr->uri) && isset($existing_kgr->label) && $existing_kgr->label === $kgr_label) {
              $progress[] = "  → Found existing KGR: " . $existing_kgr->uri;
              \Drupal::logger('pmsr')->info("KGR: Found existing KGR to delete: " . $existing_kgr->uri);
              
              // Get the DataFile URI
              $existing_datafile_uri = isset($existing_kgr->hasDataFileUri) ? $existing_kgr->hasDataFileUri : null;
              
              // Delete the associated DataFile FIRST (this should delete the named graph with all RDF data)
              if ($existing_datafile_uri) {
                $progress[] = "  → Deleting DataFile and its RDF data: " . $existing_datafile_uri;
                $delete_df_result = $api->datafileDel($existing_datafile_uri);
                $delete_df_response = json_decode($delete_df_result);
                if ($delete_df_response && isset($delete_df_response->isSuccessful) && $delete_df_response->isSuccessful) {
                  $progress[] = "    ✓ Deleted DataFile and all ingested RDF triples";
                  \Drupal::logger('pmsr')->info("KGR: Deleted DataFile and RDF data: " . $existing_datafile_uri);
                } else {
                  $error_msg = isset($delete_df_response->message) ? $delete_df_response->message : 'Unknown error';
                  $progress[] = "    ⚠ Could not delete DataFile: " . $error_msg;
                  \Drupal::logger('pmsr')->warning("KGR: Could not delete DataFile: " . $error_msg);
                }
              }
              
              // Then delete the KGR entity metadata
              $delete_kgr_result = $api->elementDel('kgr', $existing_kgr->uri);
              $delete_kgr_response = json_decode($delete_kgr_result);
              if ($delete_kgr_response && isset($delete_kgr_response->isSuccessful) && $delete_kgr_response->isSuccessful) {
                $progress[] = "    ✓ Deleted KGR metadata entity";
                \Drupal::logger('pmsr')->info("KGR: Deleted KGR entity: " . $existing_kgr->uri);
              } else {
                $error_msg = isset($delete_kgr_response->message) ? $delete_kgr_response->message : 'Unknown error';
                $progress[] = "    ⚠ Could not delete KGR entity: " . $error_msg;
              }
            }
          }
        } else {
          $progress[] = "  ✓ No existing KGR found (fresh ingestion)";
        }
      } catch (\Exception $e) {
        $progress[] = "  ⚠ Error checking for existing KGR: " . $e->getMessage();
        \Drupal::logger('pmsr')->warning("KGR: Error checking for existing KGR: " . $e->getMessage());
        // Continue with ingestion even if deletion check fails
      }
      
      // Create temporary location for file
      $progress[] = "";
      $progress[] = "[Step 3/3] Uploading and ingesting $filename...";
      
      $destination = 'public://kgr/' . $filename;
      $directory = dirname($destination);
      \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
      
      $file_content = file_get_contents($filePath);
      file_put_contents(\Drupal::service('file_system')->realpath($destination), $file_content);
      
      // Create Drupal managed file with correct filename
      $file_entity = \Drupal\file\Entity\File::create([
        'uri' => $destination,
        'status' => 1,
        'filename' => $filename,
      ]);
      $file_entity->setPermanent();
      $file_entity->save();
      \Drupal::logger('pmsr')->info("KGR: Created Drupal file entity for $filename (ID: " . $file_entity->id() . ")");
      
      // Generate URIs for DataFile and KGR
      $newDataFileUri = \Drupal\rep\Utils::uriGen('datafile');
      $newKGRUri = str_replace("DFL", \Drupal\rep\Utils::elementPrefix('kgr'), $newDataFileUri);
      \Drupal::logger('pmsr')->info("KGR: Generated URIs - DFL: $newDataFileUri, KGR: $newKGRUri");
      
      $useremail = \Drupal::currentUser()->getEmail();
      
      // Create DataFile
      $datafileJSON = json_encode([
        "uri" => $newDataFileUri,
        "typeUri" => \Drupal\rep\Vocabulary\HASCO::DATAFILE,
        "hascoTypeUri" => \Drupal\rep\Vocabulary\HASCO::DATAFILE,
        "label" => $kgr_label,
        "filename" => $filename,
        "fileStatus" => \Drupal\rep\Constant::FILE_STATUS_UNPROCESSED,
        "hasSIRManagerEmail" => $useremail,
      ]);
      
      $msg1 = $api->parseObjectResponse($api->datafileAdd($datafileJSON), 'datafileAdd');
      if ($msg1 == NULL) {
        $errors[] = "Failed to create DataFile for $filename";
        $progress[] = "  ✗ DataFile creation failed";
        \Drupal::logger('pmsr')->error("KGR: DataFile creation failed for $filename");
        $kgr_error_count++;
      } else {
        \Drupal::logger('pmsr')->info("KGR: DataFile created successfully for $filename");
        
        // Create KGR entity
        $kgrJSON = json_encode([
          "uri" => $newKGRUri,
          "typeUri" => "http://hadatac.org/ont/hasco/KGR",
          "hascoTypeUri" => "http://hadatac.org/ont/hasco/KGR",
          "label" => $kgr_label,
          "hasDataFileUri" => $newDataFileUri,
          "hasSIRManagerEmail" => $useremail,
        ]);
        
        $msg2 = $api->parseObjectResponse($api->elementAdd('kgr', $kgrJSON), 'elementAdd');
        if ($msg2 == NULL) {
          $errors[] = "Failed to create KGR entity for $filename";
          $progress[] = "  ✗ KGR entity creation failed";
          \Drupal::logger('pmsr')->error("KGR: KGR entity creation failed for $filename");
          $kgr_error_count++;
        } else {
          \Drupal::logger('pmsr')->info("KGR: KGR entity created successfully for $filename");
          
          // Upload file content
          $upload_result = $api->uploadFile($newKGRUri, $file_entity->id());
          if ($upload_result === false || $upload_result === NULL) {
            $errors[] = "Failed to upload file content for $filename";
            $progress[] = "  ✗ File upload failed";
            \Drupal::logger('pmsr')->error("KGR: File upload failed for $filename");
            $kgr_error_count++;
          } else {
            \Drupal::logger('pmsr')->info("KGR: File uploaded successfully for $filename");
            
            // Trigger ingestion
            $template = new \stdClass();
            $template->uri = $newKGRUri;
            $template->hasDataFileUri = $newDataFileUri;
            $template->hasDataFile = new \stdClass();
            $template->hasDataFile->id = $file_entity->id();
            $template->hasDataFile->filename = $filename;
            
            $ingest_result = $api->uploadTemplate('kgr', $template, '_');
            
            if ($ingest_result === NULL || $ingest_result === FALSE || $ingest_result === '') {
              $errors[] = "Ingestion failed for $filename: No response from API";
              $progress[] = "  ✗ Ingestion trigger failed: No response";
              $kgr_error_count++;
            } else {
              $template_data = json_decode($ingest_result);
              
              if (!$template_data) {
                $errors[] = "Ingestion failed for $filename: Invalid JSON response";
                $progress[] = "  ✗ Ingestion trigger failed: Invalid response";
                $progress[] = "    → Raw response: " . substr($ingest_result, 0, 200);
                $kgr_error_count++;
              } else if (!isset($template_data->isSuccessful) || !$template_data->isSuccessful) {
                $error_msg = isset($template_data->message) ? $template_data->message : 'No error message provided';
                $errors[] = "Ingestion failed for $filename: $error_msg";
                $progress[] = "  ✗ Ingestion trigger failed: $error_msg";
                \Drupal::logger('pmsr')->error("KGR: Ingestion trigger failed for $filename: $error_msg");
                if (isset($template_data->body)) {
                  $body_preview = substr(json_encode($template_data->body), 0, 200);
                  $progress[] = "    → Response body: " . $body_preview;
                  \Drupal::logger('pmsr')->error("KGR: Response body: $body_preview");
                }
                $kgr_error_count++;
              } else {
                $progress[] = "  ✓ $filename ingested successfully";
                $progress[] = "    → Persons, memberships, and emails loaded into knowledge graph";
                \Drupal::logger('pmsr')->info("KGR: Successfully ingested $filename");
                $kgr_success_count++;
              }
            }
          }
        }
      }
      
      // Delete temporary Drupal file
      $file_entity->delete();
      
    } catch (\Exception $e) {
      $error_detail = $e->getMessage() . " (File: " . $e->getFile() . " Line: " . $e->getLine() . ")";
      $errors[] = "Exception processing $filename: " . $error_detail;
      $progress[] = "  ✗ Exception: " . $e->getMessage();
      $progress[] = "    → Details: " . $e->getFile() . " line " . $e->getLine();
      \Drupal::logger('pmsr')->error("KGR: Exception processing $filename: $error_detail");
      \Drupal::logger('pmsr')->error("KGR: Stack trace: " . $e->getTraceAsString());
      $kgr_error_count++;
    }
    
    $progress[] = "";
    $progress[] = "=== People Ingestion Complete ===";
    $progress[] = "";
    
    if ($kgr_success_count > 0) {
      $progress[] = "✓ KGR-PEOPLE.xlsx ingested successfully";
      $progress[] = "✓ Person profiles, organization memberships, and emails loaded";
    }
    
    if ($kgr_error_count > 0) {
      $progress[] = "⚠ Ingestion failed (see errors above)";
    }
    
    // Final log
    \Drupal::logger('pmsr')->info("KGR: People ingestion completed - Success: $kgr_success_count, Errors: $kgr_error_count");
    
    // Invalidate cached people lists
    \Drupal\Core\Cache\Cache::invalidateTags(['kgr_people']);
    \Drupal::logger('pmsr')->info("KGR: Cleared cached people data tagged with 'kgr_people'");
    $progress[] = "✓ Cleared cached people data to reflect new information";
    
    return new JsonResponse([
      'success' => $kgr_error_count == 0,
      'message' => $kgr_error_count == 0 ? 'People ingestion completed successfully' : 'People ingestion completed with some errors',
      'progress' => $progress,
      'errors' => $errors,
      'stats' => [
        'kgr_success' => $kgr_success_count,
        'kgr_failed' => $kgr_error_count,
        'total_errors' => count($errors),
      ],
    ]);
  }

}
