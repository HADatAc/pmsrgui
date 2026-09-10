<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\file\Entity\File;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\VSTOI;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for batch WKF scenario ingestion from pmsr/wkf folder.
 */
class IngestionWkfScenariosController extends ControllerBase {

  private const MAX_INGESTION_ROUNDS = 3;
  private const MONITOR_MAX_ATTEMPTS = 60;
  private const MONITOR_SLEEP_SECONDS = 2;
  private const URI_NOT_FOUND_MAX_CONSECUTIVE = 8;
  private const ACTIVE_JOB_STATE_KEY = 'pmsr.wkf_ingestion.active_job';
  private const PROCESS_LOCK_PREFIX = 'pmsr.wkf_ingestion.process.';
  private const WKF_CACHE_STATE_KEY = 'pmsr.wkf_ingestion.cache';

  /**
   * Render the WKF scenarios ingestion page.
   */
  public function content() {
    $wkfFiles = $this->discoverWkfFiles();
    $cache = $this->getWkfIngestionCache();
    $activeState = $this->getActiveJobState();
    $activeJobId = is_array($activeState) ? (string) ($activeState['jobId'] ?? '') : '';

    $cards = [];
    if (is_array($activeState) && !empty($activeState['cards']) && is_array($activeState['cards'])) {
      $cards = $activeState['cards'];
    }
    else {
      foreach ($wkfFiles as $file) {
        $filename = (string) $file['filename'];
        $entry = $cache[$filename] ?? [];
        $ingested = !empty($entry['ingested']);
        $cards[] = [
          'filename' => $filename,
          'status' => $ingested ? 'INGESTED' : 'UNINGESTED',
          'detail' => $ingested
            ? 'Cached as already ingested. Start without scratch will skip.'
            : 'Not ingested yet (cache).',
          'wkfUri' => isset($entry['wkfUri']) ? (string) $entry['wkfUri'] : NULL,
          'dataFileUri' => isset($entry['dataFileUri']) ? (string) $entry['dataFileUri'] : NULL,
        ];
      }
    }

    $cardsMarkup = '';
    foreach ($cards as $card) {
      $filename = (string) ($card['filename'] ?? '');
      $status = strtoupper((string) ($card['status'] ?? 'PENDING'));
      $detail = (string) ($card['detail'] ?? 'Waiting for ingestion start.');

      $class = 'wkf-card-warning';
      if ($status === 'PENDING') {
        $class = 'wkf-card-pending';
      }
      else if ($status === 'WORKING') {
        $class = 'wkf-card-working';
      }
      else if ($status === 'PROCESSED') {
        $class = 'wkf-card-processed';
      }
      else if ($status === 'INGESTED') {
        $class = 'wkf-card-processed';
      }

      $cardsMarkup .= '<div class="wkf-card ' . $class . '" data-wkf-file="' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="wkf-card-title"><strong>' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '</strong></div>'
        . '<div class="wkf-card-status">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="wkf-card-detail text-muted small">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div>';
    }

    if ($cardsMarkup === '') {
      $cardsMarkup = '<div class="alert alert-warning">No WKF-*.xlsx files found in pmsr/wkf.</div>';
    }

    $output = '';
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page ingests all WKF scenario files found in <code>pmsr/wkf</code> using the same PMSR ingestion flow used for individual WKFs. Each WKF is assigned to the organization already defined in its own content.</p>';
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-info btn-lg ms-2 btn-refresh-ingestion">Refresh</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2 btn-cancel-ingestion" onclick="history.back()">Cancel</button>';
    $output .= '</div>';

    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';

    $output .= '<div id="ingestion-results" class="mt-4" style="display:block;">';
    $output .= '<div id="wkf-cards-grid" class="wkf-cards-grid">' . $cardsMarkup . '</div>';
    $output .= '<div id="wkf-ingestion-summary" class="mt-3"></div>';
    $output .= '</div>';
    $output .= '</div>';

    $activeStatus = is_array($activeState) ? strtoupper((string) ($activeState['status'] ?? '')) : '';
    $activeMessage = is_array($activeState) ? (string) ($activeState['message'] ?? '') : '';
    $processingStarted = is_array($activeState) ? !empty($activeState['processingStarted']) : FALSE;
    $cachedCards = array_values(array_map(function ($file) use ($cache) {
      $filename = (string) $file['filename'];
      $entry = $cache[$filename] ?? [];
      $ingested = !empty($entry['ingested']);
      return [
        'filename' => $filename,
        'status' => $ingested ? 'INGESTED' : 'UNINGESTED',
        'detail' => $ingested
          ? 'Cached as already ingested. Start without scratch will skip.'
          : 'Not ingested yet (cache).',
        'wkfUri' => isset($entry['wkfUri']) ? (string) $entry['wkfUri'] : NULL,
        'dataFileUri' => isset($entry['dataFileUri']) ? (string) $entry['dataFileUri'] : NULL,
      ];
    }, $wkfFiles));
    $anyCachedIngested = FALSE;
    foreach ($cachedCards as $cachedCard) {
      if (($cachedCard['status'] ?? '') === 'INGESTED') {
        $anyCachedIngested = TRUE;
        break;
      }
    }

    return [
      '#markup' => Markup::create($output),
      '#attached' => [
        'library' => [
          'pmsr/ingestion',
          'pmsr/wkf_scenarios_ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'wkfIngestion' => [
              'files' => array_values(array_map(function ($f) {
                return $f['filename'];
              }, $wkfFiles)),
              'startEndpoint' => '/pmsr/api/ingest/wkf-scenarios/start',
              'statusEndpoint' => '/pmsr/api/ingest/wkf-scenarios/status',
              'processEndpoint' => '/pmsr/api/ingest/wkf-scenarios/process',
              'message' => 'Ingesting WKF scenarios...',
              'activeJobId' => $activeJobId,
              'activeStatus' => $activeStatus,
              'activeMessage' => $activeMessage,
              'processingStarted' => $processingStarted,
              'cachedCards' => $cachedCards,
              'anyCachedIngested' => $anyCachedIngested,
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Start a tracked WKF ingestion job.
   */
  public function start(Request $request) {
    $payload = json_decode($request->getContent(), TRUE);
    $fromScratch = is_array($payload) && !empty($payload['fromScratch']);

    $activeState = $this->getActiveJobState();
    if (is_array($activeState) && strtoupper((string) ($activeState['status'] ?? '')) === 'RUNNING') {
      if ($fromScratch) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'A WKF ingestion job is currently running. Wait for completion before starting from scratch.',
          'jobId' => $activeState['jobId'] ?? '',
          'cards' => $activeState['cards'] ?? [],
        ], 409);
      }

      return new JsonResponse([
        'success' => TRUE,
        'jobId' => $activeState['jobId'] ?? '',
        'message' => 'Resuming currently running WKF ingestion job.',
        'cards' => $activeState['cards'] ?? [],
        'resumed' => TRUE,
      ]);
    }

    $files = $this->discoverWkfFiles();
    $cache = $this->getWkfIngestionCache();
    $jobId = 'wkf-' . \Drupal::time()->getCurrentTime() . '-' . bin2hex(random_bytes(4));

    $progress = ['Job created with ' . count($files) . ' WKF file(s).'];
    $errors = [];

    if ($fromScratch) {
      $api = \Drupal::service('rep.api_connector');
      $progress[] = 'Start from scratch requested: uningesting cached WKFs before ingestion.';
      $this->performCacheBasedUningestion($api, $cache, $progress, $errors);
      $this->saveWkfIngestionCache($cache);
    }

    $cards = [];
    foreach ($files as $file) {
      $filename = (string) $file['filename'];
      $entry = $cache[$filename] ?? [];
      $cachedIngested = !empty($entry['ingested']);
      $cards[] = [
        'filename' => $filename,
        'path' => $file['path'],
        'status' => $cachedIngested ? 'INGESTED' : 'UNINGESTED',
        'detail' => $cachedIngested
          ? 'Cached as already ingested. Start without scratch skips this file.'
          : 'Ready for ingestion.',
        'attempts' => 0,
        'wkfUri' => isset($entry['wkfUri']) ? (string) $entry['wkfUri'] : NULL,
        'dataFileUri' => isset($entry['dataFileUri']) ? (string) $entry['dataFileUri'] : NULL,
        'templateStatus' => 'UNKNOWN',
        'fileStatus' => 'UNKNOWN',
        'wkfUriMisses' => 0,
        'dataFileUriMisses' => 0,
      ];
    }

    $state = [
      'jobId' => $jobId,
      'status' => 'RUNNING',
      'message' => $fromScratch
        ? 'WKF ingestion job started from scratch (including cached uningestion).'
        : 'WKF ingestion job started',
      'cards' => $cards,
      'progress' => $progress,
      'errors' => $errors,
      'startedAt' => \Drupal::time()->getCurrentTime(),
      'updatedAt' => \Drupal::time()->getCurrentTime(),
      'finishedAt' => NULL,
      'processingStarted' => FALSE,
      'fromScratch' => $fromScratch,
    ];

    $this->saveJobState($jobId, $state);
    $this->setActiveJobId($jobId);

    return new JsonResponse([
      'success' => TRUE,
      'jobId' => $jobId,
      'message' => $state['message'],
      'cards' => $cards,
      'fromScratch' => $fromScratch,
    ]);
  }

  /**
   * Get current job status.
   */
  public function status(string $jobId) {
    $state = $this->getJobState($jobId);
    if (!is_array($state)) {
      return new JsonResponse([
        'success' => FALSE,
        'status' => 'UNKNOWN',
        'message' => 'WKF ingestion job not found',
      ], 404);
    }

    return new JsonResponse([
      'success' => (($state['status'] ?? 'UNKNOWN') === 'SUCCESS'),
      'status' => $state['status'] ?? 'UNKNOWN',
      'message' => $state['message'] ?? '',
      'jobId' => $jobId,
      'cards' => $state['cards'] ?? [],
      'progress' => $state['progress'] ?? [],
      'errors' => $state['errors'] ?? [],
      'processingStarted' => !empty($state['processingStarted']),
      'startedAt' => $state['startedAt'] ?? NULL,
      'updatedAt' => $state['updatedAt'] ?? NULL,
      'finishedAt' => $state['finishedAt'] ?? NULL,
    ]);
  }

  /**
   * Execute the WKF ingestion loop (submit + monitor with retries).
   */
  public function process(Request $request) {
    @set_time_limit(900);

    $payload = json_decode($request->getContent(), TRUE);
    $jobId = is_array($payload) && isset($payload['jobId']) ? trim((string) $payload['jobId']) : '';
    if ($jobId === '') {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Missing jobId',
      ], 400);
    }

