<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\pmsr\Support\PmsrSetupTracker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

/**
 * Controller for KGR People ingestion operations.
 */
class IngestionKgrPeopleController extends ControllerBase {

  private const DP2_FILES = ['DP2-PMSR-V3.xlsx', 'DP2-PIAGET-V3.xlsx'];

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
    $output .= '<p class="mt-3">After KGR-PEOPLE.xlsx ingestion, DP2-PMSR-V3.xlsx and then DP2-PIAGET-V3.xlsx will be ingested using generic MT ingestion endpoints with type <code>dp2</code>.</p>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-warning btn-lg ms-2 btn-sync-users-person">Sync Users/Person</button>';
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
            'syncUsersPerson' => [
              'endpoint' => '/pmsr/api/ingest/people/sync-users-person',
              'message' => 'Synchronizing Drupal users with KGR persons...',
              'token' => $csrf_token,
            ],
          ],
        ],
      ],
    ];
  }

  /**
  * Process KGR people and DP2 ingestion.
   * 
   * Ingests:
   * 1. KGR-PEOPLE.xlsx - Person profiles, memberships, and emails
  * 2. DP2-PMSR-V3.xlsx - Instrument instances, platforms, deployments, and component deployments
  * 3. DP2-PIAGET-V3.xlsx - Instrument instances, platforms, deployments, and component deployments
   */
  public function processPeopleIngestion(Request $request) {
    PmsrSetupTracker::markStageStarted('ingest_kgr_people', 'KGR people ingestion started');

    @ini_set('max_execution_time', '30');
    @set_time_limit(30);

    $progress = [];
    $errors = [];
    $successCount = 0;

    $requestData = json_decode($request->getContent(), TRUE);
    $providedToken = is_array($requestData) ? ($requestData['token'] ?? NULL) : NULL;
    if (!$providedToken || !\Drupal::csrfToken()->validate($providedToken, 'kgr_people_ingestion')) {
      PmsrSetupTracker::markStageResult('ingest_kgr_people', FALSE, 'Security error: invalid or missing CSRF token.');
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Security error: this endpoint can only be called from the GUI interface.',
        'errors' => ['Invalid or missing CSRF token. Please use the GUI to start ingestion.'],
      ]);
    }

    $modulePath = \Drupal::service('extension.list.module')->getPath('pmsr');
    $mtsDir = DRUPAL_ROOT . '/' . $modulePath . '/mts';
    $api = \Drupal::service('rep.api_connector');

    $apiUrl = (string) $api->getApiUrl();
    if (!$this->isLocalHascoApiAt9001($apiUrl)) {
      $message = 'Hard stop: hascoapi must be configured at localhost:9001. Current api_url=' . $apiUrl;
      PmsrSetupTracker::markStageResult('ingest_kgr_people', FALSE, $message);
      return new JsonResponse([
        'success' => FALSE,
        'message' => $message,
        'errors' => [$message],
        'progress' => ['Aborted before ingestion: hascoapi is not configured at localhost:9001.'],
      ]);
    }

    $probe = $this->probeHascoApiFast($apiUrl);
    if (!$probe['ok']) {
      $message = 'Hard stop: hascoapi at localhost:9001 is unreachable. ' . $probe['message'];
      PmsrSetupTracker::markStageResult('ingest_kgr_people', FALSE, $message);
      return new JsonResponse([
        'success' => FALSE,
        'message' => $message,
        'errors' => [$message],
        'progress' => ['Aborted before ingestion: hascoapi did not respond quickly at localhost:9001.'],
      ]);
    }

    $templates = [
      ['concept' => 'kgr', 'filename' => 'KGR-PEOPLE.xlsx', 'typeUri' => 'http://hadatac.org/ont/hasco/KGR', 'label' => 'KGR-PEOPLE'],
      ['concept' => 'dp2', 'filename' => 'DP2-PMSR-V3.xlsx', 'typeUri' => 'http://hadatac.org/ont/hasco/DP2', 'label' => 'DP2-PMSR-V3'],
      ['concept' => 'dp2', 'filename' => 'DP2-PIAGET-V3.xlsx', 'typeUri' => 'http://hadatac.org/ont/hasco/DP2', 'label' => 'DP2-PIAGET-V3'],
    ];

    $failNow = function (string $message, string $progressLine = '') use (&$progress, &$errors, $templates, $successCount) {
      if ($progressLine !== '') {
        $progress[] = $progressLine;
      }
      $errors[] = $message;
      PmsrSetupTracker::markStageResult('ingest_kgr_people', FALSE, $message);
      return new JsonResponse([
        'success' => FALSE,
        'message' => $message,
        'progress' => $progress,
        'errors' => $errors,
        'stats' => [
          'submitted' => $successCount,
          'failed' => count($errors),
          'total' => count($templates),
        ],
      ]);
    };

    $progress[] = 'Starting KGR/DP2 ingestion sequence.';

    foreach ($templates as $idx => $tpl) {
      $concept = (string) $tpl['concept'];
      $filename = (string) $tpl['filename'];
      $typeUri = (string) $tpl['typeUri'];
      $label = (string) $tpl['label'];
      $filePath = $mtsDir . '/' . $filename;
      $step = $idx + 1;

      $progress[] = '[Step ' . $step . '/3] Processing ' . $filename;

      if (!file_exists($filePath)) {
        return $failNow('File not found: ' . $filename . ' at ' . $filePath, '  - ERROR: file not found');
      }

      try {
        $cleanup = $this->cleanupExistingMetadataTemplatesForFile($api, $concept, $label, $filename, $progress);
        if (!$cleanup['ok']) {
          $detail = trim((string) ($cleanup['message'] ?? ''));
          return $failNow('Failed to clean previous metadata template(s) for ' . $filename . ($detail !== '' ? ': ' . $detail : ''), '  - ERROR: rerun cleanup failed');
        }

        $destination = 'public://' . $concept . '/' . $filename;
        $directory = dirname($destination);
        \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

        // Remove stale managed-file records for this exact destination URI.
        $this->deleteManagedFileEntitiesByUri($destination);

        // Ensure filesystem copy is a fresh write.
        $destinationPath = \Drupal::service('file_system')->realpath($destination);
        if (is_string($destinationPath) && $destinationPath !== '' && file_exists($destinationPath)) {
          @unlink($destinationPath);
        }

        $fileContent = file_get_contents($filePath);
        file_put_contents(\Drupal::service('file_system')->realpath($destination), $fileContent);

        $fileEntity = \Drupal\file\Entity\File::create([
          'uri' => $destination,
          'status' => 1,
          'filename' => $filename,
        ]);
        $fileEntity->setPermanent();
        $fileEntity->save();

        $newDataFileUri = \Drupal\rep\Utils::uriGen('datafile');
        if (!is_string($newDataFileUri) || trim($newDataFileUri) === '') {
          return $failNow('Failed to generate DataFile URI for ' . $filename, '  - ERROR: uri generation failed');
        }
        $newDataFileUri = trim($newDataFileUri);
        $newTemplateUri = str_replace('DFL', \Drupal\rep\Utils::elementPrefix($concept), $newDataFileUri);
        $useremail = (string) \Drupal::currentUser()->getEmail();

        $datafileJSON = json_encode([
          'uri' => $newDataFileUri,
          'typeUri' => \Drupal\rep\Vocabulary\HASCO::DATAFILE,
          'hascoTypeUri' => \Drupal\rep\Vocabulary\HASCO::DATAFILE,
          'label' => $label,
          'filename' => $filename,
          'fileStatus' => \Drupal\rep\Constant::FILE_STATUS_UNPROCESSED,
          'id' => $fileEntity->id(),
          'hasSIRManagerEmail' => $useremail,
        ]);

        $dataFileCreate = $this->datafileAddWithTransientRetry($api, $datafileJSON, 3);
        if (!$dataFileCreate['ok']) {
          $detail = trim((string) ($dataFileCreate['detail'] ?? ''));
          return $failNow('Failed to create DataFile for ' . $filename . ($detail !== '' ? ': ' . $detail : ''), '  - ERROR: DataFile creation failed');
        }
        if ((int) ($dataFileCreate['attempts'] ?? 1) > 1) {
          $progress[] = '  - Retry recovered DataFile creation after transient triplestore outage.';
        }

        $elementJSON = json_encode([
          'uri' => $newTemplateUri,
          'typeUri' => $typeUri,
          'hascoTypeUri' => $typeUri,
          'label' => $label,
          'hasDataFileUri' => $newDataFileUri,
          'hasSIRManagerEmail' => $useremail,
        ]);

        $msg2 = $api->parseObjectResponse($api->elementAdd($concept, $elementJSON), 'elementAdd');
        if ($msg2 == NULL) {
          $detail = trim((string) $api->getErrorMessage());
          return $failNow('Failed to create ' . strtoupper($concept) . ' entity for ' . $filename . ($detail !== '' ? ': ' . $detail : ''), '  - ERROR: metadata entity creation failed');
        }

        $uploadResult = $api->uploadFile($newTemplateUri, $fileEntity->id());
        if ($uploadResult === FALSE || $uploadResult === NULL) {
          $detail = trim((string) $api->getErrorMessage());
          return $failNow('Failed to upload file content for ' . $filename . ($detail !== '' ? ': ' . $detail : ''), '  - ERROR: file upload failed');
        }

        $template = new \stdClass();
        $template->uri = $newTemplateUri;
        $template->hasDataFileUri = $newDataFileUri;
        $template->hasDataFile = new \stdClass();
        $template->hasDataFile->id = $fileEntity->id();
        $template->hasDataFile->filename = $filename;

        $ingestResult = $api->uploadTemplate($concept, $template, '_');
        if ($ingestResult === NULL || $ingestResult === FALSE || $ingestResult === '') {
          return $failNow('Ingestion failed for ' . $filename . ': no response from API.', '  - ERROR: ingestion trigger returned no response');
        }

        $ingestObj = json_decode($ingestResult);
        if (!is_object($ingestObj)) {
          return $failNow('Ingestion failed for ' . $filename . ': invalid JSON response.', '  - ERROR: ingestion trigger returned invalid JSON');
        }

        if (!isset($ingestObj->isSuccessful) || !$ingestObj->isSuccessful) {
          $msg = isset($ingestObj->message) ? (string) $ingestObj->message : 'unknown API error';
          return $failNow('Ingestion failed for ' . $filename . ': ' . $msg, '  - ERROR: ingestion trigger failed');
        }

        $successCount++;
        $progress[] = '  - OK: submitted to hascoapi';
      }
      catch (\Throwable $e) {
        return $failNow('Exception processing ' . $filename . ': ' . $e->getMessage(), '  - ERROR: exception during processing');
      }
    }

    \Drupal\Core\Cache\Cache::invalidateTags([
      'kgr_people',
      'dp2_pmsr',
      'pmsr_statistics:global',
      'pmsr_statistics:instances',
      'pmsr_statistics:people',
      'pmsr_statistics:projects',
      'pmsr_statistics:organizations',
    ]);
    $progress[] = 'Cache invalidation completed.';

    $success = empty($errors);
    $message = $success
      ? 'KGR and DP2 ingestion requests submitted successfully.'
      : 'Ingestion completed with some errors.';

    PmsrSetupTracker::markStageResult('ingest_kgr_people', $success, $message);

    return new JsonResponse([
      'success' => $success,
      'message' => $message,
      'progress' => $progress,
      'errors' => $errors,
      'stats' => [
        'submitted' => $successCount,
        'failed' => count($errors),
        'total' => count($templates),
      ],
    ]);
  }

  /**
   * Sync Drupal users and KGR persons by email and update user info fields.
   */
  public function syncUsersPerson(Request $request) {
    set_time_limit(300);

    $requestData = json_decode($request->getContent(), TRUE);
    $providedToken = is_array($requestData) ? ($requestData['token'] ?? NULL) : NULL;
    if (!$providedToken || !\Drupal::csrfToken()->validate($providedToken, 'kgr_people_ingestion')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Security error: invalid or missing CSRF token.',
        'errors' => ['Invalid or missing CSRF token.'],
      ]);
    }

    $progress = [];
    $errors = [];
    $updatedPeople = [];
    $updatedCount = 0;

    $progress[] = 'Starting Users/Person synchronization...';

    $api = \Drupal::service('rep.api_connector');

    // Step 1: Load all persons and index by email-like fields.
    $personList = [];
    try {
      $raw = $api->listByKeyword('person', '_', 9999, 0);
      $parsed = $api->parseObjectResponse($raw, 'listByKeyword');
      if (is_array($parsed)) {
        $personList = $parsed;
      }
    }
    catch (\Throwable $e) {
      $errors[] = 'Failed to load KGR persons: ' . $e->getMessage();
    }

    // Step 2: Load all Drupal users and index by email.
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple();
    $usersByEmail = [];
    foreach ($users as $user) {
      if (!is_object($user) || !method_exists($user, 'getEmail')) {
        continue;
      }

      $email = $this->normalizeEmail((string) $user->getEmail());
      if ($email === '') {
        continue;
      }

      if (!isset($usersByEmail[$email])) {
        $usersByEmail[$email] = [];
      }
      $usersByEmail[$email][] = $user;
    }

    $progress[] = 'Loaded ' . count($users) . ' Drupal users and ' . count($personList) . ' KGR persons.';

    $seenPersonUris = [];
    foreach ($personList as $personLite) {
      if (!is_object($personLite)) {
        continue;
      }

      $personUri = trim((string) ($personLite->uri ?? ''));
      if ($personUri === '' || isset($seenPersonUris[$personUri])) {
        continue;
      }
      $seenPersonUris[$personUri] = TRUE;

      // Hydrate with full data to avoid sparse list payload mismatches.
      $person = $personLite;
      try {
        $rawPerson = $api->getUri($personUri);
        $fullPerson = $api->parseObjectResponse($rawPerson, 'getUri');
        if (is_object($fullPerson)) {
          $person = $fullPerson;
        }
      }
      catch (\Throwable $e) {
        // Fall back to list payload.
      }

      $matchEmail = $this->pickMatchingPersonEmail($person, $usersByEmail);
      if ($matchEmail === '') {
        continue;
      }

      $targetUser = $this->selectTargetUserForPerson($person, $usersByEmail[$matchEmail], $progress);
      if ($targetUser === NULL) {
        continue;
      }

      $expectedUserName = trim((string) $targetUser->getAccountName());
      $expectedUserEmail = trim((string) $targetUser->getEmail());
      $expectedUserId = (string) $targetUser->id();

      if ($this->personMatchesUserInfo($person, $expectedUserName, $expectedUserEmail, $expectedUserId)) {
        continue;
      }

      $payload = $this->buildSyncedPersonPayload($person, $expectedUserName, $expectedUserEmail, $expectedUserId);
      $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if (!is_string($payloadJson) || trim($payloadJson) === '') {
        $errors[] = 'Failed to encode payload for person: ' . $personUri;
        continue;
      }

      try {
        $delRaw = $api->elementDel('person', $personUri);
        $delDecoded = json_decode((string) $delRaw);
        if (is_object($delDecoded) && isset($delDecoded->isSuccessful) && !$delDecoded->isSuccessful) {
          $errors[] = 'Failed to delete person before update: ' . $personUri;
          continue;
        }

        $addRaw = $api->elementAdd('person', $payloadJson);
        $addDecoded = json_decode((string) $addRaw);
        if (is_object($addDecoded) && isset($addDecoded->isSuccessful) && !$addDecoded->isSuccessful) {
          $errors[] = 'Failed to update person user info: ' . $personUri;
          continue;
        }

        // Verify persisted user info after write.
        $persistedMatches = FALSE;
        try {
          $rawAfter = $api->getUri($personUri);
          $personAfter = $api->parseObjectResponse($rawAfter, 'getUri');
          if (is_object($personAfter)) {
            $persistedMatches = $this->personMatchesUserInfo($personAfter, $expectedUserName, $expectedUserEmail, $expectedUserId);
          }
        }
        catch (\Throwable $e) {
          $persistedMatches = FALSE;
        }

        if (!$persistedMatches) {
          $errors[] = 'Updated person but user info still mismatched after write: ' . $personUri;
          continue;
        }

        $updatedCount++;
        $updatedPeople[] = [
          'uri' => $personUri,
          'label' => trim((string) ($person->label ?? $personUri)),
          'userName' => $expectedUserName,
          'userEmail' => $expectedUserEmail,
          'userID' => $expectedUserId,
        ];
      }
      catch (\Throwable $e) {
        $errors[] = 'Exception updating person ' . $personUri . ': ' . $e->getMessage();
      }
    }

    $progress[] = 'Synchronization complete.';
    $progress[] = 'Updated persons: ' . $updatedCount;
    if (!empty($updatedPeople)) {
      $progress[] = 'Updated KGR persons:';
      foreach ($updatedPeople as $item) {
        $progress[] = ' - ' . $item['label'] . ' [' . $item['uri'] . ']';
      }
    }

    \Drupal::logger('pmsr')->info('Sync Users/Person completed. Updated: @count', ['@count' => $updatedCount]);

    return new JsonResponse([
      'success' => empty($errors),
      'message' => 'Sync Users/Person finished. Updated: ' . $updatedCount,
      'progress' => $progress,
      'errors' => $errors,
      'updates_count' => $updatedCount,
      'updated_people' => $updatedPeople,
    ]);
  }

  /**
   * Remove existing MTs for the same logical file before re-ingestion.
   */
  private function cleanupExistingMetadataTemplatesForFile($api, string $concept, string $label, string $filename, array &$progress): array {
    try {
      $response = $api->listByKeyword($concept, $label, 500, 0);
      $decoded = json_decode((string) $response);

      $matches = [];
      if (is_object($decoded) && !empty($decoded->isSuccessful) && !empty($decoded->body) && is_array($decoded->body)) {
        foreach ($decoded->body as $candidate) {
          if (!is_object($candidate)) {
            continue;
          }

          $uri = trim((string) ($candidate->uri ?? ''));
          if ($uri === '') {
            continue;
          }

          $candidateLabel = trim((string) ($candidate->label ?? ''));
          $candidateFilename = '';
          $candidateDataFileUri = trim((string) ($candidate->hasDataFileUri ?? ''));
          if (isset($candidate->hasDataFile) && is_object($candidate->hasDataFile)) {
            $candidateFilename = trim((string) ($candidate->hasDataFile->filename ?? ''));
            if ($candidateDataFileUri === '') {
              $candidateDataFileUri = trim((string) ($candidate->hasDataFile->uri ?? ''));
            }
          }

          if ($candidateLabel === $label || $candidateFilename === $filename) {
            $matches[$uri] = [
              'uri' => $uri,
              'dataFileUri' => $candidateDataFileUri,
            ];
          }
        }
      }

      if (empty($matches)) {
        $progress[] = '  - No previous ' . strtoupper($concept) . ' metadata template found for ' . $filename;
        return ['ok' => TRUE, 'message' => ''];
      }

      $progress[] = '  - Found ' . count($matches) . ' previous metadata template(s) for ' . $filename . '; un-ingesting before re-run';
      foreach ($matches as $entry) {
        $uri = trim((string) ($entry['uri'] ?? ''));
        $dataFileUri = trim((string) ($entry['dataFileUri'] ?? ''));
        if ($uri === '') {
          continue;
        }

        $delRaw = $api->uningestMT($uri);
        $del = json_decode((string) $delRaw);
        $isSuccessful = (is_object($del) && isset($del->isSuccessful) && $del->isSuccessful);
        if (!$isSuccessful) {
          $detail = $this->extractApiErrorDetail($delRaw, 'Unknown uningestMT failure');
          if ($this->isBenignUningestFailure($detail)) {
            $progress[] = '    - MT already absent/previously removed (continuing): ' . $uri;
            continue;
          }

          if ($this->isMissingDataFileDuringUningestFailure($detail)) {
            $forceDelete = $this->forceDeleteMetadataTemplate($api, $concept, $uri, $dataFileUri, $progress);
            if ($forceDelete['ok']) {
              continue;
            }

            $fallbackDetail = trim((string) ($forceDelete['message'] ?? ''));
            if ($fallbackDetail !== '') {
              $detail .= ' | Fallback delete failed: ' . $fallbackDetail;
            }
          }

          return ['ok' => FALSE, 'message' => $detail];
        }
        $progress[] = '    - Un-ingested previous MT: ' . $uri;
      }

      return ['ok' => TRUE, 'message' => ''];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'message' => $e->getMessage()];
    }
  }

  /**
   * Classify benign un-ingest failures where the MT was already removed.
   */
  private function isBenignUningestFailure(string $detail): bool {
    $d = strtolower(trim($detail));
    if ($d === '') {
      return FALSE;
    }

    return (
      strpos($d, 'not found') !== FALSE
      || strpos($d, 'does not exist') !== FALSE
      || strpos($d, 'already deleted') !== FALSE
      || strpos($d, 'already removed') !== FALSE
      || strpos($d, 'no object') !== FALSE
      || strpos($d, 'returned no object') !== FALSE
    );
  }

  /**
   * Detect uningest failures caused by broken/missing DataFile links.
   */
  private function isMissingDataFileDuringUningestFailure(string $detail): bool {
    $d = strtolower(trim($detail));
    if ($d === '') {
      return FALSE;
    }

    return (
      strpos($d, 'unable to retrieve') !== FALSE
      && strpos($d, 'datafile') !== FALSE
    );
  }

  /**
   * Fallback cleanup when uningestMT cannot resolve linked DataFile.
   */
  private function forceDeleteMetadataTemplate($api, string $concept, string $mtUri, string $dataFileUri, array &$progress): array {
    try {
      if ($dataFileUri !== '') {
        try {
          $dfRaw = $api->datafileDel($dataFileUri);
          $df = json_decode((string) $dfRaw);
          if (is_object($df) && !empty($df->isSuccessful)) {
            $progress[] = '    - Fallback: deleted orphan DataFile ' . $dataFileUri;
          }
        }
        catch (\Throwable $ignored) {
          // Continue with MT delete even if DataFile delete fails.
        }
      }

      $elRaw = $api->elementDel($concept, $mtUri);
      $el = json_decode((string) $elRaw);
      if (is_object($el) && !empty($el->isSuccessful)) {
        $progress[] = '    - Fallback: force-deleted metadata template ' . $mtUri;
        return ['ok' => TRUE, 'message' => ''];
      }

      $message = $this->extractApiErrorDetail($elRaw, 'Unknown elementDel failure');
      return ['ok' => FALSE, 'message' => $message];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'message' => $e->getMessage()];
    }
  }

  /**
   * Delete stale Drupal managed-file entities for a known destination URI.
   */
  private function deleteManagedFileEntitiesByUri(string $uri): void {
    $uri = trim($uri);
    if ($uri === '') {
      return;
    }

    try {
      $storage = \Drupal::entityTypeManager()->getStorage('file');
      $files = $storage->loadByProperties(['uri' => $uri]);
      if (empty($files)) {
        return;
      }

      foreach ($files as $file) {
        if (is_object($file) && method_exists($file, 'delete')) {
          $file->delete();
        }
      }
    }
    catch (\Throwable $ignored) {
      // Best-effort cleanup; ingestion can continue because the file path is rewritten.
    }
  }

  /**
   * Normalize an email-like value for matching.
   */
  private function normalizeEmail(string $value): string {
    $value = trim(strtolower($value));
    if ($value === '') {
      return '';
    }

    if (str_starts_with($value, 'mailto:')) {
      $value = substr($value, 7);
    }

    return trim($value);
  }

  /**
   * Run strict DP2 preflight verifier and block ingestion on any error.
   */
  private function runDp2Preflight(string $workbookPath, string $mtsDir, string $prefix): array {
    $modulePath = \Drupal::service('extension.list.module')->getPath('pmsr');
    $moduleRoot = DRUPAL_ROOT . '/' . $modulePath;
    $scriptPath = $moduleRoot . '/scripts/dp2_verify.py';
    $insWorkbook = $mtsDir . '/INS-PMSR-V3.xlsx';
    $outDir = $moduleRoot . '/tests/reports';

    if (!file_exists($scriptPath)) {
      return [
        'success' => FALSE,
        'message' => 'DP2 verifier script not found at ' . $scriptPath,
        'progress' => ['✗ Preflight script is missing.'],
      ];
    }

    if (!file_exists($insWorkbook)) {
      return [
        'success' => FALSE,
        'message' => 'INS workbook not found at ' . $insWorkbook,
        'progress' => ['✗ INS workbook required for slot validation is missing.'],
      ];
    }

    if (!is_dir($outDir)) {
      @mkdir($outDir, 0775, TRUE);
    }

    $command = [
      '/usr/bin/python3',
      $scriptPath,
      $workbookPath,
      '--ins-workbook',
      $insWorkbook,
      '--out-dir',
      $outDir,
      '--prefix',
      $prefix . '.preflight',
    ];

    $process = new Process($command, $moduleRoot, NULL, NULL, 240);
    $process->run();

    $stdout = trim((string) $process->getOutput());
    $stderr = trim((string) $process->getErrorOutput());

    if (!$process->isSuccessful()) {
      $details = $stderr !== '' ? $stderr : $stdout;
      if ($details === '') {
        $details = 'Unknown preflight failure';
      }
      return [
        'success' => FALSE,
        'message' => $details,
        'progress' => [
          '✗ Preflight verification reported errors.',
          '→ ' . substr($details, 0, 600),
        ],
      ];
    }

    $progress = ['✓ Preflight verification passed.'];
    if ($stdout !== '') {
      $lines = preg_split('/\r\n|\r|\n/', $stdout);
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
          $progress[] = '→ ' . $line;
        }
      }
    }

    return [
      'success' => TRUE,
      'message' => 'DP2 preflight passed',
      'progress' => $progress,
    ];
  }

  /**
   * Validate post-ingestion DataFile state to avoid false success reporting.
   */
  private function validateDataFileIngestionOutcome($api, string $dataFileUri): array {
    if (trim($dataFileUri) === '') {
      return [
        'success' => FALSE,
        'message' => 'Missing DataFile URI after ingestion trigger.',
      ];
    }

    try {
      // Keep verification bounded to avoid PHP request hard timeouts in environments
      // that enforce max_execution_time=30 regardless of set_time_limit().
      $maxAttempts = 5; // Up to ~5 seconds of polling between checks.
      $sleepMicros = 1000000;
      $lastStatus = 'UNKNOWN';
      $lastErrorHint = '';

      for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $raw = $api->getUri($dataFileUri);
        $dataFile = $api->parseObjectResponse($raw, 'getUri');

        if (!is_object($dataFile)) {
          $lastStatus = 'UNAVAILABLE';
          if ($attempt < $maxAttempts) {
            usleep($sleepMicros);
            continue;
          }

          return [
            'success' => FALSE,
            'message' => 'Could not retrieve DataFile state from repository after waiting for ingestion completion.',
          ];
        }

        $status = strtoupper(trim((string) ($dataFile->fileStatus ?? '')));
        $log = (string) ($dataFile->log ?? '');
        $logUpper = strtoupper($log);
        $lastStatus = ($status === '' ? 'EMPTY' : $status);

        if (strpos($logUpper, '[ERROR]') !== FALSE) {
          return [
            'success' => FALSE,
            'message' => 'DataFile log contains ingestion errors.',
          ];
        }

        if ($status === 'ERROR' || $status === 'FAILED') {
          return [
            'success' => FALSE,
            'message' => 'DataFile status is ' . $status . '.',
          ];
        }

        if ($status === 'PROCESSED' || $status === 'PROCESSED_STD') {
          return [
            'success' => TRUE,
            'message' => 'DataFile ingestion status is ' . $status . '.',
          ];
        }

        if ($status === '' || $status === 'UNPROCESSED' || $status === 'WORKING' || $status === 'WORKING_STD' || $status === 'PROCESSING' || $status === 'QUEUED') {
          if ($attempt < $maxAttempts) {
            usleep($sleepMicros);
            continue;
          }
          $lastErrorHint = 'Timed out in quick verification window waiting for DataFile to reach PROCESSED state.';
          break;
        }

        // Unknown status: keep waiting until timeout in case backend transitions.
        if ($attempt < $maxAttempts) {
          usleep($sleepMicros);
          continue;
        }
      }

      $message = 'DataFile status did not reach a completed successful state (last status: ' . $lastStatus . ').';
      if ($lastErrorHint !== '') {
        $message .= ' ' . $lastErrorHint;
      }

      return [
        'success' => FALSE,
        'message' => $message,
      ];
    }
    catch (\Throwable $e) {
      return [
        'success' => FALSE,
        'message' => 'Exception while validating DataFile outcome: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Extract a concise API error detail from raw connector responses.
   */
  private function extractApiErrorDetail($rawResponse, string $fallback): string {
    if ($rawResponse === NULL || $rawResponse === FALSE) {
      return $fallback;
    }

    if (is_object($rawResponse) && !method_exists($rawResponse, '__toString')) {
      $msg = '';
      if (isset($rawResponse->message) && is_string($rawResponse->message) && trim($rawResponse->message) !== '') {
        $msg = trim($rawResponse->message);
      }
      elseif (isset($rawResponse->body) && is_string($rawResponse->body) && trim($rawResponse->body) !== '') {
        $msg = trim($rawResponse->body);
      }
      if ($msg !== '') {
        return $msg;
      }
      $json = json_encode($rawResponse);
      if (is_string($json) && trim($json) !== '') {
        return substr($json, 0, 280);
      }
      return $fallback;
    }

    if (is_string($rawResponse)) {
      $trimmed = trim($rawResponse);
      if ($trimmed === '') {
        return $fallback;
      }

      $decoded = json_decode($trimmed);
      if (is_object($decoded)) {
        if (isset($decoded->message) && is_string($decoded->message) && trim($decoded->message) !== '') {
          return trim($decoded->message);
        }
        if (isset($decoded->body)) {
          if (is_string($decoded->body) && trim($decoded->body) !== '') {
            return trim($decoded->body);
          }
          $bodyJson = json_encode($decoded->body);
          if (is_string($bodyJson) && trim($bodyJson) !== '') {
            return substr($bodyJson, 0, 280);
          }
        }
      }

      return substr($trimmed, 0, 280);
    }

    return $fallback;
  }

  /**
   * Retry datafileAdd for transient triplestore outages.
   */
  private function datafileAddWithTransientRetry($api, string $datafileJSON, int $maxAttempts = 3): array {
    $attempt = 0;
    $lastDetail = '';

    while ($attempt < $maxAttempts) {
      $attempt++;

      $msg = $api->parseObjectResponse($api->datafileAdd($datafileJSON), 'datafileAdd');
      if ($msg !== NULL) {
        return [
          'ok' => TRUE,
          'attempts' => $attempt,
          'detail' => '',
        ];
      }

      $lastDetail = trim((string) $api->getErrorMessage());
      $isTransient = $this->isTransientTriplestoreFailure($lastDetail);
      if (!$isTransient || $attempt >= $maxAttempts) {
        break;
      }

      // Small bounded backoff for transient backend outages.
      usleep(300000 * $attempt);
    }

    return [
      'ok' => FALSE,
      'attempts' => $attempt,
      'detail' => $lastDetail,
    ];
  }

  /**
   * Detect transient triplestore outages reported by hascoapi.
   */
  private function isTransientTriplestoreFailure(string $detail): bool {
    $d = strtolower(trim($detail));
    if ($d === '') {
      return FALSE;
    }

    return (
      strpos($d, 'triplestore_unavailable') !== FALSE
      || strpos($d, 'triplestore is unavailable') !== FALSE
      || strpos($d, 'status code: 503') !== FALSE
      || strpos($d, '"status":503') !== FALSE
    );
  }

  /**
   * Quick readiness check for HASCOAPI before starting ingestion operations.
   */
  private function checkHascoApiReadiness($api): array {
    try {
      $repoRaw = $api->repoInfo();
      $repoObj = $api->parseObjectResponse($repoRaw, 'repoInfo');

      if ($repoObj !== NULL) {
        return [
          'ok' => TRUE,
          'message' => 'HASCOAPI responded to repoInfo.',
        ];
      }

      $detail = trim((string) $api->getErrorMessage());
      if ($detail === '') {
        $detail = 'HASCOAPI did not return a valid response to repoInfo.';
      }

      return [
        'ok' => FALSE,
        'message' => 'HASCOAPI readiness check failed: ' . $detail,
      ];
    }
    catch (\Throwable $e) {
      return [
        'ok' => FALSE,
        'message' => 'HASCOAPI readiness check exception: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Require local hascoapi endpoint on port 9001.
   */
  private function isLocalHascoApiAt9001(string $apiUrl): bool {
    $url = trim($apiUrl);
    if ($url === '') {
      return FALSE;
    }

    $parts = @parse_url($url);
    if (!is_array($parts)) {
      return FALSE;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    $port = (int) ($parts['port'] ?? 80);

    if ($host !== 'localhost' && $host !== '127.0.0.1') {
      return FALSE;
    }

    return $port === 9001;
  }

  /**
   * Fast liveness probe to avoid long hangs when HASCOAPI is down.
   */
  private function probeHascoApiFast(string $apiUrl): array {
    $pingUrl = rtrim($apiUrl, '/') . '/hascoapi/api/ping';

    try {
      $client = new \GuzzleHttp\Client([
        'timeout' => 2,
        'connect_timeout' => 1,
        'http_errors' => FALSE,
      ]);

      $res = $client->get($pingUrl);
      $status = (int) $res->getStatusCode();
      if ($status >= 200 && $status < 500) {
        return ['ok' => TRUE, 'message' => ''];
      }

      return ['ok' => FALSE, 'message' => 'Ping returned HTTP ' . $status . ' at ' . $pingUrl];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'message' => $e->getMessage() . ' (' . $pingUrl . ')'];
    }
  }

  /**
   * Pick the primary email used to match a person to Drupal users.
   */
  private function pickPrimaryPersonEmail(object $person): string {
    $candidates = [
      $this->normalizeEmail((string) ($person->userEmail ?? '')),
      $this->normalizeEmail((string) ($person->mbox ?? '')),
      $this->normalizeEmail((string) ($person->hasSIRManagerEmail ?? '')),
    ];

    foreach ($candidates as $candidate) {
      if ($candidate !== '') {
        return $candidate;
      }
    }

    return '';
  }

  /**
   * Pick the first person email candidate that maps to an existing Drupal user.
   */
  private function pickMatchingPersonEmail(object $person, array $usersByEmail): string {
    $candidates = [
      $this->normalizeEmail((string) ($person->userEmail ?? '')),
      $this->normalizeEmail((string) ($person->mbox ?? '')),
      $this->normalizeEmail((string) ($person->hasSIRManagerEmail ?? '')),
    ];

    foreach ($candidates as $candidate) {
      if ($candidate !== '' && isset($usersByEmail[$candidate])) {
        return $candidate;
      }
    }

    return '';
  }

  /**
   * Choose a deterministic target user for a person when email maps to many users.
   */
  private function selectTargetUserForPerson(object $person, array $usersForEmail, array &$progress) {
    if (empty($usersForEmail)) {
      return NULL;
    }

    // Prefer existing direct linkage by userID.
    $personUserId = trim((string) ($person->userID ?? ''));
    if ($personUserId !== '') {
      foreach ($usersForEmail as $user) {
        if ((string) $user->id() === $personUserId) {
          return $user;
        }
      }
    }

    // Then prefer existing linkage by userName.
    $personUserName = trim((string) ($person->userName ?? ''));
    if ($personUserName !== '') {
      foreach ($usersForEmail as $user) {
        if (strcasecmp((string) $user->getAccountName(), $personUserName) === 0) {
          return $user;
        }
      }
    }

    if (count($usersForEmail) === 1) {
      return reset($usersForEmail);
    }

    // Stable fallback: lowest UID to avoid oscillation across runs.
    usort($usersForEmail, function ($a, $b) {
      return ((int) $a->id()) <=> ((int) $b->id());
    });

    $uri = trim((string) ($person->uri ?? '(unknown person)'));
    $progress[] = 'Ambiguous email match for ' . $uri . '; selected lowest UID deterministically.';

    return $usersForEmail[0];
  }

  /**
   * Compare person user-info fields to expected Drupal user values.
   */
  private function personMatchesUserInfo(object $person, string $expectedUserName, string $expectedUserEmail, string $expectedUserId): bool {
    $currentUserName = trim((string) ($person->userName ?? ''));
    $currentUserEmail = trim((string) ($person->userEmail ?? ''));
    $currentUserId = trim((string) ($person->userID ?? ''));

    return (
      strcasecmp($currentUserName, $expectedUserName) === 0
      && $this->normalizeEmail($currentUserEmail) === $this->normalizeEmail($expectedUserEmail)
      && $currentUserId === trim($expectedUserId)
    );
  }

  /**
   * Build a person payload preserving existing fields and synced user info.
   */
  private function buildSyncedPersonPayload(object $person, string $userName, string $userEmail, string $userId): array {
    $payload = [
      'uri' => trim((string) ($person->uri ?? '')),
      'typeUri' => trim((string) ($person->typeUri ?? 'https://schema.org/Person')),
      'hascoTypeUri' => trim((string) ($person->hascoTypeUri ?? 'https://schema.org/Person')),
      'label' => trim((string) ($person->label ?? '')),
      'name' => trim((string) ($person->name ?? '')),
      'givenName' => trim((string) ($person->givenName ?? '')),
      'familyName' => trim((string) ($person->familyName ?? '')),
      'mbox' => trim((string) ($person->mbox ?? '')),
      'telephone' => trim((string) ($person->telephone ?? '')),
      'hasAddressUri' => trim((string) ($person->hasAddressUri ?? '')),
      'hasAffiliationUri' => trim((string) ($person->hasAffiliationUri ?? '')),
      'jobTitle' => trim((string) ($person->jobTitle ?? '')),
      'comment' => trim((string) ($person->comment ?? '')),
      'description' => trim((string) ($person->description ?? '')),
      'hasImageUri' => trim((string) ($person->hasImageUri ?? '')),
      'hasWebDocument' => trim((string) ($person->hasWebDocument ?? '')),
      'hasSIRManagerEmail' => $userEmail,
      'userName' => $userName,
      'userEmail' => $userEmail,
      'userID' => $userId,
    ];

    // Preserve optional fields when present.
    foreach (['namedGraph', 'hasStatus', 'originalID', 'hasLanguage', 'hasVersion'] as $field) {
      if (isset($person->{$field})) {
        $payload[$field] = $person->{$field};
      }
    }

    // Keep label/name usable if one is missing.
    if ($payload['label'] === '' && $payload['name'] !== '') {
      $payload['label'] = $payload['name'];
    }
    if ($payload['name'] === '' && $payload['label'] !== '') {
      $payload['name'] = $payload['label'];
    }

    return $payload;
  }

}
