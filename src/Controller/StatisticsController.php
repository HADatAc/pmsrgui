<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides statistics page for PMSR.
 *
 * Statistics Data Storage:
 * - Statistics payloads are stored in Drupal state under pmsr.statistics.data.
 * - This storage is intentionally outside Drupal cache bins, so data survives
 *   cache table clears and cache-rebuild operations.
 * - Each stored value includes an updated_at timestamp used for status metadata.
 *
 * Invalidation Model:
 * - Refresh Statistics is the explicit invalidation mechanism.
 * - Starting a new refresh job clears the state-backed statistics payload and
 *   launches asynchronous warmup.
 * - Cache-tag invalidation is still used for dependent rendered fragments.
 *
 * Async Warmup Behavior:
 * - A lock-protected background job computes statistics in stages and writes
 *   results incrementally to the state store.
 * - UI endpoints can read partial values while the job is running.
 */
class StatisticsController extends ControllerBase {

  private const STATS_JOB_STATE_KEY = 'pmsr.statistics.cache_job';
  private const STATS_JOB_LOCK_KEY = 'pmsr.statistics.cache_job.lock';
  private const STATS_DATA_STATE_KEY = 'pmsr.statistics.data';
  private const STATS_PROJECT_URI = 'https://pmsr.net/ont/PJT1742783481383251';
  private const STATS_MEMBERS_DATA_KEY = 'members_data';

  /**
   * Returns all cache tags used by statistics data.
   */
  private function getStatisticsCacheTags(): array {
    return [
      'pmsr_statistics:global',
      'pmsr_statistics:ontologies',
      'pmsr_statistics:classes',
      'pmsr_statistics:instances',
      'pmsr_statistics:people',
      'pmsr_statistics:projects',
      'pmsr_statistics:organizations',
      'pmsr_statistics:instruments',
      'pmsr_statistics:procedures',
      'pmsr_statistics:anatomy',
      'pmsr_statistics:devices',
    ];
  }

  /**
   * Persistent statistics store (survives Drupal cache rebuilds).
   */
  private function getStatisticsDataStore(): array {
    $store = \Drupal::state()->get(self::STATS_DATA_STATE_KEY, []);
    return is_array($store) ? $store : [];
  }

  private function saveStatisticsDataStore(array $store): void {
    \Drupal::state()->set(self::STATS_DATA_STATE_KEY, $store);
  }

  private function setStatisticsDataValue(string $key, $value): void {
    $store = $this->getStatisticsDataStore();
    $store[$key] = [
      'value' => $value,
      'updated_at' => time(),
    ];
    $this->saveStatisticsDataStore($store);
  }

  private function getStatisticsDataValue(string $key, ?int &$updatedAt = NULL) {
    $store = $this->getStatisticsDataStore();
    if (!isset($store[$key]) || !is_array($store[$key]) || !array_key_exists('value', $store[$key])) {
      return NULL;
    }

    $updatedAt = isset($store[$key]['updated_at']) ? (int) $store[$key]['updated_at'] : NULL;
    return $store[$key]['value'];
  }

  private function clearStatisticsDataStore(): void {
    \Drupal::state()->set(self::STATS_DATA_STATE_KEY, []);
  }

  /**
   * Build fresh state for a new async statistics caching job.
   */
  private function createStatisticsCacheJobState(): array {
    $jobId = 'stats-' . time() . '-' . substr(md5((string) mt_rand()), 0, 8);

    return [
      'job_id' => $jobId,
      'status' => 'running',
      'created_at' => time(),
      'updated_at' => time(),
      'heartbeat_at' => time(),
      'completed_at' => NULL,
      'stage' => 'ontologies',
      'stages' => [
        'ontologies',
        'classes',
        'instances',
        'instruments',
        'procedures',
        'anatomy',
        'devices',
        'members_init',
        'members_collect',
        'members_finalize',
      ],
      'attempts' => 0,
      'last_error' => '',
      'last_message' => 'Job created',
      'members' => [
        'contributor_uris' => [],
        'index' => 0,
        'rows' => [],
        'totals' => [
          'registeredUsers' => 0,
          'people' => 0,
          'simulators' => 0,
          'platforms' => 0,
          'scenarios' => 0,
          'processes' => 0,
          'tasksSubtasks' => 0,
        ],
        'attempts_by_uri' => [],
      ],
    ];
  }

  private function getStatisticsCacheJob(): array {
    $state = \Drupal::state()->get(self::STATS_JOB_STATE_KEY);
    return is_array($state) ? $state : [];
  }

  private function saveStatisticsCacheJob(array $job): void {
    $job['updated_at'] = time();
    $job['heartbeat_at'] = time();
    \Drupal::state()->set(self::STATS_JOB_STATE_KEY, $job);
  }

  private function isStatisticsCacheJobRunning(array $job): bool {
    return !empty($job['job_id']) && (($job['status'] ?? '') === 'running');
  }

  private function isStatisticsCacheJobStale(array $job, int $staleSeconds = 120): bool {
    if (!$this->isStatisticsCacheJobRunning($job)) {
      return FALSE;
    }
    $heartbeat = (int) ($job['heartbeat_at'] ?? 0);
    if ($heartbeat <= 0) {
      return TRUE;
    }
    return (time() - $heartbeat) > $staleSeconds;
  }

  /**
   * Estimate job completion percentage from stage and member index checkpoints.
   */
  private function getStatisticsJobProgressInfo(array $job): array {
    if (($job['status'] ?? '') === 'completed' || ($job['stage'] ?? '') === 'done') {
      return [
        'percent' => 100,
        'label' => 'Completed',
      ];
    }

    $stages = isset($job['stages']) && is_array($job['stages']) ? $job['stages'] : [];
    if (empty($stages)) {
      return [
        'percent' => 0,
        'label' => 'Starting',
      ];
    }

    $stage = (string) ($job['stage'] ?? '');

    // Weighted progress so member collection/finalization are visible in 90%+.
    $preMembersStages = [
      'ontologies',
      'classes',
      'instances',
      'instruments',
      'procedures',
      'anatomy',
      'devices',
    ];

    $percent = 1;
    if (in_array($stage, $preMembersStages, TRUE)) {
      $index = array_search($stage, $preMembersStages, TRUE);
      $index = ($index === FALSE) ? 0 : (int) $index;
      // 1%..74%
      $percent = 1 + (int) round(($index / max(1, count($preMembersStages) - 1)) * 73);
    }
    elseif ($stage === 'members_init') {
      $percent = 75;
    }
    elseif ($stage === 'members_collect') {
      $contributors = isset($job['members']['contributor_uris']) && is_array($job['members']['contributor_uris'])
        ? count($job['members']['contributor_uris'])
        : 0;
      $index = (int) ($job['members']['index'] ?? 0);
      $attemptsByUri = isset($job['members']['attempts_by_uri']) && is_array($job['members']['attempts_by_uri'])
        ? $job['members']['attempts_by_uri']
        : [];

      $retryBonus = 0.0;
      if ($contributors > 0 && $index < $contributors) {
        $currentUri = (string) ($job['members']['contributor_uris'][$index] ?? '');
        $currentAttempts = (int) ($attemptsByUri[$currentUri] ?? 0);
        // Up to +0.66 item progress while retrying same contributor.
        $retryBonus = min(0.66, max(0.0, ($currentAttempts - 1) / 3));
      }

      if ($contributors > 0) {
        $fraction = min(1.0, max(0.0, ($index + $retryBonus) / $contributors));
        // 76%..96%
        $percent = 76 + (int) round($fraction * 20);
      } else {
        $percent = 76;
      }
    }
    elseif ($stage === 'members_finalize') {
      $percent = 98;
    }
    elseif ($stage === 'done') {
      $percent = 100;
    }

    if (($job['status'] ?? '') === 'running') {
      $percent = max(1, min(99, $percent));
    } else {
      $percent = max(0, min(100, $percent));
    }

    $label = ucwords(str_replace('_', ' ', $stage !== '' ? $stage : 'running'));
    return [
      'percent' => $percent,
      'label' => $label,
    ];
  }

  /**
   * Trigger worker endpoint in a fire-and-forget way.
   */
  private function triggerStatisticsCacheWorker(string $jobId): void {
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $url = $host . '/pmsr/api/statistics/refresh?worker=1&job=' . rawurlencode($jobId);

    // Use raw socket fire-and-forget so button requests never wait on worker
    // completion (or on session lock contention).
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
      return;
    }

    $scheme = $parts['scheme'] ?? 'http';
    $hostName = $parts['host'];
    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
    $target = $path . $query;
    $port = isset($parts['port']) ? (int) $parts['port'] : (($scheme === 'https') ? 443 : 80);
    $transport = ($scheme === 'https') ? 'ssl://' : '';

    $socket = @fsockopen($transport . $hostName, $port, $errno, $errstr, 0.2);
    if (!$socket) {
      return;
    }

    stream_set_blocking($socket, FALSE);
    $request = "POST {$target} HTTP/1.1\r\n"
      . "Host: {$hostName}\r\n"
      . "Connection: Close\r\n"
      . "Content-Length: 0\r\n"
      . "\r\n";

