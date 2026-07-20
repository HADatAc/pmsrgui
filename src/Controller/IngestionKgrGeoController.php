<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for KGR Geography and Organizations ingestion operations.
 */
class IngestionKgrGeoController extends ControllerBase {

  /**
   * Ingest KGR geography and organizations.
   */
  public function ingestGeography() {
    // Generate CSRF token for GUI-only access control
    $csrf_token = \Drupal::csrfToken()->get('kgr_geography_ingestion');
    
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest geography data and organizational structures from KGR templates.</p>';
    
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
    $output .= '<div class="alert alert-info mt-3">';
    $output .= '<strong>💡 Clean Deployment Option:</strong><br>';
    $output .= 'Use the "Clear Existing Images" checkbox below to delete all existing images before uploading new ones. ';
    $output .= 'Without this option, new images will overwrite same-name files, but old files not in the new zips will remain.';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<div class="form-check mb-3">';
    $output .= '<input class="form-check-input" type="checkbox" id="clearExistingImages" name="clearExistingImages">';
    $output .= '<label class="form-check-label" for="clearExistingImages">';
    $output .= '<strong>Clear Existing Images</strong> - Delete all files in the kgr media folder before uploading';
    $output .= '</label>';
    $output .= '</div>';
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
              'endpoint' => '/pmsr/api/ingest/geography/process',
              'message' => 'Ingesting geography data and images...',
              'token' => $csrf_token,
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Process KRG geography ingestion.
   * 
   * Phase 1: Upload 5 zip files containing entity images to HAScO API.
   * Phase 2: Ingest 10 KGR files sequentially in specific order.
   */
  public function processGeographyIngestion(Request $request) {
    // Increase execution time limit for long-running ingestion (5 minutes)
    set_time_limit(300);
    
    $progress = [];
    $errors = [];
    
    // SECURITY: Validate CSRF token to prevent programmatic calls
    $requestData = json_decode($request->getContent(), true);
    $provided_token = isset($requestData['token']) ? $requestData['token'] : null;
    
    // Validate CSRF token
    if (!$provided_token || !\Drupal::csrfToken()->validate($provided_token, 'kgr_geography_ingestion')) {
      \Drupal::logger('pmsr')->error('KGR Geography ingestion blocked: Invalid or missing CSRF token');
      return new JsonResponse([
        'success' => false,
        'message' => '🔒 Security Error: This endpoint can only be called from the GUI interface.',
        'errors' => ['Invalid or missing CSRF token. Please use the GUI to start ingestion.'],
      ]);
    }
    
    // Log start of ingestion
    \Drupal::logger('pmsr')->info('KGR Geography ingestion started (CSRF token validated)');
    
    // Get clearExistingImages parameter from request
    $clearExistingImages = isset($requestData['clearExistingImages']) ? $requestData['clearExistingImages'] : false;
    
    $progress[] = "Starting KRG Geography & Organizations Ingestion Process...";
    $progress[] = "";
    
    if ($clearExistingImages) {
      $progress[] = "🗑️  Clean Deployment Mode: Will delete existing images before uploading";
    } else {
      $progress[] = "🔄 Merge Mode: New images will overwrite existing ones, old files will remain";
    }
    
    $progress[] = "";
    $progress[] = "=== PHASE 1: Upload Entity Images ===";
    $progress[] = "";
    
    // Define the 5 zip files to upload
    $zipFiles = [
      'concelhos.zip',
      'countries.zip',
      'distritos.zip',
      'initiative.zip',
      'organizations.zip',
    ];
    
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
    $images_dir = DRUPAL_ROOT . '/' . $module_path . '/kgrimages';
    
    $api = \Drupal::service('rep.api_connector');
    $foldername = 'kgr'; // Store all KGR images in 'kgr' folder
    
    // Step 0: Delete existing images if requested
    if ($clearExistingImages) {
      $progress[] = "[Step 0/6] Deleting existing images...";
      
      try {
        $delete_response = $api->deleteMediaFolder($foldername);
        $delete_data = json_decode($delete_response);
        
        if (!$delete_data || !$delete_data->isSuccessful) {
          $error_msg = isset($delete_data->message) ? $delete_data->message : 'Unknown error';
          // If folder doesn't exist, that's OK - continue with upload
          if (strpos($error_msg, 'does not exist') !== false || strpos($error_msg, 'already deleted') !== false) {
            $progress[] = "  ℹ️ Folder doesn't exist (first upload or already clean)";
          } else {
            $errors[] = "Failed to delete existing images: $error_msg";
            $progress[] = "  ✗ Delete failed: $error_msg";
          }
        } else {
          $progress[] = "  ✓ Existing images deleted successfully";
          if (isset($delete_data->message)) {
            $progress[] = "    " . $delete_data->message;
          }
        }
        
      } catch (\Exception $e) {
        $errors[] = "Exception deleting existing images: " . $e->getMessage();
        $progress[] = "  ✗ Exception: " . $e->getMessage();
      }
      
      $progress[] = "";
    }
    
    // Step 1: Verify all zip files exist
    $progress[] = "[Step 1/" . ($clearExistingImages ? "6" : "5") . "] Verifying zip files...";
    
    foreach ($zipFiles as $filename) {
      $filePath = $images_dir . '/' . $filename;
      if (!file_exists($filePath)) {
        $errors[] = "Missing zip file: $filename at $filePath";
        return new JsonResponse([
          'success' => false,
          'message' => 'Required zip files not found',
          'progress' => $progress,
          'errors' => $errors,
        ]);
      }
      $filesize = filesize($filePath);
      $progress[] = "  ✓ Found $filename (" . round($filesize / 1024, 2) . " KB)";
    }
    
    // Step 2: Upload each zip file to HAScO API
    $stepNum = $clearExistingImages ? 2 : 2;
    $totalSteps = $clearExistingImages ? 6 : 5;
    
    $progress[] = "";
    $progress[] = "[Step $stepNum/$totalSteps] Uploading zip files to HAScO API...";
    
    foreach ($zipFiles as $filename) {
      $filePath = $images_dir . '/' . $filename;
      $progress[] = "  → Uploading $filename...";
      
      try {
        // Read file content
        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
          $errors[] = "Failed to read file: $filename";
          continue;
        }
        
        // Upload to HAScO API using uploadMedia endpoint
        $upload_response = $api->uploadMedia($foldername, $filename, $fileContent);
        $upload_data = json_decode($upload_response);
        
        if (!$upload_data || !$upload_data->isSuccessful) {
          $error_msg = isset($upload_data->message) ? $upload_data->message : 'Unknown error';
          $errors[] = "Failed to upload $filename: $error_msg";
          $progress[] = "    ✗ Upload failed: $error_msg";
        } else {
          $progress[] = "    ✓ Upload successful (will be extracted asynchronously)";
        }
        
      } catch (\Exception $e) {
        $errors[] = "Exception uploading $filename: " . $e->getMessage();
        $progress[] = "    ✗ Exception: " . $e->getMessage();
      }
    }
    
    // Step 3: Verify uploads
    $progress[] = "";
    $progress[] = "[Step " . ($stepNum + 1) . "/$totalSteps] Verifying uploads...";
    
    if (count($errors) > 0) {
      $progress[] = "  ⚠ Some uploads failed (see errors below)";
      return new JsonResponse([
        'success' => false,
        'message' => 'Some zip files failed to upload',
        'progress' => $progress,
        'errors' => $errors,
      ]);
    }
    
    $progress[] = "  ✓ All 5 zip files uploaded successfully";
    $progress[] = "  ✓ Images will be extracted to: {basePath}/media/kgr/";
    
    // Step 4: KGR file ingestion (Phase 2)
    $progress[] = "";
    $progress[] = "[Step " . ($stepNum + 2) . "/$totalSteps] KGR File Ingestion (Phase 2)...";
    $progress[] = "";
    
    // Define the 10 KGR files to ingest in order
    $kgrFiles = [
      'KGR-COUNTRIES-URI.xlsx',
      'KGR-DISTRITOS-URI.xlsx',
      'KGR-CONCELHOS-URI.xlsx',
      'KGR-INST-POSTAL-URI.xlsx',
      'KGR-FACULDADES-URI.xlsx',
      'KGR-INSTITUTOS-URI.xlsx',
      'KGR-GOVPT.xlsx',
      'KGR-EU-EC.xlsx',
      'KGR-EU-RRF.xlsx',
      'KGR-Digi4health.xlsx',
    ];
    
    $kgr_success_count = 0;
    $kgr_error_count = 0;
    
    foreach ($kgrFiles as $index => $filename) {
      $fileNum = $index + 1;
      $progress[] = "  [$fileNum/10] Processing $filename...";
      
      $filePath = $images_dir . '/../mts/' . $filename;
      
      if (!file_exists($filePath)) {
        $errors[] = "Missing KGR file: $filename";
        $progress[] = "    ✗ File not found at: $filePath";
        $kgr_error_count++;
        continue;
      }
      
      try {
        \Drupal::logger('pmsr')->info("KGR: Starting $filename (step $fileNum/10)");
        
        // STEP 0: Delete existing KGR/DataFile for this filename to prevent duplicates
        $progress[] = "    → Checking for existing KGR data...";
        
        try {
          // Search for existing KGR with matching label (filename without extension)
          $kgr_label = str_replace('.xlsx', '', $filename);
          
          // Use listByKeyword to find KGRs matching this filename
          $existing_kgr_response = $api->listByKeyword('kgr', $kgr_label, 100, 0);
          $existing_kgr_data = json_decode($existing_kgr_response);
          
          if ($existing_kgr_data && isset($existing_kgr_data->body) && is_array($existing_kgr_data->body) && count($existing_kgr_data->body) > 0) {
            foreach ($existing_kgr_data->body as $existing_kgr) {
              // Check if label matches exactly to avoid false positives
              if (isset($existing_kgr->uri) && isset($existing_kgr->label) && $existing_kgr->label === $kgr_label) {
                $progress[] = "    → Found existing KGR: " . $existing_kgr->uri;
                \Drupal::logger('pmsr')->info("KGR: Found existing KGR to delete: " . $existing_kgr->uri);
                
                // Get the DataFile URI
                $existing_datafile_uri = isset($existing_kgr->hasDataFileUri) ? $existing_kgr->hasDataFileUri : null;
                
                // Delete the associated DataFile FIRST (this should delete the named graph with all RDF data)
                if ($existing_datafile_uri) {
                  $progress[] = "    → Deleting DataFile and its RDF data: " . $existing_datafile_uri;
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
            $progress[] = "    ✓ No existing KGR found (fresh ingestion)";
          }
        } catch (\Exception $e) {
          $progress[] = "    ⚠ Error checking for existing KGR: " . $e->getMessage();
          \Drupal::logger('pmsr')->warning("KGR: Error checking for existing KGR: " . $e->getMessage());
          // Continue with ingestion even if deletion check fails
        }
        
        // Copy file to public directory with correct filename
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
          "label" => str_replace('.xlsx', '', $filename),
          "filename" => $filename,
          "fileStatus" => \Drupal\rep\Constant::FILE_STATUS_UNPROCESSED,
          "hasSIRManagerEmail" => $useremail,
        ]);
        
        $msg1 = $api->parseObjectResponse($api->datafileAdd($datafileJSON), 'datafileAdd');
        if ($msg1 == NULL) {
          $errors[] = "Failed to create DataFile for $filename";
          $progress[] = "    ✗ DataFile creation failed";
          \Drupal::logger('pmsr')->error("KGR: DataFile creation failed for $filename");
          $kgr_error_count++;
          continue;
        }
        \Drupal::logger('pmsr')->info("KGR: DataFile created successfully for $filename");
        
        // Create KGR entity
        $kgrJSON = json_encode([
          "uri" => $newKGRUri,
          "typeUri" => "http://hadatac.org/ont/hasco/KGR",
          "hascoTypeUri" => "http://hadatac.org/ont/hasco/KGR",
          "label" => str_replace('.xlsx', '', $filename),
          "hasDataFileUri" => $newDataFileUri,
          "hasSIRManagerEmail" => $useremail,
        ]);
        
        $msg2 = $api->parseObjectResponse($api->elementAdd('kgr', $kgrJSON), 'elementAdd');
        if ($msg2 == NULL) {
          $errors[] = "Failed to create KGR entity for $filename";
          $progress[] = "    ✗ KGR entity creation failed";
          \Drupal::logger('pmsr')->error("KGR: KGR entity creation failed for $filename");
          $kgr_error_count++;
          continue;
        }
        \Drupal::logger('pmsr')->info("KGR: KGR entity created successfully for $filename");
        
        // Upload file content
        $upload_result = $api->uploadFile($newKGRUri, $file_entity->id());
        if ($upload_result === false || $upload_result === NULL) {
          $errors[] = "Failed to upload file content for $filename";
          $progress[] = "    ✗ File upload failed";
          \Drupal::logger('pmsr')->error("KGR: File upload failed for $filename");
          $kgr_error_count++;
          continue;
        }
        \Drupal::logger('pmsr')->info("KGR: File uploaded successfully for $filename");
        
        // Trigger ingestion
        // Create template object (uploadTemplate expects an object, not a URI string)
        $template = new \stdClass();
        $template->uri = $newKGRUri;
        $template->hasDataFileUri = $newDataFileUri;
        $template->hasDataFile = new \stdClass();
        $template->hasDataFile->id = $file_entity->id();
        $template->hasDataFile->filename = $filename;
        
        $ingest_result = $api->uploadTemplate('kgr', $template, '_');
        
        if ($ingest_result === NULL || $ingest_result === FALSE || $ingest_result === '') {
          $errors[] = "Ingestion failed for $filename: No response from API";
          $progress[] = "    ✗ Ingestion trigger failed: No response";
          $kgr_error_count++;
          continue;
        }
        
        $template_data = json_decode($ingest_result);
        
        if (!$template_data) {
          $errors[] = "Ingestion failed for $filename: Invalid JSON response";
          $progress[] = "    ✗ Ingestion trigger failed: Invalid response";
          $progress[] = "    → Raw response: " . substr($ingest_result, 0, 200);
          $kgr_error_count++;
          continue;
        }
        
        if (!isset($template_data->isSuccessful) || !$template_data->isSuccessful) {
          $error_msg = isset($template_data->message) ? $template_data->message : 'No error message provided';
          $errors[] = "Ingestion failed for $filename: $error_msg";
          $progress[] = "    ✗ Ingestion trigger failed: $error_msg";
          \Drupal::logger('pmsr')->error("KGR: Ingestion trigger failed for $filename: $error_msg");
          if (isset($template_data->body)) {
            $body_preview = substr(json_encode($template_data->body), 0, 200);
            $progress[] = "    → Response body: " . $body_preview;
            \Drupal::logger('pmsr')->error("KGR: Response body: $body_preview");
          }
          $kgr_error_count++;
          continue;
        }
        
        $progress[] = "    ✓ Successfully ingested $filename";
        \Drupal::logger('pmsr')->info("KGR: Successfully ingested $filename");
        $kgr_success_count++;
        
        // Delete temporary Drupal file
        $file_entity->delete();
        
      } catch (\Exception $e) {
        $error_detail = $e->getMessage() . " (File: " . $e->getFile() . " Line: " . $e->getLine() . ")";
        $errors[] = "Exception processing $filename: " . $error_detail;
        $progress[] = "    ✗ Exception: " . $e->getMessage();
        $progress[] = "    → Details: " . $e->getFile() . " line " . $e->getLine();
        \Drupal::logger('pmsr')->error("KGR: Exception processing $filename: $error_detail");
        \Drupal::logger('pmsr')->error("KGR: Stack trace: " . $e->getTraceAsString());
        $kgr_error_count++;
      }
    }
    
    $progress[] = "";
    $progress[] = "  Phase 2 Summary: $kgr_success_count successful, $kgr_error_count failed";
    \Drupal::logger('pmsr')->info("KGR: Phase 2 complete - $kgr_success_count successful, $kgr_error_count failed");
    
    // Step 5: Complete
    $progress[] = "";
    $progress[] = "[Step " . ($stepNum + 3) . "/$totalSteps] Geography Ingestion Complete!";
    $progress[] = "";
    $progress[] = "✓ Entity images uploaded successfully (5 zip files)";
    $progress[] = "✓ Images are being extracted in the background";
    $progress[] = "✓ KGR files ingested: $kgr_success_count of 10";
    
    if ($kgr_error_count > 0) {
      $progress[] = "⚠ Some KGR files failed to ingest (see errors above)";
    }
    
    // Final log
    \Drupal::logger('pmsr')->info("KGR: Geography ingestion completed - Success: $kgr_success_count, Errors: $kgr_error_count, Total errors: " . count($errors));
    
    // Invalidate cached organization lists (e.g., Digi4Health members map)
    \Drupal\Core\Cache\Cache::invalidateTags(['kgr_geography']);
    \Drupal::logger('pmsr')->info("KGR: Cleared cached organization lists tagged with 'kgr_geography'");
    $progress[] = "✓ Cleared cached organization data to reflect new geography information";
    
    return new JsonResponse([
      'success' => $kgr_error_count == 0,
      'message' => $kgr_error_count == 0 ? 'Geography ingestion completed successfully' : 'Geography ingestion completed with some errors',
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