    $state = $this->getJobState($jobId);
    if (!is_array($state)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'WKF ingestion job not found',
      ], 404);
    }

    $currentStatus = strtoupper((string) ($state['status'] ?? ''));
    if ($currentStatus === 'SUCCESS' || $currentStatus === 'FAILED') {
      return new JsonResponse([
        'success' => ($currentStatus === 'SUCCESS'),
        'status' => $currentStatus,
        'message' => $state['message'] ?? 'WKF ingestion already finished.',
        'jobId' => $jobId,
        'cards' => $state['cards'] ?? [],
        'progress' => $state['progress'] ?? [],
        'errors' => $state['errors'] ?? [],
      ]);
    }

    $lockName = self::PROCESS_LOCK_PREFIX . $jobId;
    if (!\Drupal::lock()->acquire($lockName, 0.05)) {
      return new JsonResponse([
        'success' => TRUE,
        'status' => 'RUNNING',
        'message' => 'WKF ingestion is already running for this job. Refresh will keep following progress.',
        'jobId' => $jobId,
        'cards' => $state['cards'] ?? [],
        'progress' => $state['progress'] ?? [],
        'errors' => $state['errors'] ?? [],
      ]);
    }

    try {
      $state['processingStarted'] = TRUE;
      $state['status'] = 'RUNNING';
      $this->touchState($state, $jobId, 'WKF ingestion worker active.');

      $api = \Drupal::service('rep.api_connector');

      for ($round = 1; $round <= self::MAX_INGESTION_ROUNDS; $round++) {
        $state['progress'][] = 'Round ' . $round . ': submitting pending WKFs.';

        foreach ($state['cards'] as $idx => $card) {
          if ($this->isCardDone($card)) {
            continue;
          }

          $existing = $this->inspectExistingBackendState($api, $card);
          if (!$existing['shouldSubmit']) {
            $state['cards'][$idx]['wkfUri'] = $existing['wkfUri'];
            $state['cards'][$idx]['dataFileUri'] = $existing['dataFileUri'];
            $state['cards'][$idx]['templateStatus'] = $existing['templateStatus'];
            $state['cards'][$idx]['fileStatus'] = $existing['fileStatus'];
            $state['cards'][$idx]['status'] = $existing['status'];
            $state['cards'][$idx]['detail'] = $existing['detail'];

            if ($existing['status'] === 'PROCESSED') {
              $filename = (string) ($card['filename'] ?? '');
              if ($filename !== '') {
                $cache = $this->getWkfIngestionCache();
                $cache[$filename] = [
                  'ingested' => TRUE,
                  'wkfUri' => $existing['wkfUri'],
                  'dataFileUri' => $existing['dataFileUri'],
                  'updatedAt' => \Drupal::time()->getCurrentTime(),
                ];
                $this->saveWkfIngestionCache($cache);
              }
            }
            continue;
          }

          $state['cards'][$idx]['status'] = 'WORKING';
          $state['cards'][$idx]['detail'] = 'Submitting ingestion (round ' . $round . ')...';
          $state['cards'][$idx]['attempts'] = (int) ($state['cards'][$idx]['attempts'] ?? 0) + 1;
          $this->touchState($state, $jobId, 'Submitting ' . ($card['filename'] ?? 'unknown'));

          $submit = $this->submitSingleWkf($api, $card);
          if (!$submit['success']) {
            $state['cards'][$idx]['status'] = 'FAILED';
            $state['cards'][$idx]['detail'] = $submit['message'];
            $state['errors'][] = ($card['filename'] ?? 'WKF') . ': ' . $submit['message'];
            $this->touchState($state, $jobId, 'Submission failed for ' . ($card['filename'] ?? 'unknown'));
            continue;
          }

          $state['cards'][$idx]['wkfUri'] = $submit['wkfUri'];
          $state['cards'][$idx]['dataFileUri'] = $submit['dataFileUri'];
          $state['cards'][$idx]['wkfUriMisses'] = 0;
          $state['cards'][$idx]['dataFileUriMisses'] = 0;
          $state['cards'][$idx]['detail'] = 'Submitted. Waiting for backend processing...';
        }

        $this->monitorCardsUntilTerminal($api, $state, $jobId, $round);

        $allProcessed = TRUE;
        foreach ($state['cards'] as $card) {
          if (!$this->isCardDone($card)) {
            $allProcessed = FALSE;
            break;
          }
        }
        if ($allProcessed) {
          break;
        }
      }

      $allProcessed = TRUE;
      foreach ($state['cards'] as $card) {
        if (!$this->isCardDone($card)) {
          $allProcessed = FALSE;
          break;
        }
      }

      $state['status'] = $allProcessed ? 'SUCCESS' : 'FAILED';
      $state['message'] = $allProcessed
        ? 'All WKF scenarios were processed successfully.'
        : 'Some WKF scenarios did not reach PROCESSED status.';
      $state['finishedAt'] = \Drupal::time()->getCurrentTime();
      $state['processingStarted'] = FALSE;
      $this->touchState($state, $jobId, $state['message']);

      if (($this->getActiveJobId() ?? '') === $jobId) {
        $this->clearActiveJobId();
      }

      return new JsonResponse([
        'success' => $allProcessed,
        'status' => $state['status'],
        'message' => $state['message'],
        'jobId' => $jobId,
        'cards' => $state['cards'],
        'progress' => $state['progress'],
        'errors' => $state['errors'],
      ]);
    }
    finally {
      \Drupal::lock()->release($lockName);
    }
  }

  /**
   * Submit one WKF by creating DataFile/WKF entities and triggering uploadTemplate.
   */
  private function submitSingleWkf($api, array $card): array {
    $filename = (string) ($card['filename'] ?? '');
    $sourcePath = (string) ($card['path'] ?? '');
    if ($filename === '' || $sourcePath === '' || !file_exists($sourcePath)) {
      return ['success' => FALSE, 'message' => 'WKF file is missing on disk: ' . $filename];
    }

    try {
      // Keep temporary files in the same public://mts area used by existing
      // upload fallbacks in the API connector.
      $destination = 'public://mts/' . $filename;
      $directory = dirname($destination);
      \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
      $contents = file_get_contents($sourcePath);
      $file = \Drupal::service('file.repository')->writeData($contents, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
      if (!$file instanceof File) {
        return ['success' => FALSE, 'message' => 'Failed to create Drupal file entity for ' . $filename];
      }
      $file->setPermanent();
      $file->save();
    }
    catch (\Throwable $e) {
      return ['success' => FALSE, 'message' => 'Drupal file creation failed: ' . $e->getMessage()];
    }

    $useremail = trim((string) \Drupal::currentUser()->getEmail());
      if ($useremail === '' || !filter_var($useremail, FILTER_VALIDATE_EMAIL)) {
        $siteMail = trim((string) (\Drupal::config('system.site')->get('mail') ?? ''));
        if ($siteMail !== '' && filter_var($siteMail, FILTER_VALIDATE_EMAIL)) {
          $useremail = $siteMail;
        }
        else {
          $useremail = 'admin@pmsr.com';
        }
      }
      $useremail = $this->resolveManagerEmailForSubmission($useremail);
    $dataFileUri = $this->generateDataFileUriWithFallback($api);
    if ($dataFileUri === '') {
      return ['success' => FALSE, 'message' => 'Could not generate DataFile URI'];
    }

    $wkfUri = $this->buildLinkedTemplateUri($dataFileUri, 'wkf');
    if ($wkfUri === '') {
      return ['success' => FALSE, 'message' => 'Could not generate WKF URI from DataFile URI'];
    }

    $label = preg_replace('/\.xlsx$/i', '', $filename);

    $datafileJSON = json_encode([
      'uri' => $dataFileUri,
      'typeUri' => HASCO::DATAFILE,
      'hascoTypeUri' => HASCO::DATAFILE,
      'label' => $label,
      'filename' => $filename,
      'fileStatus' => \Drupal\rep\Constant::FILE_STATUS_UNPROCESSED,
      'hasSIRManagerEmail' => $useremail,
      'id' => $file->id(),
    ]);

    $wkfJSON = json_encode([
      'uri' => $wkfUri,
      'typeUri' => HASCO::WKF,
      'hascoTypeUri' => HASCO::WKF,
      'label' => $label,
      'hasDataFileUri' => $dataFileUri,
      'hasVersion' => '1.0',
      'comment' => 'Batch ingestion from pmsr/wkf folder',
      'hasSIRManagerEmail' => $useremail,
    ]);

    $dfRaw = $api->datafileAdd($datafileJSON);
    $dfObj = $api->parseObjectResponse($dfRaw, 'datafileAdd');
    if ($dfObj === NULL) {
      return ['success' => FALSE, 'message' => 'Failed to create DataFile entity'];
    }

    $wkfRaw = $api->elementAdd('wkf', $wkfJSON);
    $wkfObj = $api->parseObjectResponse($wkfRaw, 'elementAdd');
    if ($wkfObj === NULL) {
      return ['success' => FALSE, 'message' => 'Failed to create WKF entity'];
    }

    // Mirror REPSelectMTForm::performIngest() (the "Ingest" button in Manage WKFs):
    // re-fetch the full persisted WKF template instead of submitting a hand-built
    // partial object, and submit with VSTOI::DRAFT status like the working button does.
    $template = $api->parseObjectResponse($api->getUri($wkfUri), 'getUri');
    if ($template === NULL) {
      return ['success' => FALSE, 'message' => 'Failed to retrieve the newly created WKF entity for ingestion'];
    }

    if (isset($template->uri) && is_string($template->uri) && $template->uri !== '') {
      $template->uri = Utils::plainUri($template->uri) ?: $template->uri;
    }
    else {
      $template->uri = $wkfUri;
    }

    if ((!isset($template->hasDataFileUri) || $template->hasDataFileUri == NULL || $template->hasDataFileUri === '')
      && isset($template->hasDataFile) && is_object($template->hasDataFile)
      && isset($template->hasDataFile->uri) && is_string($template->hasDataFile->uri) && $template->hasDataFile->uri !== '') {
      $template->hasDataFileUri = Utils::plainUri($template->hasDataFile->uri) ?: $template->hasDataFile->uri;
    }

    if (!isset($template->hasDataFile) && isset($template->hasDataFileUri)) {
      $dataFileUriToFetch = Utils::plainUri($template->hasDataFileUri) ?: $template->hasDataFileUri;
      $dataFile = $api->parseObjectResponse($api->getUri($dataFileUriToFetch), 'getUri');
      if ($dataFile !== NULL) {
        $template->hasDataFile = $dataFile;
        $template->hasDataFileUri = $dataFileUriToFetch;
      }
    }

    $ingestRaw = $api->uploadTemplate('wkf', $template, VSTOI::DRAFT);
    $ingestObj = $api->parseObjectResponse($ingestRaw, 'uploadTemplateStatus');
    if ($ingestObj === NULL) {
      $apiDetail = method_exists($api, 'getErrorMessage') ? trim((string) $api->getErrorMessage()) : '';
      return ['success' => FALSE, 'message' => 'Failed to trigger WKF ingestion' . ($apiDetail !== '' ? ': ' . $apiDetail : '')];
    }

    return [
      'success' => TRUE,
      'wkfUri' => $wkfUri,
      'dataFileUri' => $dataFileUri,
      'message' => 'Submitted',
    ];
  }

  /**
   * Monitor submitted cards until they reach terminal status or timeout.
   */
  private function monitorCardsUntilTerminal($api, array &$state, string $jobId, int $round): void {
    $cache = $this->getWkfIngestionCache();

    for ($attempt = 1; $attempt <= self::MONITOR_MAX_ATTEMPTS; $attempt++) {
      $pending = 0;
      foreach ($state['cards'] as $idx => $card) {
        $status = (string) ($card['status'] ?? 'PENDING');
        if ($this->isCardDone($card)) {
          continue;
        }

        if ($status === 'INGESTED') {
          $state['cards'][$idx]['detail'] = 'Already ingested (cache).';
          continue;
        }

        $wkfUri = isset($card['wkfUri']) && is_string($card['wkfUri']) ? trim($card['wkfUri']) : '';
        $dataFileUri = isset($card['dataFileUri']) && is_string($card['dataFileUri']) ? trim($card['dataFileUri']) : '';
        if ($wkfUri === '' && $dataFileUri === '') {
          $state['cards'][$idx]['status'] = 'FAILED';
          $state['cards'][$idx]['detail'] = 'Missing WKF/DataFile URI after submit.';
          continue;
        }

        $templateStatus = 'UNKNOWN';
        $fileStatus = 'UNKNOWN';
        $wkfMissing = FALSE;
        $datafileMissing = FALSE;

        if ($wkfUri !== '') {
          $wkfLookup = $this->fetchUriObject($api, $wkfUri);
          if (!empty($wkfLookup['found']) && is_object($wkfLookup['object'])) {
            $state['cards'][$idx]['wkfUriMisses'] = 0;
            $wkfObj = $wkfLookup['object'];
            if (isset($wkfObj->hasStatus)) {
              $templateStatus = $this->normalizeStatusToken((string) $wkfObj->hasStatus, 'UNKNOWN');
            }
          }
          else if (!empty($wkfLookup['notFound'])) {
            $wkfMissing = TRUE;
            $state['cards'][$idx]['wkfUriMisses'] = (int) ($state['cards'][$idx]['wkfUriMisses'] ?? 0) + 1;
          }
        }

        if ($dataFileUri !== '') {
          $dfLookup = $this->fetchUriObject($api, $dataFileUri);
          if (!empty($dfLookup['found']) && is_object($dfLookup['object'])) {
            $state['cards'][$idx]['dataFileUriMisses'] = 0;
            $dfObj = $dfLookup['object'];
            if (isset($dfObj->fileStatus)) {
              $fileStatus = $this->normalizeStatusToken((string) $dfObj->fileStatus, 'UNKNOWN');
            }
            else if (isset($dfObj->hasStatus)) {
              $fileStatus = $this->normalizeStatusToken((string) $dfObj->hasStatus, 'UNKNOWN');
            }
          }
          else if (!empty($dfLookup['notFound'])) {
            $datafileMissing = TRUE;
            $state['cards'][$idx]['dataFileUriMisses'] = (int) ($state['cards'][$idx]['dataFileUriMisses'] ?? 0) + 1;
          }
        }

        $state['cards'][$idx]['templateStatus'] = $templateStatus;
        $state['cards'][$idx]['fileStatus'] = $fileStatus;

        if ($wkfMissing && ((int) ($state['cards'][$idx]['wkfUriMisses'] ?? 0) >= self::URI_NOT_FOUND_MAX_CONSECUTIVE)) {
          $state['cards'][$idx]['status'] = 'FAILED';
          $state['cards'][$idx]['detail'] = 'WKF URI not found in KG after multiple checks. Will retry with a new submission in next round.';
          continue;
        }

        if ($datafileMissing && ((int) ($state['cards'][$idx]['dataFileUriMisses'] ?? 0) >= self::URI_NOT_FOUND_MAX_CONSECUTIVE)) {
          $state['cards'][$idx]['status'] = 'FAILED';
          $state['cards'][$idx]['detail'] = 'DataFile URI not found in KG after multiple checks. Will retry with a new submission in next round.';
          continue;
        }

        if ($templateStatus === 'PROCESSED' || $fileStatus === 'PROCESSED') {
          $state['cards'][$idx]['status'] = 'PROCESSED';
          $state['cards'][$idx]['detail'] = 'Processed successfully.';
          $filename = (string) ($card['filename'] ?? '');
          if ($filename !== '') {
            $cache[$filename] = [
              'ingested' => TRUE,
              'wkfUri' => $wkfUri !== '' ? $wkfUri : ($cache[$filename]['wkfUri'] ?? ''),
              'dataFileUri' => $dataFileUri !== '' ? $dataFileUri : ($cache[$filename]['dataFileUri'] ?? ''),
              'updatedAt' => \Drupal::time()->getCurrentTime(),
            ];
          }
          continue;
        }

        if (in_array($templateStatus, ['FAILED', 'ERROR'], TRUE) || in_array($fileStatus, ['FAILED', 'ERROR'], TRUE)) {
          $state['cards'][$idx]['status'] = 'FAILED';
          $state['cards'][$idx]['detail'] = 'Backend status failure. WKF=' . $templateStatus . ', DataFile=' . $fileStatus;
          continue;
        }

        $state['cards'][$idx]['status'] = 'WORKING';
        $state['cards'][$idx]['detail'] = 'Backend processing... WKF=' . $templateStatus . ', DataFile=' . $fileStatus;
        $pending++;
      }

      $this->saveWkfIngestionCache($cache);

      $this->touchState($state, $jobId, 'Round ' . $round . ' monitor attempt ' . $attempt . '.');

      if ($pending === 0) {
        return;
      }
      sleep(self::MONITOR_SLEEP_SECONDS);
    }

    foreach ($state['cards'] as $idx => $card) {
      if (($card['status'] ?? '') === 'WORKING') {
        $state['cards'][$idx]['status'] = 'FAILED';
        $state['cards'][$idx]['detail'] = 'Timed out waiting for PROCESSED status.';
      }
    }
    $this->touchState($state, $jobId, 'Monitoring timeout reached for some WKFs.');
  }

  /**
   * True when card no longer requires ingestion submission.
   */
  private function isCardDone(array $card): bool {
    $status = strtoupper((string) ($card['status'] ?? ''));
    return $status === 'PROCESSED' || $status === 'INGESTED';
  }

  /**
   * Uningest cached WKFs to restart the whole run from scratch.
   */
  private function performCacheBasedUningestion($api, array &$cache, array &$progress, array &$errors): void {
    foreach ($cache as $filename => $entry) {
      $isIngested = !empty($entry['ingested']);
      if (!$isIngested) {
        continue;
      }

      $wkfUri = isset($entry['wkfUri']) ? trim((string) $entry['wkfUri']) : '';
      if ($wkfUri === '') {
        $cache[$filename]['ingested'] = FALSE;
        $cache[$filename]['updatedAt'] = \Drupal::time()->getCurrentTime();
        $progress[] = 'Cache reset to UNINGESTED for ' . $filename . ' (no WKF URI found in cache).';
        continue;
      }

      try {
        $response = $api->uningestMT($wkfUri);
        $decoded = json_decode((string) $response);
        if ($decoded && !empty($decoded->isSuccessful)) {
          $cache[$filename]['ingested'] = FALSE;
          $cache[$filename]['updatedAt'] = \Drupal::time()->getCurrentTime();
          $progress[] = 'Uningested ' . $filename . ' (' . $wkfUri . ').';
        }
        else {
          $errors[] = 'Could not uningest ' . $filename . ' (' . $wkfUri . ').';
          $progress[] = 'Failed to uningest ' . $filename . ' (' . $wkfUri . ').';
        }
      }
      catch (\Throwable $e) {
        $errors[] = 'Exception while uningesting ' . $filename . ': ' . $e->getMessage();
        $progress[] = 'Exception while uningesting ' . $filename . '.';
      }
    }
  }

  /**
   * Fetch URI object without raising connector-side parse noise.
   */
  private function fetchUriObject($api, string $uri): array {
    try {
      $raw = $api->getUri($uri);
      $obj = json_decode((string) $raw);
      if (!$obj || !is_object($obj)) {
        return [
          'found' => FALSE,
          'object' => NULL,
          'notFound' => FALSE,
          'error' => 'Invalid API response',
        ];
      }

      $isSuccessful = !empty($obj->isSuccessful);
      if ($isSuccessful && isset($obj->body) && is_object($obj->body)) {
        return [
          'found' => TRUE,
          'object' => $obj->body,
          'notFound' => FALSE,
          'error' => '',
        ];
      }

      $bodyText = '';
      if (isset($obj->body) && is_string($obj->body)) {
        $bodyText = $obj->body;
      }
      $notFound = stripos($bodyText, 'returned no object from the knowledge graph') !== FALSE;

      return [
        'found' => FALSE,
        'object' => NULL,
        'notFound' => $notFound,
        'error' => $bodyText,
      ];
    }
    catch (\Throwable $e) {
      return [
        'found' => FALSE,
        'object' => NULL,
        'notFound' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Normalize status strings/URIs.
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
    }
    else if (strpos($value, '/') !== FALSE) {
      $value = substr($value, strrpos($value, '/') + 1);
    }
    $token = strtoupper(trim($value));
    if ($token === '') {
      return $default;
    }
    // Check UNPROCESSED/PROCESSING before the generic PROCESS substring match below,
    // otherwise 'UNPROCESSED' would be misdetected as 'PROCESSED' (both contain 'PROCESS').
    if (strpos($token, 'UNPROCESSED') !== FALSE) {
      return 'UNPROCESSED';
    }
    if (strpos($token, 'PROCESSING') !== FALSE) {
      return 'WORKING';
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
   * Inspect existing backend entities to avoid duplicate resubmissions.
   */
  private function inspectExistingBackendState($api, array $card): array {
    $wkfUri = isset($card['wkfUri']) && is_string($card['wkfUri']) ? trim($card['wkfUri']) : '';
    $dataFileUri = isset($card['dataFileUri']) && is_string($card['dataFileUri']) ? trim($card['dataFileUri']) : '';

    if ($wkfUri === '' && $dataFileUri === '') {
      return [
        'shouldSubmit' => TRUE,
        'status' => 'WORKING',
        'detail' => '',
        'wkfUri' => '',
        'dataFileUri' => '',
        'templateStatus' => 'UNKNOWN',
        'fileStatus' => 'UNKNOWN',
      ];
    }

    $templateStatus = 'UNKNOWN';
    $fileStatus = 'UNKNOWN';
    $foundAny = FALSE;

    if ($wkfUri !== '') {
      $wkfLookup = $this->fetchUriObject($api, $wkfUri);
      if (!empty($wkfLookup['found']) && is_object($wkfLookup['object'])) {
        $foundAny = TRUE;
        if (isset($wkfLookup['object']->hasStatus)) {
          $templateStatus = $this->normalizeStatusToken((string) $wkfLookup['object']->hasStatus, 'UNKNOWN');
        }
      }
    }

    if ($dataFileUri !== '') {
      $dfLookup = $this->fetchUriObject($api, $dataFileUri);
      if (!empty($dfLookup['found']) && is_object($dfLookup['object'])) {
        $foundAny = TRUE;
        if (isset($dfLookup['object']->fileStatus)) {
          $fileStatus = $this->normalizeStatusToken((string) $dfLookup['object']->fileStatus, 'UNKNOWN');
        }
        else if (isset($dfLookup['object']->hasStatus)) {
          $fileStatus = $this->normalizeStatusToken((string) $dfLookup['object']->hasStatus, 'UNKNOWN');
        }
      }
    }

    if (!$foundAny) {
      return [
        'shouldSubmit' => TRUE,
        'status' => 'WORKING',
        'detail' => '',
        'wkfUri' => $wkfUri,
        'dataFileUri' => $dataFileUri,
        'templateStatus' => $templateStatus,
        'fileStatus' => $fileStatus,
      ];
    }

    if ($templateStatus === 'PROCESSED' || $fileStatus === 'PROCESSED') {
      return [
        'shouldSubmit' => FALSE,
        'status' => 'PROCESSED',
        'detail' => 'Existing backend submission already processed. Skipping resubmission.',
        'wkfUri' => $wkfUri,
        'dataFileUri' => $dataFileUri,
        'templateStatus' => $templateStatus,
        'fileStatus' => $fileStatus,
      ];
    }

    if ($templateStatus === 'WORKING' || $fileStatus === 'WORKING') {
      return [
        'shouldSubmit' => FALSE,
        'status' => 'WORKING',
        'detail' => 'Existing backend submission detected. Monitoring current processing state.',
        'wkfUri' => $wkfUri,
        'dataFileUri' => $dataFileUri,
        'templateStatus' => $templateStatus,
        'fileStatus' => $fileStatus,
      ];
    }

    // Any other status (UNPROCESSED, DRAFT, FAILED, ERROR, UNKNOWN, ...) means nothing
    // is actively being ingested right now, so this WKF must be (re)submitted.
    return [
      'shouldSubmit' => TRUE,
      'status' => 'WORKING',
      'detail' => '',
      'wkfUri' => $wkfUri,
      'dataFileUri' => $dataFileUri,
      'templateStatus' => $templateStatus,
      'fileStatus' => $fileStatus,
    ];
  }

  /**
   * Generate DataFile URI with fallback.
   */
  private function generateDataFileUriWithFallback($api): string {
    $generated = trim((string) \Drupal\rep\Utils::uriGen('datafile'));
    if ($generated !== '') {
      return $generated;
    }

    $repoNamespace = '';
    try {
      $repoInfoRaw = $api->repoInfo();
      $repoObj = json_decode((string) $repoInfoRaw);
      if ($repoObj && !empty($repoObj->isSuccessful) && isset($repoObj->body->hasDefaultNamespaceURL)) {
        $repoNamespace = \Drupal\rep\Utils::normalizeRepositoryNamespace((string) $repoObj->body->hasDefaultNamespaceURL);
      }
    }
    catch (\Throwable $t) {
      // Ignore and fallback below.
    }

    if ($repoNamespace === '') {
      $repoNamespace = 'https://pmsr.net/ont/';
    }

    $prefix = (string) \Drupal\rep\Utils::elementPrefix('datafile');
    if ($prefix === '') {
      $prefix = 'DFL';
    }
    $suffix = (string) \Drupal::time()->getCurrentTime() . (string) random_int(10000, 99999) . (string) \Drupal::currentUser()->id();
    return $repoNamespace . $prefix . $suffix;
  }

  /**
   * Build linked template URI from DataFile URI.
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
   * Resolve manager email for ingestion submit/query params.
   *
   * The organization is now resolved from each WKF's own content during
   * ingestion, so this only falls back through:
   * 1) provided fallback email,
   * 2) site mail,
   * 3) static admin fallback.
   */
  private function resolveManagerEmailForSubmission(string $fallbackEmail): string {
    $fallback = trim($fallbackEmail);
    if ($fallback !== '' && !filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
      $fallback = '';
    }

    if ($fallback !== '') {
      return $fallback;
    }

    $siteMail = trim((string) (\Drupal::config('system.site')->get('mail') ?? ''));
    if ($siteMail !== '' && filter_var($siteMail, FILTER_VALIDATE_EMAIL)) {
      return $siteMail;
    }

    return 'admin@pmsr.com';
  }

  /**
   * Discover WKF files in module wkf folder.
   */
  private function discoverWkfFiles(): array {
    $modulePath = \Drupal::service('extension.list.module')->getPath('pmsr');
    $wkfDir = DRUPAL_ROOT . '/' . $modulePath . '/wkf';
    if (!is_dir($wkfDir)) {
      return [];
    }

    $entries = scandir($wkfDir);
    if (!is_array($entries)) {
      return [];
    }

    $paths = [];
    foreach ($entries as $entry) {
      if (!is_string($entry) || $entry === '.' || $entry === '..') {
        continue;
      }
      if (!preg_match('/^wkf-.*\.(xlsx|xlsm|xls)$/i', $entry)) {
        continue;
      }
      $fullPath = $wkfDir . '/' . $entry;
      if (!is_file($fullPath)) {
        continue;
      }
      $paths[] = $fullPath;
    }

    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    $files = [];
    foreach ($paths as $path) {
      $files[] = [
        'filename' => basename($path),
        'path' => $path,
      ];
    }
    return $files;
  }

  /**
   * Build state key for one job.
   */
  private function stateKey(string $jobId): string {
    return 'pmsr.wkf_ingestion.job.' . $jobId;
  }

  /**
   * Persist state.
   */
  private function saveJobState(string $jobId, array $state): void {
    \Drupal::state()->set($this->stateKey($jobId), $state);
  }

  /**
   * Load state.
   */
  private function getJobState(string $jobId): ?array {
    $state = \Drupal::state()->get($this->stateKey($jobId));
    return is_array($state) ? $state : NULL;
  }

  /**
   * Return active job id.
   */
  private function getActiveJobId(): ?string {
    $jobId = \Drupal::state()->get(self::ACTIVE_JOB_STATE_KEY);
    return is_string($jobId) && trim($jobId) !== '' ? trim($jobId) : NULL;
  }

  /**
   * Persist active job id.
   */
  private function setActiveJobId(string $jobId): void {
    \Drupal::state()->set(self::ACTIVE_JOB_STATE_KEY, $jobId);
  }

  /**
   * Clear active job id marker.
   */
  private function clearActiveJobId(): void {
    \Drupal::state()->delete(self::ACTIVE_JOB_STATE_KEY);
  }

  /**
   * Load currently active job state, if any.
   */
  private function getActiveJobState(): ?array {
    $jobId = $this->getActiveJobId();
    if ($jobId === NULL) {
      return NULL;
    }
    return $this->getJobState($jobId);
  }

  /**
   * Load WKF ingestion cache keyed by filename.
   */
  private function getWkfIngestionCache(): array {
    $cache = \Drupal::state()->get(self::WKF_CACHE_STATE_KEY);
    return is_array($cache) ? $cache : [];
  }

  /**
   * Persist WKF ingestion cache keyed by filename.
   */
  private function saveWkfIngestionCache(array $cache): void {
    \Drupal::state()->set(self::WKF_CACHE_STATE_KEY, $cache);
  }

  /**
   * Update state heartbeat and message.
   */
  private function touchState(array &$state, string $jobId, string $message): void {
    $state['message'] = $message;
    $state['updatedAt'] = \Drupal::time()->getCurrentTime();
    $this->saveJobState($jobId, $state);
  }

}