    fwrite($socket, $request);
    fclose($socket);
  }

  /**
   * Invalidate current statistics cache before warming it again.
   */
  private function resetStatisticsCachesBeforeWarmup(): void {
    // Statistics payload now lives outside cache bins and is reset explicitly.
    $this->clearStatisticsDataStore();

    // Keep tag invalidation for any dependent rendered fragments.
    \Drupal\Core\Cache\Cache::invalidateTags($this->getStatisticsCacheTags());
  }

  private function advanceStatisticsJobStage(array &$job): void {
    $stages = $job['stages'] ?? [];
    $current = $job['stage'] ?? '';
    $idx = array_search($current, $stages, TRUE);
    if ($idx === FALSE || !isset($stages[$idx + 1])) {
      $job['stage'] = 'done';
      return;
    }
    $job['stage'] = $stages[$idx + 1];
  }

  /**
   * Execute one stage of statistics cache warmup.
   */
  private function processStatisticsCacheJob(string $jobId, int $budgetSeconds = 40): void {
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::STATS_JOB_LOCK_KEY, 120)) {
      return;
    }

    try {
      $job = $this->getStatisticsCacheJob();
      if (!$this->isStatisticsCacheJobRunning($job) || ($job['job_id'] ?? '') !== $jobId) {
        return;
      }

      @set_time_limit(0);
      ignore_user_abort(TRUE);

      $api = \Drupal::service('rep.api_connector');
      $deadline = microtime(TRUE) + max(5, $budgetSeconds);

      while (microtime(TRUE) < $deadline && $this->isStatisticsCacheJobRunning($job)) {
        $stage = (string) ($job['stage'] ?? 'done');

        try {
          switch ($stage) {
            case 'ontologies':
              $resp = $api->statisticsOntologiesCount();
              $obj = $api->parseObjectResponse($resp, 'statisticsOntologiesCount');
              if ($obj && isset($obj->total)) {
                $this->setStatisticsDataValue('ontologies', (int) $obj->total);
              }
              $job['last_message'] = 'Cached ontologies count';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'classes':
              $resp = $api->statisticsClassesCount();
              $obj = $api->parseObjectResponse($resp, 'statisticsClassesCount');
              if ($obj && isset($obj->total)) {
                $this->setStatisticsDataValue('classes', (int) $obj->total);
              }
              $job['last_message'] = 'Cached classes count';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'instances':
              $resp = $api->statisticsInstancesCount();
              $obj = $api->parseObjectResponse($resp, 'statisticsInstancesCount');
              if ($obj && isset($obj->total)) {
                $this->setStatisticsDataValue('instances', (int) $obj->total);
              }
              $job['last_message'] = 'Cached instances count';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'instruments':
              $resp = $api->statisticsInstrumentCount();
              $obj = $api->parseObjectResponse($resp, 'statisticsInstrumentCount');
              if ($obj && isset($obj->total)) {
                $this->setStatisticsDataValue('instruments', (int) $obj->total);
              }
              $job['last_message'] = 'Cached instruments count';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'procedures':
              $resp = $api->statisticsProceduresCount();
              $obj = $api->parseObjectResponse($resp, 'statisticsProceduresCount');
              if ($obj && isset($obj->total)) {
                $this->setStatisticsDataValue('procedures', (int) $obj->total);
              }
              $job['last_message'] = 'Cached procedures count';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'anatomy':
              $resp = $api->statisticsAnatomyCount();
              $obj = $api->parseObjectResponse($resp, 'statisticsAnatomyCount');
              if ($obj && isset($obj->total)) {
                $this->setStatisticsDataValue('anatomy', (int) $obj->total);
              }
              $job['last_message'] = 'Cached anatomy count';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'devices':
              $resp = $api->statisticsMedicalDevicesCount();
              $obj = $api->parseObjectResponse($resp, 'statisticsMedicalDevicesCount');
              if ($obj && isset($obj->total)) {
                $this->setStatisticsDataValue('devices', (int) $obj->total);
              }
              $job['last_message'] = 'Cached medical devices count';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'members_init':
              $projectData = $this->fetchUriObjectWithRetry($api, self::STATS_PROJECT_URI, 4);
              $job['members']['contributor_uris'] = (isset($projectData->contributorUris) && is_array($projectData->contributorUris)) ? array_values($projectData->contributorUris) : [];
              $job['members']['index'] = 0;
              $job['members']['rows'] = [];
              $job['members']['totals'] = [
                'registeredUsers' => 0,
                'people' => 0,
                'simulators' => 0,
                'platforms' => 0,
                'scenarios' => 0,
                'processes' => 0,
                'tasksSubtasks' => 0,
              ];
              $job['members']['attempts_by_uri'] = [];
              $job['last_message'] = 'Initialized members collection';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'members_collect':
              $contributors = $job['members']['contributor_uris'] ?? [];
              $index = (int) ($job['members']['index'] ?? 0);

              if ($index >= count($contributors)) {
                $this->advanceStatisticsJobStage($job);
                $job['last_message'] = 'Collected all member rows';
                $this->saveStatisticsCacheJob($job);
                break;
              }

              $contributorUri = (string) $contributors[$index];
              $attemptsByUri = $job['members']['attempts_by_uri'] ?? [];
              $attemptNum = (int) ($attemptsByUri[$contributorUri] ?? 0) + 1;
              $attemptsByUri[$contributorUri] = $attemptNum;
              $job['members']['attempts_by_uri'] = $attemptsByUri;
              $this->saveStatisticsCacheJob($job);

              try {
                $row = $this->buildMemberStatisticsRow($api, $contributorUri);
                $job['members']['rows'][] = $row;
                $job['members']['totals']['registeredUsers'] += (int) ($row['registeredUsersCount'] ?? 0);
                $job['members']['totals']['people'] += (int) ($row['peopleCount'] ?? 0);
                $job['members']['totals']['simulators'] += (int) ($row['simulatorCount'] ?? 0);
                $job['members']['totals']['platforms'] += (int) ($row['platformCount'] ?? 0);
                $job['members']['totals']['scenarios'] += (int) ($row['registeredScenariosCount'] ?? 0);
                $job['members']['totals']['processes'] += (int) ($row['registeredProcessesCount'] ?? 0);
                $job['members']['totals']['tasksSubtasks'] += (int) ($row['registeredTasksSubtasksCount'] ?? 0);
                $job['members']['index'] = $index + 1;
                $job['last_message'] = 'Processed contributor ' . ($index + 1) . '/' . count($contributors);
                $this->saveStatisticsCacheJob($job);
              }
              catch (\Exception $e) {
                $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
                $job['last_error'] = $e->getMessage();
                if ($attemptNum >= 3) {
                  // Skip after a few attempts so job can continue to completion.
                  $job['members']['index'] = $index + 1;
                }
                $this->saveStatisticsCacheJob($job);
                // Stop current run and retry later to avoid hammering backend.
                break 2;
              }

              break;

            case 'members_finalize':
              $this->setStatisticsDataValue(self::STATS_MEMBERS_DATA_KEY, [
                'members' => $job['members']['rows'] ?? [],
                'totals' => $job['members']['totals'] ?? [],
              ]);
              $job['last_message'] = 'Finalized members cache';
              $this->advanceStatisticsJobStage($job);
              $this->saveStatisticsCacheJob($job);
              break;

            case 'done':
            default:
              $job['status'] = 'completed';
              $job['completed_at'] = time();
              $job['last_message'] = 'Statistics cache warmup completed';
              $this->saveStatisticsCacheJob($job);
              break 2;
          }
        }
        catch (\Exception $e) {
          $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
          $job['last_error'] = $e->getMessage();
          $job['last_message'] = 'Stage failed: ' . ($job['stage'] ?? 'unknown');
          $this->saveStatisticsCacheJob($job);
          break;
        }
      }

      // Keep job alive with self-chaining until completion.
      $job = $this->getStatisticsCacheJob();
      if ($this->isStatisticsCacheJobRunning($job) && ($job['job_id'] ?? '') === $jobId) {
        $this->triggerStatisticsCacheWorker($jobId);
      }
    }
    finally {
      $lock->release(self::STATS_JOB_LOCK_KEY);
    }
  }

  /**
   * Worker endpoint target for background cache warmup.
   */
  public function statisticsCacheWorker(string $jobId) {
    $job = $this->getStatisticsCacheJob();
    if (!$this->isStatisticsCacheJobRunning($job) || ($job['job_id'] ?? '') !== $jobId) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'No matching running statistics cache job',
      ], 404);
    }

    $this->processStatisticsCacheJob($jobId, 40);

    $latest = $this->getStatisticsCacheJob();
    return new JsonResponse([
      'success' => TRUE,
      'job_id' => $jobId,
      'status' => $latest['status'] ?? 'unknown',
      'stage' => $latest['stage'] ?? 'unknown',
      'message' => $latest['last_message'] ?? '',
    ]);
  }

  /**
   * Get platform count for an organization.
   * Counts platform instances where vstoi:partOf equals the organization URI.
   */
  private function getPlatformCountByOrganization($api, $orgUri) {
    $count = 0;
    $pageSize = 100;
    $offset = 0;
    $hasMore = true;
    
    try {
      while ($hasMore) {
        // Get platform instances (using keyword '_' to get all)
        $response = $api->listByKeyword('platforminstance', '_', $pageSize, $offset);
        $platforms = $api->parseObjectResponse($response, 'listByKeyword');
        
        if (!is_array($platforms) || empty($platforms)) {
          $hasMore = false;
          break;
        }
        
        // Filter platforms by organization (vstoi:partOf)
        foreach ($platforms as $platform) {
          if (is_object($platform) && !empty($platform->partOf) && $platform->partOf === $orgUri) {
            $count++;
          }
        }
        
        // Check if we need to fetch more
        if (count($platforms) < $pageSize) {
          $hasMore = false;
        } else {
          $offset += $pageSize;
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('pmsr')->warning('Failed to count platforms for ' . $orgUri . ': ' . $e->getMessage());
    }
    
    return $count;
  }

  /**
   * Get simulator count for an organization.
   * Counts unique instruments deployed on platforms owned by the organization.
   */
  private function getSimulatorCountByOrganization($api, $orgUri) {
    $uniqueInstruments = [];
    $pageSize = 100;
    
    try {
      // First, get all platform instances for this organization
      $platforms = [];
      $offset = 0;
      $hasMore = true;
      
      while ($hasMore) {
        $response = $api->listByKeyword('platforminstance', '_', $pageSize, $offset);
        $batch = $api->parseObjectResponse($response, 'listByKeyword');
        
        if (!is_array($batch) || empty($batch)) {
          $hasMore = false;
          break;
        }
        
        // Filter platforms by organization
        foreach ($batch as $platform) {
          if (is_object($platform) && !empty($platform->partOf) && $platform->partOf === $orgUri && !empty($platform->uri)) {
            $platforms[] = $platform->uri;
          }
        }
        
        if (count($batch) < $pageSize) {
          $hasMore = false;
        } else {
          $offset += $pageSize;
        }
      }
      
      // Now get deployments for each platform and count unique instruments
      foreach ($platforms as $platformUri) {
        $depOffset = 0;
        $hasMoreDep = true;
        
        while ($hasMoreDep) {
          $depResponse = $api->deploymentsByPlatformInstanceWithPage($platformUri, $pageSize, $depOffset);
          $deployments = $api->parseObjectResponse($depResponse, 'deploymentsByPlatformInstanceWithPage');
          
          if (!is_array($deployments) || empty($deployments)) {
            $hasMoreDep = false;
            break;
          }
          
          // Extract instrument URIs from deployments
          foreach ($deployments as $deployment) {
            if (is_object($deployment) && !empty($deployment->instrumentInstanceUri)) {
              $uniqueInstruments[$deployment->instrumentInstanceUri] = true;
            }
          }
          
          if (count($deployments) < $pageSize) {
            $hasMoreDep = false;
          } else {
            $depOffset += $pageSize;
          }
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('pmsr')->warning('Failed to count simulators for ' . $orgUri . ': ' . $e->getMessage());
    }
    
    return count($uniqueInstruments);
  }

  /**
   * Get total people count for an organization including all sub-organizations.
   */
  private function getTotalPeopleCount($api, $orgUri) {
    $totalCount = 0;
    
    // Get direct affiliations for this organization
    try {
      $affiliationsResponse = $api->getTotalAffiliations($orgUri);
      $decoded = NULL;
      if (is_string($affiliationsResponse)) {
        $decoded = json_decode($affiliationsResponse);
      }
      elseif (is_object($affiliationsResponse) || is_array($affiliationsResponse)) {
        $decoded = json_decode(json_encode($affiliationsResponse));
      }
      
      if (is_object($decoded) && !empty($decoded->isSuccessful)) {
        $body = $decoded->body ?? NULL;
        if (is_string($body)) {
          $body = json_decode($body);
        }
        
        if (is_object($body) && isset($body->total) && is_numeric($body->total)) {
          $totalCount += (int) $body->total;
        }
        elseif (is_array($body) && isset($body['total']) && is_numeric($body['total'])) {
          $totalCount += (int) $body['total'];
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('pmsr')->warning('Failed to fetch affiliations for ' . $orgUri . ': ' . $e->getMessage());
    }
    
    // Get sub-organizations and their people counts
    try {
      $subOrgsResponse = $api->getSubOrganizations($orgUri, 100, 0);
      $subOrgsData = $api->parseObjectResponse($subOrgsResponse, 'getSubOrganizations');
      
      if (is_array($subOrgsData)) {
        foreach ($subOrgsData as $subOrg) {
          if (is_object($subOrg) && !empty($subOrg->uri)) {
            // Recursively get count for sub-organization
            $totalCount += $this->getTotalPeopleCount($api, $subOrg->uri);
          }
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('pmsr')->warning('Failed to fetch sub-organizations for ' . $orgUri . ': ' . $e->getMessage());
    }
    
    return $totalCount;
  }

  /**
   * Get registered PMSR users count for an organization.
   * Counts only people with user information (hasco:userID populated).
   */
  private function getRegisteredUsersCount($api, $orgUri) {
    $registeredCount = 0;
    $pageSize = 100;
    $offset = 0;
    
    try {
      // Fetch all people affiliated with this organization
      while (true) {
        $response = $api->getAffiliations($orgUri, $pageSize, $offset);
        $people = $api->parseObjectResponse($response, 'getAffiliations');
        
        if (!is_array($people) || empty($people)) {
          break;
        }
        
        // Check each person for userID by fetching their full details
        foreach ($people as $person) {
          if (is_object($person) && !empty($person->uri)) {
            // Fetch full person details to check for userID
            try {
              $personResponse = $api->getUri($person->uri);
              $personData = $api->parseObjectResponse($personResponse, 'getUri');
              
              // Check if this person has userID (registered PMSR user)
              if (is_object($personData) && !empty($personData->userID)) {
                $registeredCount++;
              }
            } catch (\Exception $e) {
              // Skip if person details can't be fetched
              continue;
            }
          }
        }
        
        // Check if we need to fetch more
        if (count($people) < $pageSize) {
          break;
        }
        $offset += $pageSize;
      }
      
      // Recursively count for sub-organizations
      $subOrgsResponse = $api->getSubOrganizations($orgUri, 100, 0);
      $subOrgsData = $api->parseObjectResponse($subOrgsResponse, 'getSubOrganizations');
      
      if (is_array($subOrgsData)) {
        foreach ($subOrgsData as $subOrg) {
          if (is_object($subOrg) && !empty($subOrg->uri)) {
            $registeredCount += $this->getRegisteredUsersCount($api, $subOrg->uri);
          }
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('pmsr')->warning('Failed to fetch registered users for ' . $orgUri . ': ' . $e->getMessage());
    }
    
    return $registeredCount;
  }

  /**
   * Get all organization URIs in the hierarchy rooted at $orgUri.
   */
  private function getOrganizationHierarchyUris($api, $orgUri, array &$visited = []) {
    $uris = [];
    if (empty($orgUri) || isset($visited[$orgUri])) {
      return $uris;
    }

    $visited[$orgUri] = true;
    $uris[] = $orgUri;

    try {
      $subOrgsResponse = $api->getSubOrganizations($orgUri, 100, 0);
      $subOrgsData = $api->parseObjectResponse($subOrgsResponse, 'getSubOrganizations');
      if (is_array($subOrgsData)) {
        foreach ($subOrgsData as $subOrg) {
          if (is_object($subOrg) && !empty($subOrg->uri)) {
            $uris = array_merge($uris, $this->getOrganizationHierarchyUris($api, $subOrg->uri, $visited));
          }
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('pmsr')->warning('Failed to fetch organization hierarchy for ' . $orgUri . ': ' . $e->getMessage());
    }

    return $uris;
  }

  /**
   * Normalize and extract emails from a person-like object.
   */
  private function extractEmailsFromPersonObject($personObj) {
    $emails = [];
    if (!is_object($personObj)) {
      return $emails;
    }

    $candidates = [];
    if (!empty($personObj->userEmail)) {
      $candidates[] = $personObj->userEmail;
    }
    if (!empty($personObj->mbox)) {
      $candidates[] = $personObj->mbox;
    }
    if (!empty($personObj->hasSIRManagerEmail)) {
      $candidates[] = $personObj->hasSIRManagerEmail;
    }

    foreach ($candidates as $candidate) {
      $normalized = $this->normalizeEmailValue($candidate);
      if ($normalized !== '') {
        $emails[$normalized] = true;
      }
    }

    return $emails;
  }

  /**
   * Normalize a raw email-like value for comparison.
   */
  private function normalizeEmailValue($value) {
    $email = strtolower(trim((string) $value));
    if ($email === '') {
      return '';
    }

    // Flatten markdown/noisy wrappers before extraction.
    $email = str_replace(['*', '[', ']', '(', ')', '<', '>'], ' ', $email);
    $email = preg_replace('/\s+/', ' ', $email);

    if (strpos($email, 'mailto:') === 0) {
      $email = substr($email, 7);
    }

    // Extract query manageremail value when present.
    if (preg_match('/(?:^|[?&])manageremail=([^&\s]+)/i', $email, $m)) {
      $email = trim((string) $m[1]);
    }

    // Defensive cleanup for malformed values such as
    // "user@example.org?manageremail=user@example.org".
    $qPos = strpos($email, '?');
    if ($qPos !== FALSE) {
      $email = substr($email, 0, $qPos);
    }

    // Extract first email-looking token from labels such as
    // "pi: user@example.org" or "principal investigator user@example.org".
    if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $email, $m)) {
      $email = strtolower(trim((string) $m[0]));
    }

    return trim($email);
  }

  /**
   * Extract possible process URIs from a scenario object.
   */
  private function extractProcessUrisFromScenarioObject($scenarioObj) {
    $uris = [];
    if (!is_object($scenarioObj)) {
      return $uris;
    }

    $candidates = [];
    if (!empty($scenarioObj->processUri)) {
      $candidates[] = $scenarioObj->processUri;
    }
    if (!empty($scenarioObj->hasProcessUri)) {
      $candidates[] = $scenarioObj->hasProcessUri;
    }
    if (!empty($scenarioObj->process) && is_object($scenarioObj->process) && !empty($scenarioObj->process->uri)) {
      $candidates[] = $scenarioObj->process->uri;
    }

    foreach ($candidates as $candidate) {
      $uri = trim((string) $candidate);
      if ($uri !== '') {
        $uris[$uri] = true;
      }
    }

    return array_keys($uris);
  }

  /**
   * Extract possible manager/contact emails from a ProcessBasedStudy-like object.
   */
  private function extractEmailsFromScenarioObject($scenarioObj) {
    $emails = [];
    if (!is_object($scenarioObj)) {
      return $emails;
    }

    $candidates = [];
    if (!empty($scenarioObj->hasSIRManagerEmail)) {
      $candidates[] = $scenarioObj->hasSIRManagerEmail;
    }
    if (!empty($scenarioObj->principalInvestigator)) {
      $candidates[] = $scenarioObj->principalInvestigator;
    }
    if (!empty($scenarioObj->contactEmail)) {
      $candidates[] = $scenarioObj->contactEmail;
    }
    if (isset($scenarioObj->process) && is_object($scenarioObj->process) && !empty($scenarioObj->process->hasSIRManagerEmail)) {
      $candidates[] = $scenarioObj->process->hasSIRManagerEmail;
    }

    foreach ($candidates as $candidate) {
      $normalized = $this->normalizeEmailValue($candidate);
      if ($normalized !== '') {
        $emails[$normalized] = true;
      }
    }

    return $emails;
  }

  /**
   * Get all manager emails affiliated with an organization and sub-organizations.
   */
  private function getOrganizationManagerEmails($api, $orgUri) {
    $emails = [];
    $visited = [];
    $allOrgUris = $this->getOrganizationHierarchyUris($api, $orgUri, $visited);

    foreach ($allOrgUris as $currentOrgUri) {
      $pageSize = 100;
      $offset = 0;
      while (true) {
        try {
          $response = $api->getAffiliations($currentOrgUri, $pageSize, $offset);
          $people = $api->parseObjectResponse($response, 'getAffiliations');
          if (!is_array($people) || empty($people)) {
            break;
          }

          foreach ($people as $person) {
            if (!is_object($person)) {
              continue;
            }

            $localEmails = $this->extractEmailsFromPersonObject($person);
            foreach ($localEmails as $email => $dummy) {
              $emails[$email] = true;
            }

            // Enrich with full person record if needed.
            if (empty($localEmails) && !empty($person->uri)) {
              try {
                $personResponse = $api->getUri($person->uri);
                $personData = $api->parseObjectResponse($personResponse, 'getUri');
                $fullEmails = $this->extractEmailsFromPersonObject($personData);
                foreach ($fullEmails as $email => $dummy) {
                  $emails[$email] = true;
                }
              } catch (\Exception $e) {
                // Best effort; continue.
              }
            }
          }

          if (count($people) < $pageSize) {
            break;
          }
          $offset += $pageSize;
        } catch (\Exception $e) {
          \Drupal::logger('pmsr')->warning('Failed to fetch affiliations for ' . $currentOrgUri . ': ' . $e->getMessage());
          break;
        }
      }
    }

    return array_keys($emails);
  }

  /**
   * Collect unique element URIs by manager emails.
   */
  private function collectElementUrisByManagerEmails($api, array $emails, $elementType) {
    $uris = [];
    if (empty($emails) || empty($elementType)) {
      return $uris;
    }

    $pageSize = 100;
    foreach ($emails as $email) {
      $offset = 0;
      while (true) {
        try {
          $response = $api->listByManagerEmail($elementType, $email, $pageSize, $offset);
          $elements = $api->parseObjectResponse($response, 'listByManagerEmail');
          if (!is_array($elements) || empty($elements)) {
            break;
          }

          foreach ($elements as $element) {
            if (is_object($element) && !empty($element->uri)) {
              $uris[$element->uri] = true;
            }
          }

          if (count($elements) < $pageSize) {
            break;
          }
          $offset += $pageSize;
        } catch (\Exception $e) {
          \Drupal::logger('pmsr')->warning('Failed to list ' . $elementType . ' by manager email ' . $email . ': ' . $e->getMessage());
          break;
        }
      }
    }

    return array_keys($uris);
  }

  /**
   * Collect ProcessBasedStudy URIs that match any manager email.
   */
  private function collectProcessBasedStudyUrisByManagerEmails($api, array $emails) {
    $uris = [];
    if (empty($emails)) {
      return $uris;
    }

    $emailSet = [];
    foreach ($emails as $email) {
      $normalized = $this->normalizeEmailValue($email);
      if ($normalized !== '') {
        $emailSet[$normalized] = true;
      }
    }

    $pageSize = 100;
    $offset = 0;
    while (true) {
      try {
        $endpoint = '/hascoapi/api/processbasedstudy/elements/' . $pageSize . '/' . $offset;
        $response = $api->perform_http_request('GET', $api->getApiUrl() . $endpoint, $api->getHeader());
        $items = $api->parseObjectResponse($response, 'getProcessBasedStudiesWithPage');
        if (!is_array($items) || empty($items)) {
          break;
        }

        foreach ($items as $item) {
          if (!is_object($item) || empty($item->uri)) {
            continue;
          }
          $scenarioEmails = $this->extractEmailsFromScenarioObject($item);
          $matches = false;
          foreach ($scenarioEmails as $scenarioEmail => $dummy) {
            if (isset($emailSet[$scenarioEmail])) {
              $matches = true;
              break;
            }
          }

          if ($matches) {
            $uris[$item->uri] = true;
          }
        }

        if (count($items) < $pageSize) {
          break;
        }
        $offset += $pageSize;
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->warning('Failed to list ProcessBasedStudy scenarios: ' . $e->getMessage());
        break;
      }
    }

    return array_keys($uris);
  }

  /**
   * Collect scenario URIs: Study plus known Study subclasses (e.g., ProcessBasedStudy).
   */
  private function collectScenarioUrisByManagerEmails($api, array $emails) {
    $scenarioUris = [];

    $studyUris = $this->collectElementUrisByManagerEmails($api, $emails, 'study');
    foreach ($studyUris as $uri) {
      if (!empty($uri)) {
        $scenarioUris[$uri] = true;
      }
    }

    $pbsUris = $this->collectProcessBasedStudyUrisByManagerEmails($api, $emails);
    foreach ($pbsUris as $uri) {
      if (!empty($uri)) {
        $scenarioUris[$uri] = true;
      }
    }

    return array_keys($scenarioUris);
  }

  /**
   * Collect scenario and process URIs for manager emails.
   *
   * Process discovery is a union of:
   * - direct manager-email process listings
   * - process references found on matched scenario objects.
   */
  private function collectScenarioAndProcessStatsByManagerEmails($api, array $emails) {
    $scenarioUris = [];
    $processUris = [];

    $studyUris = $this->collectElementUrisByManagerEmails($api, $emails, 'study');
    foreach ($studyUris as $studyUri) {
      if (empty($studyUri)) {
        continue;
      }
      $scenarioUris[$studyUri] = true;

      try {
        $studyObj = $this->fetchUriObjectWithRetry($api, $studyUri, 2);
        foreach ($this->extractProcessUrisFromScenarioObject($studyObj) as $processUri) {
          $processUris[$processUri] = true;
        }
      }
      catch (\Exception $e) {
        // Best effort only.
      }
    }

    $emailSet = [];
    foreach ($emails as $email) {
      $normalized = $this->normalizeEmailValue($email);
      if ($normalized !== '') {
        $emailSet[$normalized] = true;
      }
    }

    $pageSize = 100;
    $offset = 0;
    while (true) {
      try {
        $endpoint = '/hascoapi/api/processbasedstudy/elements/' . $pageSize . '/' . $offset;
        $response = $api->perform_http_request('GET', $api->getApiUrl() . $endpoint, $api->getHeader());
        $items = $api->parseObjectResponse($response, 'getProcessBasedStudiesWithPage');
        if (!is_array($items) || empty($items)) {
          break;
        }

        foreach ($items as $item) {
          if (!is_object($item) || empty($item->uri)) {
            continue;
          }

          $scenarioEmails = $this->extractEmailsFromScenarioObject($item);
          $matches = false;
          foreach ($scenarioEmails as $scenarioEmail => $dummy) {
            if (isset($emailSet[$scenarioEmail])) {
              $matches = true;
              break;
            }
          }

          if (!$matches) {
            continue;
          }

          $scenarioUris[$item->uri] = true;
          foreach ($this->extractProcessUrisFromScenarioObject($item) as $processUri) {
            $processUris[$processUri] = true;
          }
        }

        if (count($items) < $pageSize) {
          break;
        }
        $offset += $pageSize;
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->warning('Failed to list ProcessBasedStudy scenarios for scenario/process stats: ' . $e->getMessage());
        break;
      }
    }

    $directProcessUris = $this->collectElementUrisByManagerEmails($api, $emails, 'process');
    foreach ($directProcessUris as $processUri) {
      if (!empty($processUri)) {
        $processUris[$processUri] = true;
      }
    }

    return [
      'scenario_uris' => array_keys($scenarioUris),
      'process_uris' => array_keys($processUris),
    ];
  }

  /**
   * Count unique tasks/subtasks for a set of processes.
   */
  private function countTasksByProcessUris($api, array $processUris) {
    $taskUris = [];
    if (empty($processUris)) {
      return 0;
    }

    foreach ($processUris as $processUri) {
      if (empty($processUri)) {
        continue;
      }

      try {
        $endpoint = '/hascoapi/api/process/' . rawurlencode($processUri) . '/tasks';
        $response = $api->perform_http_request('GET', $api->getApiUrl() . $endpoint, $api->getHeader());
        $taskPayload = $api->parseObjectResponse($response, 'getTasksByProcess');

        if (is_object($taskPayload) && isset($taskPayload->tasks) && is_array($taskPayload->tasks)) {
          foreach ($taskPayload->tasks as $task) {
            if (is_object($task) && !empty($task->uri)) {
              $taskUris[$task->uri] = true;
            }
          }
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->warning('Failed to count tasks for process ' . $processUri . ': ' . $e->getMessage());
      }
    }

    return count($taskUris);
  }

  /**
   * Build an empty members totals array.
   */
  private function createEmptyMemberTotals(): array {
    return [
      'registeredUsers' => 0,
      'people' => 0,
      'simulators' => 0,
      'platforms' => 0,
      'scenarios' => 0,
      'processes' => 0,
      'tasksSubtasks' => 0,
    ];
  }

  /**
   * Build one member row for statistics cache.
   */
  private function buildMemberStatisticsRow($api, string $contributorUri): array {
    $orgData = $this->fetchUriObjectWithRetry($api, $contributorUri, 3);

    $repModulePath = 
      \Drupal::service('extension.list.module')->getPath('rep');
    $placeholderImage = base_path() . $repModulePath . '/images/organization_placeholder.png';
    $imageUrl = $placeholderImage;
    if ($orgData && !empty($orgData->hasImageUri)) {
      $imageUrl = Utils::getAPIImage($contributorUri, $orgData->hasImageUri, $placeholderImage);
    }

    $peopleCount = $this->getTotalPeopleCount($api, $contributorUri);
    $registeredUsersCount = $this->getRegisteredUsersCount($api, $contributorUri);
    $platformCount = $this->getPlatformCountByOrganization($api, $contributorUri);
    $simulatorCount = $this->getSimulatorCountByOrganization($api, $contributorUri);
    $managerEmails = $this->getOrganizationManagerEmails($api, $contributorUri);
    $scenarioStats = $this->collectScenarioAndProcessStatsByManagerEmails($api, $managerEmails);
    $studyUris = $scenarioStats['scenario_uris'] ?? [];
    $processUris = $scenarioStats['process_uris'] ?? [];
    $tasksAndSubtasksCount = $this->countTasksByProcessUris($api, $processUris);
    $fallbackLabel = $this->labelFromUri($contributorUri);

    return [
      'uri' => $contributorUri,
      'label' => $orgData->label ?? $fallbackLabel,
      'shortName' => $orgData->hasShortName ?? $orgData->label ?? $fallbackLabel,
      'fullName' => $orgData->name ?? $orgData->label ?? $fallbackLabel,
      'image' => $imageUrl,
      'peopleCount' => $peopleCount,
      'registeredUsersCount' => $registeredUsersCount,
      'platformCount' => $platformCount,
      'simulatorCount' => $simulatorCount,
      'registeredScenariosCount' => count($studyUris),
      'registeredProcessesCount' => count($processUris),
      'registeredTasksSubtasksCount' => $tasksAndSubtasksCount,
    ];
  }

  /**
   * Recompute aggregate totals from member rows.
   */
  private function buildMemberTotalsFromRows(array $rows): array {
    $totals = $this->createEmptyMemberTotals();

    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $totals['registeredUsers'] += (int) ($row['registeredUsersCount'] ?? 0);
      $totals['people'] += (int) ($row['peopleCount'] ?? 0);
      $totals['simulators'] += (int) ($row['simulatorCount'] ?? 0);
      $totals['platforms'] += (int) ($row['platformCount'] ?? 0);
      $totals['scenarios'] += (int) ($row['registeredScenariosCount'] ?? 0);
      $totals['processes'] += (int) ($row['registeredProcessesCount'] ?? 0);
      $totals['tasksSubtasks'] += (int) ($row['registeredTasksSubtasksCount'] ?? 0);
    }

    return $totals;
  }

  /**
   * Resolve contributor URIs whose organization tree includes the manager email.
   */
  private function resolveContributorUrisByManagerEmail($api, array $contributorUris, string $managerEmail): array {
    $matched = [];
    $normalizedTarget = $this->normalizeEmailValue($managerEmail);
    if ($normalizedTarget === '') {
      return $matched;
    }

    foreach ($contributorUris as $contributorUri) {
      if (empty($contributorUri)) {
        continue;
      }

      try {
        $emails = $this->getOrganizationManagerEmails($api, (string) $contributorUri);
      }
      catch (\Exception $e) {
        continue;
      }

      foreach ($emails as $email) {
        if ($this->normalizeEmailValue($email) === $normalizedTarget) {
          $matched[] = (string) $contributorUri;
          break;
        }
      }
    }

    return array_values(array_unique($matched));
  }

  /**
   * Recompute and persist members statistics cache.
   */
  private function recomputeMembersStatisticsCache($api, ?string $contributorUri = NULL, ?string $managerEmail = NULL): array {
    $projectData = $this->fetchUriObjectWithRetry($api, self::STATS_PROJECT_URI, 4);
    $contributorUris = (isset($projectData->contributorUris) && is_array($projectData->contributorUris))
      ? array_values($projectData->contributorUris)
      : [];

    $storedMembersData = $this->getStatisticsDataValue(self::STATS_MEMBERS_DATA_KEY);
    $membersByUri = [];
    if (is_array($storedMembersData) && isset($storedMembersData['members']) && is_array($storedMembersData['members'])) {
      foreach ($storedMembersData['members'] as $row) {
        if (is_array($row) && !empty($row['uri'])) {
          $membersByUri[(string) $row['uri']] = $row;
        }
      }
    }

    $targetUris = [];
    $normalizedContributorUri = trim((string) ($contributorUri ?? ''));
    $normalizedManagerEmail = $this->normalizeEmailValue((string) ($managerEmail ?? ''));

    if ($normalizedContributorUri !== '') {
      $targetUris[] = $normalizedContributorUri;
    }
    elseif ($normalizedManagerEmail !== '') {
      $targetUris = $this->resolveContributorUrisByManagerEmail($api, $contributorUris, $normalizedManagerEmail);
    }

    $doFullRecompute = empty($targetUris) || empty($membersByUri);
    $updatedUris = [];
    $errors = [];

    if ($doFullRecompute) {
      $membersByUri = [];
      foreach ($contributorUris as $uri) {
        $uri = (string) $uri;
        if ($uri === '') {
          continue;
        }
        try {
          $membersByUri[$uri] = $this->buildMemberStatisticsRow($api, $uri);
          $updatedUris[] = $uri;
        }
        catch (\Exception $e) {
          $errors[] = 'Failed to update member row for ' . $uri . ': ' . $e->getMessage();
        }
      }
    }
    else {
      // Keep only rows for current contributors.
      $contributorSet = [];
      foreach ($contributorUris as $uri) {
        $contributorSet[(string) $uri] = true;
      }
      foreach (array_keys($membersByUri) as $uri) {
        if (!isset($contributorSet[$uri])) {
          unset($membersByUri[$uri]);
        }
      }

      // Ensure missing contributors are eventually added.
      foreach ($contributorUris as $uri) {
        $uri = (string) $uri;
        if (!isset($membersByUri[$uri])) {
          $targetUris[] = $uri;
        }
      }
      $targetUris = array_values(array_unique($targetUris));

      foreach ($targetUris as $uri) {
        if (!in_array($uri, $contributorUris, TRUE)) {
          continue;
        }
        try {
          $membersByUri[$uri] = $this->buildMemberStatisticsRow($api, $uri);
          $updatedUris[] = $uri;
        }
        catch (\Exception $e) {
          $errors[] = 'Failed to update member row for ' . $uri . ': ' . $e->getMessage();
        }
      }
    }

    $orderedRows = [];
    foreach ($contributorUris as $uri) {
      $uri = (string) $uri;
      if (isset($membersByUri[$uri])) {
        $orderedRows[] = $membersByUri[$uri];
      }
    }

    $snapshot = [
      'members' => $orderedRows,
      'totals' => $this->buildMemberTotalsFromRows($orderedRows),
    ];

    $this->setStatisticsDataValue(self::STATS_MEMBERS_DATA_KEY, $snapshot);
    \Drupal\Core\Cache\Cache::invalidateTags($this->getStatisticsCacheTags());

    return [
      'snapshot' => $snapshot,
      'full_recompute' => $doFullRecompute,
      'updated_uris' => array_values(array_unique($updatedUris)),
      'contributors_total' => count($contributorUris),
      'errors' => $errors,
    ];
  }

  /**
   * API endpoint: refresh members statistics only.
   *
   * Optional query parameters:
   * - contributor_uri: refresh one contributor row
   * - manager_email: refresh contributors matching this manager email
   */
  public function refreshMembersStatisticsCache() {
    $job = $this->getStatisticsCacheJob();
    if ($this->isStatisticsCacheJobRunning($job)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'A full statistics refresh job is currently running. Retry members refresh after it completes.',
        'job_id' => $job['job_id'] ?? '',
      ], 409);
    }

    $request = \Drupal::request();
    $contributorUri = (string) $request->query->get('contributor_uri', '');
    $managerEmail = (string) $request->query->get('manager_email', '');

    try {
      $api = \Drupal::service('rep.api_connector');
      $result = $this->recomputeMembersStatisticsCache($api, $contributorUri !== '' ? $contributorUri : NULL, $managerEmail !== '' ? $managerEmail : NULL);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Members statistics cache refreshed.',
        'full_recompute' => (bool) $result['full_recompute'],
        'updated_contributor_uris' => $result['updated_uris'],
        'contributors_total' => (int) $result['contributors_total'],
        'errors' => $result['errors'],
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('pmsr')->error('Failed to refresh members statistics cache: ' . $e->getMessage());
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to refresh members statistics cache.',
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Returns the Statistics page.
   */
  public function content() {
    // Get API connector service
    $api = \Drupal::service('rep.api_connector');

    // Self-heal stale background cache jobs.
    $statsJob = $this->getStatisticsCacheJob();
    if ($this->isStatisticsCacheJobStale($statsJob)) {
      \Drupal::logger('pmsr')->warning('Detected stale statistics cache job @job. Relaunching worker.', [
        '@job' => $statsJob['job_id'] ?? 'unknown',
      ]);
      if (!empty($statsJob['job_id'])) {
        $this->triggerStatisticsCacheWorker((string) $statsJob['job_id']);
      }
    }
    $statsJob = $this->getStatisticsCacheJob();
    $deferLiveFetch = $this->isStatisticsCacheJobRunning($statsJob);
    if ($deferLiveFetch && !empty($statsJob['job_id'])) {
      // Opportunistically kick the worker on each page view while running.
      $this->triggerStatisticsCacheWorker((string) $statsJob['job_id']);
    }
    
    // Fetch GLOBAL statistics from cache or API (ontologies, classes, instances)
    
    // 1. Ontologies count
    $ontologiesCount = 0;
    $storedOntologiesCount = $this->getStatisticsDataValue('ontologies');
    if ($storedOntologiesCount !== NULL) {
      $ontologiesCount = (int) $storedOntologiesCount;
    } elseif (!$deferLiveFetch) {
      try {
        $ontologiesResponse = $api->statisticsOntologiesCount();
        $ontologiesData = $api->parseObjectResponse($ontologiesResponse, 'statisticsOntologiesCount');
        if ($ontologiesData && isset($ontologiesData->total)) {
          $ontologiesCount = $ontologiesData->total;
          $this->setStatisticsDataValue('ontologies', (int) $ontologiesCount);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch ontologies count: ' . $e->getMessage());
      }
    }
    
    // 2. Classes count
    $classesCount = 0;
    $storedClassesCount = $this->getStatisticsDataValue('classes');
    if ($storedClassesCount !== NULL) {
      $classesCount = (int) $storedClassesCount;
    } elseif (!$deferLiveFetch) {
      try {
        $classesResponse = $api->statisticsClassesCount();
        $classesData = $api->parseObjectResponse($classesResponse, 'statisticsClassesCount');
        if ($classesData && isset($classesData->total)) {
          $classesCount = $classesData->total;
          $this->setStatisticsDataValue('classes', (int) $classesCount);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch classes count: ' . $e->getMessage());
      }
    }
    
    // 3. Instances count
    $instancesCount = 0;
    $storedInstancesCount = $this->getStatisticsDataValue('instances');
    if ($storedInstancesCount !== NULL) {
      $instancesCount = (int) $storedInstancesCount;
    } elseif (!$deferLiveFetch) {
      try {
        $instancesResponse = $api->statisticsInstancesCount();
        $instancesData = $api->parseObjectResponse($instancesResponse, 'statisticsInstancesCount');
        if ($instancesData && isset($instancesData->total)) {
          $instancesCount = $instancesData->total;
          $this->setStatisticsDataValue('instances', (int) $instancesCount);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch instances count: ' . $e->getMessage());
      }
    }
    
    // Fetch ONTOLOGY-SPECIFIC statistics from cache or API with ontology-specific cache tags
    
    // 1. Instruments (INS ontology)
    $instrumentsCount = 0;
    $storedInstrumentsCount = $this->getStatisticsDataValue('instruments');
    if ($storedInstrumentsCount !== NULL) {
      $instrumentsCount = (int) $storedInstrumentsCount;
    } elseif (!$deferLiveFetch) {
      try {
        $instrumentsResponse = $api->statisticsInstrumentCount();
        $instrumentsData = $api->parseObjectResponse($instrumentsResponse, 'statisticsInstrumentCount');
        if ($instrumentsData && isset($instrumentsData->total)) {
          $instrumentsCount = $instrumentsData->total;
          $this->setStatisticsDataValue('instruments', (int) $instrumentsCount);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch instruments count: ' . $e->getMessage());
      }
    }
    
    // 2. Clinical Procedures (PMSR ontology)
    $proceduresCount = 0;
    $storedProceduresCount = $this->getStatisticsDataValue('procedures');
    if ($storedProceduresCount !== NULL) {
      $proceduresCount = (int) $storedProceduresCount;
    } elseif (!$deferLiveFetch) {
      try {
        $proceduresResponse = $api->statisticsProceduresCount();
        $proceduresData = $api->parseObjectResponse($proceduresResponse, 'statisticsProceduresCount');
        if ($proceduresData && isset($proceduresData->total)) {
          $proceduresCount = $proceduresData->total;
          $this->setStatisticsDataValue('procedures', (int) $proceduresCount);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch procedures count: ' . $e->getMessage());
      }
    }
    
    // 3. Anatomical Structures (UBERON ontology)
    $anatomyCount = 0;
    $storedAnatomyCount = $this->getStatisticsDataValue('anatomy');
    if ($storedAnatomyCount !== NULL) {
      $anatomyCount = (int) $storedAnatomyCount;
    } elseif (!$deferLiveFetch) {
      try {
        $anatomyResponse = $api->statisticsAnatomyCount();
        $anatomyData = $api->parseObjectResponse($anatomyResponse, 'statisticsAnatomyCount');
        if ($anatomyData && isset($anatomyData->total)) {
          $anatomyCount = $anatomyData->total;
          $this->setStatisticsDataValue('anatomy', (int) $anatomyCount);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch anatomy count: ' . $e->getMessage());
      }
    }

    // 4. Medical Devices (NCIT ontology)
    $devicesCount = 0;
    $storedDevicesCount = $this->getStatisticsDataValue('devices');
    if ($storedDevicesCount !== NULL) {
      $devicesCount = (int) $storedDevicesCount;
    } elseif (!$deferLiveFetch) {
      try {
        $devicesResponse = $api->statisticsMedicalDevicesCount();
        $devicesData = $api->parseObjectResponse($devicesResponse, 'statisticsMedicalDevicesCount');
        if ($devicesData && isset($devicesData->total)) {
          $devicesCount = $devicesData->total;
          $this->setStatisticsDataValue('devices', (int) $devicesCount);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch medical devices count: ' . $e->getMessage());
      }
    }

    // Module path
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');

    // Initialize output
    $output = '';

    // Main content container
    $output .= '<div class="container-fluid">';
    
    // Section (a) Global Statistics
    $output .= '<div class="row mt-4">';
    $output .= '<div class="col-12">';
    $output .= '<h2>Global Statistics</h2>';
    $output .= '<p class="text-muted">This repository currently hosts a knowledge graph containing the following core metrics:</p>';
    $output .= '</div>';
    $output .= '</div>';

    // Single row with all 5 cards
    $output .= '<div class="row mt-3 mb-5 g-3">';
    
    // Card 1: #ontologies
    $output .= '<div class="col-md col-sm-6">';
    $output .= '<div class="card statistics-card text-center h-100" style="border: 1px solid #dee2e6;">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title text-primary" style="font-family: \'Courier New\', monospace; font-weight: 600; margin-bottom: 1rem;">#ontologies</h5>';
    $output .= '<h2 class="display-4 mb-2" style="font-weight: 700; color: #0d6efd;">' . $ontologiesCount . '</h2>';
    $output .= '<a href="/pmsr/ontologies/list" class="btn btn-outline-primary btn-sm">View All →</a>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    
    // Card 2: #classes
    $output .= '<div class="col-md col-sm-6">';
    $output .= '<div class="card statistics-card text-center h-100" style="border: 1px solid #dee2e6;">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title text-success" style="font-family: \'Courier New\', monospace; font-weight: 600; margin-bottom: 1rem;">#classes</h5>';
    $output .= '<h2 class="display-4 mb-2" style="font-weight: 700; color: #0d6efd;">' . number_format($classesCount) . '</h2>';
    $output .= '<small class="text-muted">Knowledge Graph</small>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    
    // Card 3: #instances
    $output .= '<div class="col-md col-sm-6">';
    $output .= '<div class="card statistics-card text-center h-100" style="border: 1px solid #dee2e6;">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title text-info" style="font-family: \'Courier New\', monospace; font-weight: 600; margin-bottom: 1rem;">#instances</h5>';
    $output .= '<h2 class="display-4 mb-2" style="font-weight: 700; color: #0d6efd;">' . number_format($instancesCount) . '</h2>';
    $output .= '<small class="text-muted">Knowledge Graph</small>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    
    // Card 4: Simulator Models
    $output .= '<div class="col-md col-sm-6">';
    $output .= '<div class="card statistics-card text-center h-100" style="border: 1px solid #dee2e6;">';
    $output .= '<div class="card-body">';
    $output .= '<h6 class="card-title text-primary" style="font-weight: 600;">Simulator Models</h6>';
    $output .= '<h2 class="display-5 mb-2" style="font-weight: 700; color: #0d6efd;">' . $instrumentsCount . '</h2>';
    $output .= '<small class="text-muted">INS Ontology</small>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    // Card 5: Clinical Procedures
    $output .= '<div class="col-md col-sm-6">';
    $output .= '<div class="card statistics-card text-center h-100" style="border: 1px solid #dee2e6;">';
    $output .= '<div class="card-body">';
    $output .= '<h6 class="card-title text-success" style="font-weight: 600;">Clinical Procedures</h6>';
    $output .= '<h2 class="display-5 mb-2" style="font-weight: 700; color: #0d6efd;">' . $proceduresCount . '</h2>';
    $output .= '<small class="text-muted">PMSR Ontology</small>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    
    // Card 6: Anatomical Structures
    $output .= '<div class="col-md col-sm-6">';
    $output .= '<div class="card statistics-card text-center h-100" style="border: 1px solid #dee2e6;">';
    $output .= '<div class="card-body">';
    $output .= '<h6 class="card-title text-warning" style="font-weight: 600;">Anatomical Structures</h6>';
    $output .= '<h2 class="display-5 mb-2" style="font-weight: 700; color: #0d6efd;">' . $anatomyCount . '</h2>';
    $output .= '<small class="text-muted">UBERON</small>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    // Card 7: Medical Devices
    $output .= '<div class="col-md col-sm-6">';
    $output .= '<div class="card statistics-card text-center h-100" style="border: 1px solid #dee2e6;">';
    $output .= '<div class="card-body">';
    $output .= '<h6 class="card-title text-danger" style="font-weight: 600;">Medical Devices</h6>';
    $output .= '<h2 class="display-5 mb-2" style="font-weight: 700; color: #0d6efd;">' . $devicesCount . '</h2>';
    $output .= '<small class="text-muted">NCIT</small>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '</div>'; // End single row with all 7 cards

$output .= '</div>'; // End single row with all 5 cards

    // Section (b) PMSR Project Members
    $output .= '<div class="row mt-5">';
    $output .= '<div class="col-12">';
    $output .= '<h2>PMSR Project Members</h2>';
    $output .= '</div>';
    $output .= '</div>';

    // Fetch PMSR project and its members (cached because this section is expensive).
    $projectUri = 'https://pmsr.net/ont/PJT1742783481383251';
    $members = [];
    $membersLoadingDeferred = FALSE;
    $memberTotals = [
      'registeredUsers' => 0,
      'people' => 0,
      'simulators' => 0,
      'platforms' => 0,
      'scenarios' => 0,
      'processes' => 0,
      'tasksSubtasks' => 0,
    ];
    $membersData = $this->getStatisticsDataValue(self::STATS_MEMBERS_DATA_KEY);
    if (is_array($membersData)
      && isset($membersData['members']) && is_array($membersData['members'])) {
      $members = $membersData['members'];
      if (isset($membersData['totals']) && is_array($membersData['totals'])) {
        $memberTotals = array_merge($memberTotals, $membersData['totals']);
      }
    } elseif ($deferLiveFetch) {
      $membersLoadingDeferred = TRUE;
    } else {
      $hadMemberFetchErrors = false;
      try {
        $projectData = $this->fetchUriObjectWithRetry($api, $projectUri, 4);

        if ($projectData && isset($projectData->contributorUris) && is_array($projectData->contributorUris)) {
          // Fetch each contributor organization
          foreach ($projectData->contributorUris as $contributorUri) {
            try {
              $members[] = $this->buildMemberStatisticsRow($api, (string) $contributorUri);
            } catch (\Exception $e) {
              $hadMemberFetchErrors = true;
              \Drupal::logger('pmsr')->warning('Failed to fetch organization ' . $contributorUri . ': ' . $e->getMessage());
            }
          }
        }
      } catch (\Exception $e) {
        // This is expected when project is not yet loaded into the knowledge graph
        \Drupal::logger('pmsr')->info('PMSR project not found in knowledge graph: ' . $e->getMessage());
      }

      foreach ($members as $member) {
        $memberTotals['registeredUsers'] += $member['registeredUsersCount'] ?? 0;
        $memberTotals['people'] += $member['peopleCount'] ?? 0;
        $memberTotals['simulators'] += $member['simulatorCount'] ?? 0;
        $memberTotals['platforms'] += $member['platformCount'] ?? 0;
        $memberTotals['scenarios'] += $member['registeredScenariosCount'] ?? 0;
        $memberTotals['processes'] += $member['registeredProcessesCount'] ?? 0;
        $memberTotals['tasksSubtasks'] += $member['registeredTasksSubtasksCount'] ?? 0;
      }

      // Avoid persisting incomplete snapshots caused by transient API failures.
      if (!$hadMemberFetchErrors) {
        $this->setStatisticsDataValue(self::STATS_MEMBERS_DATA_KEY, [
          'members' => $members,
          'totals' => $memberTotals,
        ]);
      }
    }

    // Display members table (up to 10 columns + Description column + Total column)
    if (!empty($members)) {
      $output .= '<div class="row mt-3">';
      $output .= '<div class="col-12">';
      $output .= '<div class="table-responsive">';
      $output .= '<table class="table table-bordered" style="border-color: #adb5bd;">';
      
      $displayCount = min(count($members), 10);
      
      // Calculate totals across ALL members (not just displayed ones)
      $totalRegisteredUsers = (int) ($memberTotals['registeredUsers'] ?? 0);
      $totalPeople = (int) ($memberTotals['people'] ?? 0);
      $totalSimulators = (int) ($memberTotals['simulators'] ?? 0);
      $totalPlatforms = (int) ($memberTotals['platforms'] ?? 0);
      $totalScenarios = (int) ($memberTotals['scenarios'] ?? 0);
      $totalProcesses = (int) ($memberTotals['processes'] ?? 0);
      $totalTasksSubtasks = (int) ($memberTotals['tasksSubtasks'] ?? 0);
      
      // Row 1: Logo
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="width: 150px; padding: 15px; background-color: #f8f9fa;"><strong>Logo</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $output .= '<td class="text-center align-middle" style="padding: 20px; background-color: white;">';
        $output .= '<img src="' . htmlspecialchars($member['image']) . '" ';
        $output .= 'alt="' . htmlspecialchars($member['label']) . '" ';
        $output .= 'class="img-fluid" ';
        $output .= 'style="max-height: 80px; max-width: 100%; object-fit: contain;" />';
        $output .= '</td>';
      }
      // Total column - Logo row
      $output .= '<td class="text-center align-middle" style="padding: 20px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="color: #0d6efd;">TOTAL</strong>';
      $output .= '</td>';
      $output .= '</tr>';
      
      // Row 2: Link (Internal name as clickable link)
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Link</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        // Encode URI for Drupal route
        $encodedUri = base64_encode($member['uri']);
        $orgLink = '/rep/uri/' . $encodedUri . '?destination=/pmsr/statistics';
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<a href="' . htmlspecialchars($orgLink) . '" class="text-primary">';
        $output .= '<strong>' . htmlspecialchars($member['shortName']) . '</strong>';
        $output .= '</a>';
        $output .= '</td>';
      }
      // Total column - Link row
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="color: #0d6efd;">TOTAL</strong>';
      $output .= '</td>';
      $output .= '</tr>';
      
      // Row 3: Full Name
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Full Name</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<span>' . htmlspecialchars($member['fullName']) . '</span>';
        $output .= '</td>';
      }
      // Total column - Full Name row
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="color: #0d6efd;">All Members</strong>';
      $output .= '</td>';
      $output .= '</tr>';
      
      // Row 4: Registered PMSR Users
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered PMSR Users</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $registeredUsersCount = $member['registeredUsersCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $registeredUsersCount . '</strong>';
        $output .= '</td>';
      }
      // Total column - Registered Users
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $totalRegisteredUsers . '</strong>';
      $output .= '</td>';
      $output .= '</tr>';
      
      // Row 5: People in Knowledge Graph
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>People in Knowledge Graph</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $peopleCount = $member['peopleCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $peopleCount . '</strong>';
        $output .= '</td>';
      }
      // Total column - People in KG
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $totalPeople . '</strong>';
      $output .= '</td>';
      $output .= '</tr>';
      
      // Row 6: Registered Scenarios
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Scenarios</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $scenariosCount = $member['registeredScenariosCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $scenariosCount . '</strong>';
        $output .= '</td>';
      }
      // Total column - Scenarios
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $totalScenarios . '</strong>';
      $output .= '</td>';
      $output .= '</tr>';

      // Row 7: Registered Processes
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Processes</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $processCount = $member['registeredProcessesCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $processCount . '</strong>';
        $output .= '</td>';
      }
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $totalProcesses . '</strong>';
      $output .= '</td>';
      $output .= '</tr>';

      // Row 8: Registered Tasks/Subtasks (subrow of processes)
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px 15px 15px 30px; background-color: #f8f9fa;"><strong>Registered Tasks/Subtasks</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $tasksCount = $member['registeredTasksSubtasksCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $tasksCount . '</strong>';
        $output .= '</td>';
      }
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $totalTasksSubtasks . '</strong>';
      $output .= '</td>';
      $output .= '</tr>';
      
      // Row 9: Registered Simulators
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Simulators</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $simulatorCount = $member['simulatorCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $simulatorCount . '</strong>';
        $output .= '</td>';
      }
      // Total column - Simulators
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $totalSimulators . '</strong>';
      $output .= '</td>';
      $output .= '</tr>';
      
      // Row 10: Registered Simulation Laboratories
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Simulation Laboratories</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $platformCount = $member['platformCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $platformCount . '</strong>';
        $output .= '</td>';
      }
      // Total column - Platforms
      $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: #e9ecef; border-left: 3px solid #0d6efd;">';
      $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $totalPlatforms . '</strong>';
      $output .= '</td>';
      $output .= '</tr>';
      
      $output .= '</table>';
      $output .= '</div>';
      $output .= '</div>';
      $output .= '</div>';
      
      if (count($members) > 10) {
        $output .= '<div class="row mt-2">';
        $output .= '<div class="col-12">';
        $output .= '<p class="text-muted small">Showing ' . $displayCount . ' of ' . count($members) . ' member organizations</p>';
        $output .= '</div>';
        $output .= '</div>';
      }
    } else {
      $output .= '<div class="row mt-3">';
      $output .= '<div class="col-12">';
      if ($membersLoadingDeferred) {
        $output .= '<div class="alert alert-warning" role="alert">';
        $output .= '<i class="fas fa-hourglass-half me-2"></i>';
        $output .= '<strong>Project member statistics are being refreshed in the background.</strong>';
      } else {
        $output .= '<div class="alert alert-info" role="alert">';
        $output .= '<i class="fas fa-info-circle me-2"></i>';
        $output .= '<strong>No project information is currently loaded into the knowledge graph</strong>';
      }
      $output .= '</div>';
      $output .= '</div>';
      $output .= '</div>';
    }

    // Add refresh button at the bottom
    $output .= '<div class="row mt-5 mb-4">';
    $output .= '<div class="col-12 text-center">';
    $output .= '<a href="/pmsr/statistics/refresh" class="btn btn-primary btn-lg">';
    $output .= '<i class="fas fa-sync-alt me-2"></i>Refresh Statistics';
    $output .= '</a>';

    $statsJob = $this->getStatisticsCacheJob();
    if ($this->isStatisticsCacheJobRunning($statsJob)) {
      $stage = (string) ($statsJob['stage'] ?? 'unknown');
      $heartbeatAt = (int) ($statsJob['heartbeat_at'] ?? time());
      $progress = $this->getStatisticsJobProgressInfo($statsJob);
      $output .= '<span class="badge bg-warning text-dark ms-2" title="Statistics cache progress">' . (int) $progress['percent'] . '%</span>';
      if ($stage === 'members_collect') {
        $memberDone = (int) ($statsJob['members']['index'] ?? 0);
        $memberTotal = isset($statsJob['members']['contributor_uris']) && is_array($statsJob['members']['contributor_uris'])
          ? count($statsJob['members']['contributor_uris'])
          : 0;
        if ($memberTotal > 0) {
          $output .= '<span class="badge bg-light text-dark ms-1" title="Collected contributor rows">' . $memberDone . '/' . $memberTotal . ' orgs</span>';
        }
      }
      $output .= '<p class="text-warning small mt-2"><strong>Background refresh is running.</strong> Stage: ' . htmlspecialchars($stage) . ' (last update: ' . date('Y-m-d H:i:s', $heartbeatAt) . ', progress: ' . (int) $progress['percent'] . '%, auto-updating every 6s)</p>';
      $output .= '<script>(function(){setTimeout(function(){window.location.reload();},6000);})();</script>';
    } else {
      $output .= '<p class="text-muted small mt-2">Click to trigger an asynchronous statistics cache refresh process</p>';
    }
    $output .= '</div>';
    $output .= '</div>';

    $output .= '</div>'; // End container

    return [
      '#title' => 'Statistics',
      '#markup' => Markup::create($output),
      '#attached' => [
        'library' => [
          'pmsr/statistics',
        ],
      ],
      '#cache' => [
        'contexts' => ['url'],
        'tags' => [
          'pmsr_statistics:global',
          'pmsr_statistics:ontologies',
          'pmsr_statistics:classes',
          'pmsr_statistics:instances',
          'pmsr_statistics:people',
          'pmsr_statistics:projects',
          'pmsr_statistics:organizations',
          'pmsr_ontology:ins',
          'pmsr_ontology:pmsr',
          'pmsr_ontology:uberon',
          'pmsr_ontology:ncit',
          'kgr_people',
          'kgr_geography',
          'dp2_pmsr',
        ],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * API endpoint: consolidated statistics snapshot used by tests/baselines.
   */
  public function getStatisticsSnapshot() {
    $api = \Drupal::service('rep.api_connector');

    $data = [
      'ontologies' => 0,
      'classes' => 0,
      'instances' => 0,
      'simulator_models' => 0,
      'clinical_procedures' => 0,
      'anatomical_structures' => 0,
      'medical_devices' => 0,
      'registered_pmsr_users_total' => 0,
      'people_in_knowledge_graph_total' => 0,
      'registered_scenarios_total' => 0,
      'registered_processes_total' => 0,
      'registered_tasks_subtasks_total' => 0,
      'registered_simulators_total' => 0,
      'registered_simulation_laboratories_total' => 0,
    ];

    $cardMetrics = [
      'ontologies' => ['method' => 'statisticsOntologiesCount', 'parser' => 'statisticsOntologiesCount', 'target' => 'ontologies'],
      'classes' => ['method' => 'statisticsClassesCount', 'parser' => 'statisticsClassesCount', 'target' => 'classes'],
      'instances' => ['method' => 'statisticsInstancesCount', 'parser' => 'statisticsInstancesCount', 'target' => 'instances'],
      'instruments' => ['method' => 'statisticsInstrumentCount', 'parser' => 'statisticsInstrumentCount', 'target' => 'simulator_models'],
      'procedures' => ['method' => 'statisticsProceduresCount', 'parser' => 'statisticsProceduresCount', 'target' => 'clinical_procedures'],
      'anatomy' => ['method' => 'statisticsAnatomyCount', 'parser' => 'statisticsAnatomyCount', 'target' => 'anatomical_structures'],
      'devices' => ['method' => 'statisticsMedicalDevicesCount', 'parser' => 'statisticsMedicalDevicesCount', 'target' => 'medical_devices'],
    ];

    foreach ($cardMetrics as $storeKey => $meta) {
      $stored = $this->getStatisticsDataValue($storeKey);
      if ($stored !== NULL) {
        $data[$meta['target']] = (int) $stored;
        continue;
      }

      try {
        $response = $api->{$meta['method']}();
        $parsed = $api->parseObjectResponse($response, $meta['parser']);
        if ($parsed && isset($parsed->total)) {
          $value = (int) $parsed->total;
          $data[$meta['target']] = $value;
          $this->setStatisticsDataValue($storeKey, $value);
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('pmsr')->warning('Statistics snapshot fallback failed for @key: @error', [
          '@key' => $storeKey,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    $membersData = $this->getStatisticsDataValue(self::STATS_MEMBERS_DATA_KEY);
    if (is_array($membersData) && isset($membersData['totals']) && is_array($membersData['totals'])) {
      $totals = $membersData['totals'];
      $data['registered_pmsr_users_total'] = (int) ($totals['registeredUsers'] ?? 0);
      $data['people_in_knowledge_graph_total'] = (int) ($totals['people'] ?? 0);
      $data['registered_scenarios_total'] = (int) ($totals['scenarios'] ?? 0);
      $data['registered_processes_total'] = (int) ($totals['processes'] ?? 0);
      $data['registered_tasks_subtasks_total'] = (int) ($totals['tasksSubtasks'] ?? 0);
      $data['registered_simulators_total'] = (int) ($totals['simulators'] ?? 0);
      $data['registered_simulation_laboratories_total'] = (int) ($totals['platforms'] ?? 0);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $data,
      'generated_at' => date('c'),
    ]);
  }

  /**
   * API endpoint: Get all global statistics in one call.
   * 
   * GET /pmsr/api/statistics/global
   * 
   * Returns:
   * {
   *   "success": true,
   *   "data": {
   *     "ontologies": 5,
   *     "classes": 1247,
   *     "instances": 3542
   *   },
   *   "cached_at": "2026-07-20T10:30:15Z",
   *   "cache_age_seconds": 120
   * }
   */
  public function getGlobalStatistics() {
    $api = \Drupal::service('rep.api_connector');
    
    $result = [
      'success' => TRUE,
      'data' => [
        'ontologies' => 0,
        'classes' => 0,
        'instances' => 0,
      ],
      'cached_at' => NULL,
      'cache_age_seconds' => 0,
    ];
    
    // Try to get from persistent statistics store first.
    $updatedAt = NULL;
    $cached = $this->getStatisticsDataValue('global_combined', $updatedAt);

    if (is_array($cached)) {
      $result['data'] = $cached;
      if ($updatedAt !== NULL) {
        $result['cached_at'] = date('c', $updatedAt);
        $result['cache_age_seconds'] = time() - $updatedAt;
      }
    } else {
      // Fetch all three statistics
      try {
        $ontologiesResponse = $api->statisticsOntologiesCount();
        $ontologiesData = $api->parseObjectResponse($ontologiesResponse, 'statisticsOntologiesCount');
        if ($ontologiesData && isset($ontologiesData->total)) {
          $result['data']['ontologies'] = $ontologiesData->total;
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch ontologies count: ' . $e->getMessage());
      }
      
      try {
        $classesResponse = $api->statisticsClassesCount();
        $classesData = $api->parseObjectResponse($classesResponse, 'statisticsClassesCount');
        if ($classesData && isset($classesData->total)) {
          $result['data']['classes'] = $classesData->total;
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch classes count: ' . $e->getMessage());
      }
      
      try {
        $instancesResponse = $api->statisticsInstancesCount();
        $instancesData = $api->parseObjectResponse($instancesResponse, 'statisticsInstancesCount');
        if ($instancesData && isset($instancesData->total)) {
          $result['data']['instances'] = $instancesData->total;
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch instances count: ' . $e->getMessage());
      }
      
      $this->setStatisticsDataValue('global_combined', $result['data']);
      $result['cached_at'] = date('c');
      $result['cache_age_seconds'] = 0;
    }
    
    return new JsonResponse($result);
  }

  /**
   * Retry organization lookup to tolerate transient HASCOAPI read errors.
   */
  private function fetchUriObjectWithRetry($api, string $uri, int $maxAttempts = 3) {
    $lastException = NULL;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
      try {
        $response = $api->getUri($uri);
        $parsed = $api->parseObjectResponse($response, 'getUri');
        if ($parsed === NULL || $parsed === FALSE) {
          throw new \RuntimeException('Empty API response for URI: ' . $uri);
        }
        return $parsed;
      }
      catch (\Exception $e) {
        $lastException = $e;
        if ($attempt < $maxAttempts) {
          usleep(100000 * $attempt);
          continue;
        }
      }
    }

    throw $lastException ?? new \RuntimeException('Unknown organization lookup error');
  }

  /**
   * Build a readable fallback label from a URI when org metadata is unavailable.
   */
  private function labelFromUri(string $uri): string {
    $parts = explode('/', rtrim($uri, '/'));
    return (string) end($parts);
  }
  
  /**
   * API endpoint: trigger async statistics cache warmup.
   */
  public function refreshStatisticsCache() {
    $request = \Drupal::request();
    if ($request->query->get('worker') === '1') {
      $jobId = (string) $request->query->get('job', '');
      if ($jobId === '') {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Missing worker job id',
        ], 400);
      }

      $job = $this->getStatisticsCacheJob();
      if (!$this->isStatisticsCacheJobRunning($job) || ($job['job_id'] ?? '') !== $jobId) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'No matching running statistics cache job',
        ], 404);
      }

      $this->processStatisticsCacheJob($jobId, 40);
      $latest = $this->getStatisticsCacheJob();

      return new JsonResponse([
        'success' => TRUE,
        'started' => FALSE,
        'job_id' => $jobId,
        'status' => $latest['status'] ?? 'unknown',
        'stage' => $latest['stage'] ?? 'unknown',
        'message' => $latest['last_message'] ?? '',
      ]);
    }

    $job = $this->getStatisticsCacheJob();
    $wasRunning = $this->isStatisticsCacheJobRunning($job) && !$this->isStatisticsCacheJobStale($job);

    if ($wasRunning) {
      if (!empty($job['job_id'])) {
        $this->triggerStatisticsCacheWorker((string) $job['job_id']);
      }
      return new JsonResponse([
        'success' => TRUE,
        'started' => FALSE,
        'message' => 'Statistics caching process is already running.',
        'job_id' => $job['job_id'] ?? '',
        'stage' => $job['stage'] ?? 'unknown',
      ]);
    }

    if ($this->isStatisticsCacheJobRunning($job) && $this->isStatisticsCacheJobStale($job)) {
      $job['status'] = 'running';
      $job['last_message'] = 'Restarting stale statistics caching process';
      $this->saveStatisticsCacheJob($job);
      $this->triggerStatisticsCacheWorker((string) $job['job_id']);

      return new JsonResponse([
        'success' => TRUE,
        'started' => TRUE,
        'resumed' => TRUE,
        'message' => 'A stale statistics caching process was restarted.',
        'job_id' => $job['job_id'],
      ]);
    }

    $job = $this->createStatisticsCacheJobState();
    $this->resetStatisticsCachesBeforeWarmup();
    $this->saveStatisticsCacheJob($job);
    $this->triggerStatisticsCacheWorker((string) $job['job_id']);

    return new JsonResponse([
      'success' => TRUE,
      'started' => TRUE,
      'message' => 'A new asynchronous statistics caching process was triggered.',
      'job_id' => $job['job_id'],
    ]);
  }
  
  /**
   * Refresh button handler: trigger/resume async statistics caching process.
   */
  public function refreshStatisticsPage() {
    $job = $this->getStatisticsCacheJob();

    if ($this->isStatisticsCacheJobRunning($job) && !$this->isStatisticsCacheJobStale($job)) {
      \Drupal::messenger()->addStatus('A statistics caching process is already running. No new process was started.');
      return $this->redirect('pmsr.statistics');
    }

    if ($this->isStatisticsCacheJobRunning($job) && $this->isStatisticsCacheJobStale($job)) {
      $job['status'] = 'running';
      $job['last_message'] = 'Restarting stale statistics caching process';
      $this->saveStatisticsCacheJob($job);
      $this->triggerStatisticsCacheWorker((string) $job['job_id']);
      \Drupal::messenger()->addStatus('A stale statistics caching process was detected and restarted.');
      return $this->redirect('pmsr.statistics');
    }

    $job = $this->createStatisticsCacheJobState();
    $this->resetStatisticsCachesBeforeWarmup();
    $this->saveStatisticsCacheJob($job);
    $this->triggerStatisticsCacheWorker((string) $job['job_id']);

    \Drupal::messenger()->addStatus('A new asynchronous statistics caching process was triggered.');
    return $this->redirect('pmsr.statistics');
  }
  
  /**
   * Display list of all ontologies.
   * 
   * GET /pmsr/ontologies/list
   */
  public function listOntologies() {
    $api = \Drupal::service('rep.api_connector');
    
    $output = '';
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<div class="row">';
    $output .= '<div class="col-12">';
    $output .= '<h2>Ontologies</h2>';
    $output .= '<p class="text-muted">Complete list of ontologies (named graphs) loaded in the knowledge graph repository.</p>';
    
    // Fetch ontologies from HASCOAPI
    $ontologies = [];
    try {
      $response = $api->getOntologies();
      $data = $api->parseObjectResponse($response, 'getOntologies');
      
      if (is_array($data)) {
        $ontologies = $data;
      }
    } catch (\Exception $e) {
      \Drupal::logger('pmsr')->error('Failed to fetch ontologies: ' . $e->getMessage());
      $output .= '<div class="alert alert-danger">Failed to load ontologies.</div>';
    }
    
    if (!empty($ontologies)) {
      $output .= '<table class="table table-striped table-bordered mt-4">';
      $output .= '<thead class="table-light">';
      $output .= '<tr>';
      $output .= '<th style="width: 50px;">#</th>';
      $output .= '<th>Ontology URI</th>';
      $output .= '<th style="width: 150px;">Actions</th>';
      $output .= '</tr>';
      $output .= '</thead>';
      $output .= '<tbody>';
      
      $index = 1;
      foreach ($ontologies as $ontology) {
        $uri = is_object($ontology) ? ($ontology->uri ?? '') : ($ontology['uri'] ?? '');
        if (empty($uri)) {
          continue;
        }
        
        $output .= '<tr>';
        $output .= '<td>' . $index . '</td>';
        $output .= '<td style="word-break: break-all;"><code>' . htmlspecialchars($uri) . '</code></td>';
        $output .= '<td class="text-center">';
        $output .= '<a href="' . htmlspecialchars($uri) . '" target="_blank" class="btn btn-sm btn-outline-primary">Open</a>';
        $output .= '</td>';
        $output .= '</tr>';
        $index++;
      }
      
      $output .= '</tbody>';
      $output .= '</table>';
      
      $output .= '<p class="mt-3"><strong>Total ontologies:</strong> ' . count($ontologies) . '</p>';
    } else {
      $output .= '<div class="alert alert-info mt-4">No ontologies found.</div>';
    }
    
    $output .= '<div class="mt-4">';
    $output .= '<a href="/pmsr/statistics" class="btn btn-secondary">← Back to Statistics</a>';
    $output .= '</div>';
    
    $output .= '</div>'; // col-12
    $output .= '</div>'; // row
    $output .= '</div>'; // container-fluid
    
    return [
      '#title' => 'Ontologies',
      '#markup' => Markup::create($output),
    ];
  }
}
