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
    $output .= '<h4>KGR People</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<p>This will process KGR (Knowledge Graph Repository) people templates including:</p>';
    $output .= '<ul>';
    $output .= '<li>Person profiles with names and identities</li>';
    $output .= '<li>Organization memberships (foaf:member)</li>';
    $output .= '<li>Email addresses (foaf:mbox)</li>';
    $output .= '</ul>';
    $output .= '<p class="mt-3">After KGR-PEOPLE.xlsx ingestion, DP2-PMSR.xlsx will be ingested using generic MT ingestion endpoints with type <code>dp2</code>.</p>';
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
   * Process KGR people and DP2-PMSR ingestion.
   * 
   * Ingests:
   * 1. KGR-PEOPLE.xlsx - Person profiles, memberships, and emails
   * 2. DP2-PMSR.xlsx - Instrument instances, platforms, and deployments
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
    
    $progress[] = "Starting KGR People Ingestion Process...";
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
                  $error_msg = isset($delete_df_response->message) ? $delete_df_response->message : 'DataFile may already be deleted or named graph no longer exists';
                  $progress[] = "    ⚠ Could not delete DataFile: " . $error_msg . " (non-critical - will proceed with ingestion)";
                  \Drupal::logger('pmsr')->info("KGR: DataFile delete skipped: " . $error_msg . " for URI: " . $existing_datafile_uri);
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
                
                // Retrieve ingestion log to show verification results
                $dataFile = $api->parseObjectResponse($api->getUri($newDataFileUri), 'getUri');
                if ($dataFile && isset($dataFile->log)) {
                  // Parse log to find verification messages
                  $log_lines = explode('<br>', $dataFile->log);
                  foreach ($log_lines as $log_line) {
                    // Look for PersonGenerator verification messages
                    if (strpos($log_line, '[PersonGenerator] Ingestion verification:') !== false ||
                        strpos($log_line, '[SUCCESS] PersonGenerator:') !== false ||
                        strpos($log_line, '[WARNING] PersonGenerator:') !== false ||
                        strpos($log_line, '[INFO] PersonGenerator:') !== false) {
                      // Remove timestamp prefix (format: YYYY-MM-DD HH:MM:SS)
                      $cleaned_line = preg_replace('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[LOG\] /', '', $log_line);
                      $progress[] = "    → " . trim($cleaned_line);
                    }
                  }
                }
                
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
    
    // ========================================================================
    // PART 2: DP2-PMSR.xlsx INGESTION (after KGR-PEOPLE.xlsx)
    // ========================================================================
    
    $progress[] = "";
    $progress[] = "=== Ingesting DP2-PMSR Data ===";
    $progress[] = "";
    
    $dp2File = 'DP2-PMSR.xlsx';
    $dp2_success_count = 0;
    $dp2_error_count = 0;
    
    // Only proceed with DP2 if KGR succeeded
    if ($kgr_success_count > 0) {
      $progress[] = "[Step 1/3] Processing $dp2File...";
      $progress[] = "";
      
      $filename = $dp2File;
      $dp2_label = str_replace('.xlsx', '', $filename);
      $filePath = $mts_dir . '/' . $filename;
      
      if (!file_exists($filePath)) {
        $errors[] = "File not found: $filename at $filePath";
        $progress[] = "  ✗ File not found: $filePath";
        $dp2_error_count++;
      } else {
        $filesize = filesize($filePath);
        $progress[] = "  ✓ Found $filename (" . round($filesize / 1024, 2) . " KB)";
        
        try {
          // STEP 0: Delete existing DataFile and DP2 entity if they exist
          $progress[] = "";
          $progress[] = "[Step 2/3] Checking for existing $dp2_label...";
          
          try {
            // Search for existing DP2 with matching label
            $existing_dp2_response = $api->listByKeyword('dp2', $dp2_label, 100, 0);
            $existing_dp2_data = json_decode($existing_dp2_response);
            
            if ($existing_dp2_data && isset($existing_dp2_data->body) && is_array($existing_dp2_data->body) && count($existing_dp2_data->body) > 0) {
              foreach ($existing_dp2_data->body as $existing_dp2) {
                if (isset($existing_dp2->uri) && isset($existing_dp2->label) && $existing_dp2->label === $dp2_label) {
                  $progress[] = "  → Found existing DP2: " . $existing_dp2->uri;
                  \Drupal::logger('pmsr')->info("DP2: Found existing DP2 to delete: " . $existing_dp2->uri);
                  
                  // Get the DataFile URI
                  $existing_datafile_uri = isset($existing_dp2->hasDataFileUri) ? $existing_dp2->hasDataFileUri : null;
                  
                  // Delete the associated DataFile FIRST
                  if ($existing_datafile_uri) {
                    $progress[] = "  → Deleting DataFile and its RDF data: " . $existing_datafile_uri;
                    $delete_df_result = $api->datafileDel($existing_datafile_uri);
                    $delete_df_response = json_decode($delete_df_result);
                    if ($delete_df_response && isset($delete_df_response->isSuccessful) && $delete_df_response->isSuccessful) {
                      $progress[] = "    ✓ Deleted DataFile and all ingested RDF triples";
                      \Drupal::logger('pmsr')->info("DP2: Deleted DataFile and RDF data: " . $existing_datafile_uri);
                    } else {
                      $error_msg = isset($delete_df_response->message) ? $delete_df_response->message : 'DataFile may already be deleted or named graph no longer exists';
                      $progress[] = "    ⚠ Could not delete DataFile: " . $error_msg . " (non-critical - will proceed with ingestion)";
                      \Drupal::logger('pmsr')->info("DP2: DataFile delete skipped: " . $error_msg . " for URI: " . $existing_datafile_uri);
                    }
                  }
                  
                  // Then delete the DP2 entity metadata
                  $delete_dp2_result = $api->elementDel('dp2', $existing_dp2->uri);
                  $delete_dp2_response = json_decode($delete_dp2_result);
                  if ($delete_dp2_response && isset($delete_dp2_response->isSuccessful) && $delete_dp2_response->isSuccessful) {
                    $progress[] = "    ✓ Deleted DP2 metadata entity";
                    \Drupal::logger('pmsr')->info("DP2: Deleted DP2 entity: " . $existing_dp2->uri);
                  } else {
                    $error_msg = isset($delete_dp2_response->message) ? $delete_dp2_response->message : 'Unknown error';
                    $progress[] = "    ⚠ Could not delete DP2 entity: " . $error_msg;
                  }
                }
              }
            } else {
              $progress[] = "  ✓ No existing DP2 found (fresh ingestion)";
            }
          } catch (\Exception $e) {
            $progress[] = "  ⚠ Error checking for existing DP2: " . $e->getMessage();
            \Drupal::logger('pmsr')->warning("DP2: Error checking for existing DP2: " . $e->getMessage());
          }
          
          // Create temporary location for file
          $progress[] = "";
          $progress[] = "[Step 3/3] Uploading and ingesting $filename...";
          
          $destination = 'public://dp2/' . $filename;
          $directory = dirname($destination);
          \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
          
          $file_content = file_get_contents($filePath);
          file_put_contents(\Drupal::service('file_system')->realpath($destination), $file_content);
          
          // Create Drupal managed file
          $file_entity = \Drupal\file\Entity\File::create([
            'uri' => $destination,
            'status' => 1,
            'filename' => $filename,
          ]);
          $file_entity->setPermanent();
          $file_entity->save();
          \Drupal::logger('pmsr')->info("DP2: Created Drupal file entity for $filename (ID: " . $file_entity->id() . ")");
          
          // Generate URIs for DataFile and DP2
          $newDataFileUri = \Drupal\rep\Utils::uriGen('datafile');
          $newDP2Uri = str_replace("DFL", \Drupal\rep\Utils::elementPrefix('dp2'), $newDataFileUri);
          \Drupal::logger('pmsr')->info("DP2: Generated URIs - DFL: $newDataFileUri, DP2: $newDP2Uri");
          
          $useremail = \Drupal::currentUser()->getEmail();
          
          // Create DataFile
          $datafileJSON = json_encode([
            "uri" => $newDataFileUri,
            "typeUri" => \Drupal\rep\Vocabulary\HASCO::DATAFILE,
            "hascoTypeUri" => \Drupal\rep\Vocabulary\HASCO::DATAFILE,
            "label" => $dp2_label,
            "filename" => $filename,
            "fileStatus" => \Drupal\rep\Constant::FILE_STATUS_UNPROCESSED,
            "hasSIRManagerEmail" => $useremail,
          ]);
          
          $msg1 = $api->parseObjectResponse($api->datafileAdd($datafileJSON), 'datafileAdd');
          if ($msg1 == NULL) {
            $errors[] = "Failed to create DataFile for $filename";
            $progress[] = "  ✗ DataFile creation failed";
            \Drupal::logger('pmsr')->error("DP2: DataFile creation failed for $filename");
            $dp2_error_count++;
          } else {
            \Drupal::logger('pmsr')->info("DP2: DataFile created successfully for $filename");
            
            // Create DP2 entity
            $dp2JSON = json_encode([
              "uri" => $newDP2Uri,
              "typeUri" => "http://hadatac.org/ont/hasco/DP2",
              "hascoTypeUri" => "http://hadatac.org/ont/hasco/DP2",
              "label" => $dp2_label,
              "hasDataFileUri" => $newDataFileUri,
              "hasSIRManagerEmail" => $useremail,
            ]);
            
            $msg2 = $api->parseObjectResponse($api->elementAdd('dp2', $dp2JSON), 'elementAdd');
            if ($msg2 == NULL) {
              $errors[] = "Failed to create DP2 entity for $filename";
              $progress[] = "  ✗ DP2 entity creation failed";
              \Drupal::logger('pmsr')->error("DP2: DP2 entity creation failed for $filename");
              $dp2_error_count++;
            } else {
              \Drupal::logger('pmsr')->info("DP2: DP2 entity created successfully for $filename");
              
              // Upload file content
              $upload_result = $api->uploadFile($newDP2Uri, $file_entity->id());
              if ($upload_result === false || $upload_result === NULL) {
                $errors[] = "Failed to upload file content for $filename";
                $progress[] = "  ✗ File upload failed";
                \Drupal::logger('pmsr')->error("DP2: File upload failed for $filename");
                $dp2_error_count++;
              } else {
                \Drupal::logger('pmsr')->info("DP2: File uploaded successfully for $filename");
                
                // Trigger ingestion using 'dp2' type
                $template = new \stdClass();
                $template->uri = $newDP2Uri;
                $template->hasDataFileUri = $newDataFileUri;
                $template->hasDataFile = new \stdClass();
                $template->hasDataFile->id = $file_entity->id();
                $template->hasDataFile->filename = $filename;
                
                $ingest_result = $api->uploadTemplate('dp2', $template, '_');
                
                if ($ingest_result === NULL || $ingest_result === FALSE || $ingest_result === '') {
                  $errors[] = "Ingestion failed for $filename: No response from API";
                  $progress[] = "  ✗ Ingestion trigger failed: No response";
                  $dp2_error_count++;
                } else {
                  $template_data = json_decode($ingest_result);
                  
                  if (!$template_data) {
                    $errors[] = "Ingestion failed for $filename: Invalid JSON response";
                    $progress[] = "  ✗ Ingestion trigger failed: Invalid response";
                    $progress[] = "    → Raw response: " . substr($ingest_result, 0, 200);
                    $dp2_error_count++;
                  } else if (!isset($template_data->isSuccessful) || !$template_data->isSuccessful) {
                    $error_msg = isset($template_data->message) ? $template_data->message : 'No error message provided';
                    $errors[] = "Ingestion failed for $filename: $error_msg";
                    $progress[] = "  ✗ Ingestion trigger failed: $error_msg";
                    \Drupal::logger('pmsr')->error("DP2: Ingestion trigger failed for $filename: $error_msg");
                    if (isset($template_data->body)) {
                      $body_preview = substr(json_encode($template_data->body), 0, 200);
                      $progress[] = "    → Response body: " . $body_preview;
                      \Drupal::logger('pmsr')->error("DP2: Response body: $body_preview");
                    }
                    $dp2_error_count++;
                  } else {
                    $progress[] = "  ✓ $filename ingested successfully";
                    $progress[] = "    → Instrument instances, platforms, and deployments loaded";
                    \Drupal::logger('pmsr')->info("DP2: Successfully ingested $filename");
                    $dp2_success_count++;
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
          \Drupal::logger('pmsr')->error("DP2: Exception processing $filename: $error_detail");
          \Drupal::logger('pmsr')->error("DP2: Stack trace: " . $e->getTraceAsString());
          $dp2_error_count++;
        }
      }
    } else {
      $progress[] = "⚠ Skipping DP2-PMSR.xlsx ingestion because KGR-PEOPLE.xlsx failed";
    }
    
    $progress[] = "";
    $progress[] = "=== People Ingestion Complete ===";
    $progress[] = "";
    
    if ($kgr_success_count > 0) {
      $progress[] = "✓ KGR-PEOPLE.xlsx ingested successfully";
      $progress[] = "✓ Person profiles, organization memberships, and emails loaded";
    }
    
    if ($dp2_success_count > 0) {
      $progress[] = "✓ DP2-PMSR.xlsx ingested successfully";
      $progress[] = "✓ Instrument instances, platforms, and deployments loaded";
    }
    
    if ($kgr_error_count > 0 || $dp2_error_count > 0) {
      $progress[] = "⚠ Ingestion completed with some errors (see above)";
    }
    
    // Final log
    \Drupal::logger('pmsr')->info("People ingestion completed - KGR Success: $kgr_success_count, KGR Errors: $kgr_error_count, DP2 Success: $dp2_success_count, DP2 Errors: $dp2_error_count");
    
    // Invalidate cached people lists
    \Drupal\Core\Cache\Cache::invalidateTags(['kgr_people', 'dp2_pmsr']);
    \Drupal::logger('pmsr')->info("Cleared cached data tagged with 'kgr_people' and 'dp2_pmsr'");
    $progress[] = "✓ Cleared cached data to reflect new information";
    
    $total_errors = $kgr_error_count + $dp2_error_count;
    
    return new JsonResponse([
      'success' => $total_errors == 0,
      'message' => $total_errors == 0 ? 'People and DP2 ingestion completed successfully' : 'Ingestion completed with some errors',
      'progress' => $progress,
      'errors' => $errors,
      'stats' => [
        'kgr_success' => $kgr_success_count,
        'kgr_failed' => $kgr_error_count,
        'dp2_success' => $dp2_success_count,
        'dp2_failed' => $dp2_error_count,
        'total_errors' => count($errors),
      ],
    ]);
  }

}
