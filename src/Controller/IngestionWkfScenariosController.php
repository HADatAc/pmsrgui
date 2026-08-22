<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\file\Entity\File;
use Drupal\rep\Vocabulary\HASCO;
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
  private const PMSR_PROJECT_URI = 'https://pmsr.net/ont/PJT1742783481383251';

  /**
   * Render the WKF scenarios ingestion page.
   */
  public function content() {
    $wkfFiles = $this->discoverWkfFiles();
    $cache = $this->getWkfIngestionCache();
    $activeState = $this->getActiveJobState();
    $activeJobId = is_array($activeState) ? (string) ($activeState['jobId'] ?? '') : '';
    $requireOrganizationSelection = $this->requiresOrganizationSelection();
    $organizationOptions = $this->loadOrganizationOptions();
    $activeOrganizationUri = is_array($activeState) ? (string) ($activeState['organizationUri'] ?? '') : '';

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
    $output .= '<p>This page ingests all WKF scenario files found in <code>pmsr/wkf</code> using the same PMSR ingestion flow used for individual WKFs.</p>';
    if ($requireOrganizationSelection) {
      $output .= '<div class="card border-info mt-3 mb-3">';
      $output .= '<div class="card-body">';
      $output .= '<h5 class="card-title mb-2">Deployment Organization</h5>';
      $output .= '<p class="text-muted mb-2">Select the organization that the ingested WKFs should belong to.</p>';
      $output .= '<select id="wkf-deploy-organization" class="form-select" style="max-width: 760px;">';
      $output .= '<option value="">Select an organization...</option>';
      foreach ($organizationOptions as $opt) {
        $uri = (string) ($opt['uri'] ?? '');
        $label = (string) ($opt['label'] ?? $uri);
        if ($uri === '') {
          continue;
        }
        $selected = ($activeOrganizationUri !== '' && $activeOrganizationUri === $uri) ? ' selected="selected"' : '';
        $output .= '<option value="' . htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>'
          . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
          . '</option>';
      }
      $output .= '</select>';
      $output .= '</div>';
      $output .= '</div>';
    }
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
              'requireOrganizationSelection' => $requireOrganizationSelection,
              'organizationOptions' => $organizationOptions,
              'selectedOrganizationUri' => $activeOrganizationUri,
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
    $requestedOrganizationUri = is_array($payload) && isset($payload['organizationUri'])
      ? trim((string) $payload['organizationUri'])
      : '';

    $organizationOptions = $this->loadOrganizationOptions();
    $organizationLabelByUri = [];
    foreach ($organizationOptions as $opt) {
      $uri = (string) ($opt['uri'] ?? '');
      if ($uri === '') {
        continue;
      }
      $organizationLabelByUri[$uri] = (string) ($opt['label'] ?? $uri);
    }

    if ($this->requiresOrganizationSelection()) {
      if ($requestedOrganizationUri === '') {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Please select the organization that this WKF ingestion should belong to.',
        ], 400);
      }
      if (!isset($organizationLabelByUri[$requestedOrganizationUri])) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Selected organization is invalid or no longer available.',
        ], 400);
      }
    }

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
        'organizationUri' => $activeState['organizationUri'] ?? '',
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
      'organizationUri' => $requestedOrganizationUri,
      'organizationLabel' => $requestedOrganizationUri !== '' ? ($organizationLabelByUri[$requestedOrganizationUri] ?? $requestedOrganizationUri) : '',
    ];

    $this->saveJobState($jobId, $state);
    $this->setActiveJobId($jobId);

    return new JsonResponse([
      'success' => TRUE,
      'jobId' => $jobId,
      'message' => $state['message'],
      'cards' => $cards,
      'fromScratch' => $fromScratch,
      'organizationUri' => $requestedOrganizationUri,
      'organizationLabel' => $requestedOrganizationUri !== '' ? ($organizationLabelByUri[$requestedOrganizationUri] ?? $requestedOrganizationUri) : '',
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
      'organizationUri' => $state['organizationUri'] ?? '',
      'organizationLabel' => $state['organizationLabel'] ?? '',
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
      $targetOrganizationUri = trim((string) ($state['organizationUri'] ?? ''));

      for ($round = 1; $round <= self::MAX_INGESTION_ROUNDS; $round++) {
        $state['progress'][] = 'Round ' . $round . ': submitting pending WKFs.';

        foreach ($state['cards'] as $idx => $card) {
          if ($this->isCardDone($card)) {
            continue;
          }

          $state['cards'][$idx]['status'] = 'WORKING';
          $state['cards'][$idx]['detail'] = 'Submitting ingestion (round ' . $round . ')...';
          $state['cards'][$idx]['attempts'] = (int) ($state['cards'][$idx]['attempts'] ?? 0) + 1;
          $this->touchState($state, $jobId, 'Submitting ' . ($card['filename'] ?? 'unknown'));

          $submit = $this->submitSingleWkf($api, $card, $targetOrganizationUri);
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
  private function submitSingleWkf($api, array $card, string $targetOrganizationUri = ''): array {
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
      $useremail = $this->resolveManagerEmailForSubmission($api, $targetOrganizationUri, $useremail);
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

    $template = new \stdClass();
    $template->uri = $wkfUri;
    $template->hasDataFileUri = $dataFileUri;
    $template->hasDataFile = new \stdClass();
    $template->hasDataFile->id = $file->id();
    $template->hasDataFile->filename = $filename;
    $template->hasSIRManagerEmail = $useremail;
    if ($targetOrganizationUri !== '') {
      $template->hasOrganizationUri = $targetOrganizationUri;
    }

    $ingestRaw = $api->uploadTemplate('wkf', $template, '_');
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
   * Preference order:
   * 1) curator-like emails affiliated with selected organization,
   * 2) any valid affiliated email,
   * 3) provided fallback email,
   * 4) site mail,
   * 5) static admin fallback.
   */
  private function resolveManagerEmailForSubmission($api, string $organizationUri, string $fallbackEmail): string {
    $fallback = trim($fallbackEmail);
    if ($fallback !== '' && !filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
      $fallback = '';
    }

    if ($organizationUri !== '') {
      try {
        $affRaw = $api->getAffiliations($organizationUri, 300, 0);
        $affObj = $api->parseObjectResponse($affRaw, 'organizationAffiliations');
        $emails = $this->extractEmailsFromAffiliations($affObj);
        if (!empty($emails)) {
          $curatorEmails = array_values(array_filter($emails, static function (string $email): bool {
            return stripos($email, 'curator') !== FALSE;
          }));
          if (!empty($curatorEmails)) {
            return $curatorEmails[0];
          }
          return $emails[0];
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('pmsr')->warning('Could not resolve manager email from organization affiliations for @org: @msg', [
          '@org' => $organizationUri,
          '@msg' => $e->getMessage(),
        ]);
      }
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
   * Extract distinct valid emails from possible affiliation response shapes.
   */
  private function extractEmailsFromAffiliations($affObj): array {
    $rows = [];
    if (is_object($affObj) && isset($affObj->affiliations) && is_array($affObj->affiliations)) {
      $rows = $affObj->affiliations;
    }
    else if (is_object($affObj) && isset($affObj->items) && is_array($affObj->items)) {
      $rows = $affObj->items;
    }
    else if (is_array($affObj)) {
      $rows = $affObj;
    }

    $emails = [];
    foreach ($rows as $row) {
      $candidates = [];

      if (is_object($row)) {
        $candidates[] = isset($row->email) ? (string) $row->email : '';
        $candidates[] = isset($row->managerEmail) ? (string) $row->managerEmail : '';
        $candidates[] = isset($row->hasSIRManagerEmail) ? (string) $row->hasSIRManagerEmail : '';

        if (isset($row->person) && is_object($row->person)) {
          $candidates[] = isset($row->person->email) ? (string) $row->person->email : '';
          $candidates[] = isset($row->person->hasEmail) ? (string) $row->person->hasEmail : '';
          $candidates[] = isset($row->person->hasSIREmail) ? (string) $row->person->hasSIREmail : '';
        }
      }

      foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
          continue;
        }
        $emails[strtolower($candidate)] = $candidate;
      }
    }

    $result = array_values($emails);
    sort($result);
    return $result;
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
   * True when the current user must choose a deployment organization.
   */
  private function requiresOrganizationSelection(): bool {
    return TRUE;
  }

  /**
   * Load organizations available for WKF deployment selection.
   */
  private function loadOrganizationOptions(): array {
    $options = [];

    try {
      $api = \Drupal::service('rep.api_connector');

      // Preferred source: member organizations linked as project contributors.
      $project = $api->parseObjectResponse($api->getUri(self::PMSR_PROJECT_URI), 'getUri');
      $contributorUris = [];
      if (is_object($project) && isset($project->contributorUris) && is_array($project->contributorUris)) {
        $contributorUris = array_values($project->contributorUris);
      }

      foreach ($contributorUris as $contributorUri) {
        $uri = trim((string) $contributorUri);
        if ($uri === '') {
          continue;
        }

        $orgObj = $api->parseObjectResponse($api->getUri($uri), 'getUri');
        if (!is_object($orgObj)) {
          continue;
        }

        $name = trim((string) ($orgObj->name ?? ''));
        $label = trim((string) ($orgObj->label ?? ''));
        $content = trim((string) ($orgObj->hasContent ?? ''));
        $pretty = $name !== '' ? $name : ($label !== '' ? $label : ($content !== '' ? $content : $uri));

        $options[$uri] = [
          'uri' => $uri,
          'label' => $pretty,
        ];
      }

      // Fallback source: generic organization listing.
      if (empty($options)) {
        $url = rtrim((string) $api->getApiUrl(), '/') . '/hascoapi/api/organization/keyword/_/500/0';
        $raw = $api->perform_http_request('GET', $url, [
          'timeout' => 20,
          'connect_timeout' => 3,
          'http_errors' => FALSE,
          'headers' => [
            'Content-Type' => 'application/json',
          ],
        ]);

        $decoded = json_decode((string) $raw, TRUE);
        $rows = [];
        if (is_array($decoded) && !empty($decoded['isSuccessful']) && isset($decoded['body'])) {
          if (is_array($decoded['body'])) {
            $rows = $decoded['body'];
          }
          elseif (is_string($decoded['body'])) {
            $bodyDecoded = json_decode($decoded['body'], TRUE);
            if (is_array($bodyDecoded)) {
              $rows = $bodyDecoded;
            }
          }
        }

        foreach ($rows as $row) {
          if (!is_array($row)) {
            continue;
          }
          $uri = trim((string) ($row['uri'] ?? ''));
          if ($uri === '') {
            continue;
          }
          $name = trim((string) ($row['name'] ?? ''));
          $label = trim((string) ($row['label'] ?? ''));
          $pretty = $name !== '' ? $name : ($label !== '' ? $label : $uri);
          $options[$uri] = [
            'uri' => $uri,
            'label' => $pretty,
          ];
        }
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('pmsr')->warning('Failed to load organization options for WKF ingestion selector: @msg', [
        '@msg' => $e->getMessage(),
      ]);
    }

    usort($options, static function(array $a, array $b): int {
      return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return array_values($options);
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
