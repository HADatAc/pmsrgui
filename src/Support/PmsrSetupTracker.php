<?php

namespace Drupal\pmsr\Support;

/**
 * Tracks PMSR setup stage execution and test outcomes for dashboard display.
 */
final class PmsrSetupTracker {

  private const STATE_KEY = 'pmsr.setup_tests_dashboard';

  /**
   * Stage and test definitions shown in the PMSR Tests table.
   */
  public static function definitions(): array {
    return [
      'pmsr_config_bootstrap' => [
        'label' => 'PMSR Config Bootstrap',
        'tests' => [
          ['id' => 'bootstrap-smoke', 'label' => 'Bootstrap flow smoke check'],
          ['id' => 'namespace-policy', 'label' => 'Namespace policy regression (run-tests.sh namespace-policy)'],
        ],
      ],
      'ingest_pmsr_ontologies' => [
        'label' => 'Ingest PMSR Ontologies (pmsr, uberon, ncit)',
        'tests' => [
          ['id' => 'ontology-ingestion-smoke', 'label' => 'Ontology ingestion + entrypoint mapping smoke check'],
          ['id' => 'entrypoint-soundness', 'label' => 'Entry-point soundness regression (run-tests.sh entrypoints-soundness)'],
        ],
      ],
      'ingest_auxiliary_data' => [
        'label' => 'Ingest Auxiliary Data',
        'tests' => [
          ['id' => 'auxiliary-tools-smoke', 'label' => 'Auxiliary tool registration smoke check'],
          ['id' => 'auxiliary-tools-wildcard-policy', 'label' => 'Wildcard process URI policy check (Process URI "*" = all processes)'],
        ],
      ],
      'ingest_ins_instruments' => [
        'label' => 'Ingest INS Instruments',
        'tests' => [
          ['id' => 'ins-ingestion-smoke', 'label' => 'INS ingestion processing status check'],
          ['id' => 'namespace-policy', 'label' => 'Namespace policy regression (run-tests.sh namespace-policy)'],
        ],
      ],
      'ingest_kgr_geography' => [
        'label' => 'Ingest KRG Geography and Organizations',
        'tests' => [
          ['id' => 'kgr-geo-ingestion-smoke', 'label' => 'KGR geography ingestion status check'],
          ['id' => 'namespace-policy', 'label' => 'Namespace policy regression (run-tests.sh namespace-policy)'],
        ],
      ],
      'ingest_kgr_people' => [
        'label' => 'Ingest KGR People',
        'tests' => [
          ['id' => 'kgr-people-ingestion-smoke', 'label' => 'KGR people/DP2 ingestion status check'],
          ['id' => 'statistics-minimum-baseline', 'label' => 'Statistics minimum baseline check (current >= baseline)'],
        ],
      ],
    ];
  }

  /**
   * Reset all tracked stage/test state.
   */
  public static function resetAll(string $reason = ''): void {
    $definitions = self::definitions();
    $now = \Drupal::time()->getCurrentTime();

    $state = [
      'resetAt' => $now,
      'resetReason' => $reason,
      'stages' => [],
      'tests' => [],
    ];

    foreach ($definitions as $stageId => $stageDef) {
      $state['stages'][$stageId] = [
        'executed' => false,
        'lastExecutionStatus' => 'not_run',
        'lastExecutionAt' => null,
        'lastExecutionMessage' => '',
      ];

      $state['tests'][$stageId] = [];
      foreach ($stageDef['tests'] as $testDef) {
        $state['tests'][$stageId][$testDef['id']] = [
          'status' => 'not_run',
          'lastRunAt' => null,
          'message' => '',
        ];
      }
    }

    \Drupal::state()->set(self::STATE_KEY, $state);
  }

  /**
   * Return current state, initializing when needed.
   */
  public static function getState(): array {
    $state = \Drupal::state()->get(self::STATE_KEY);
    if (!is_array($state)) {
      self::resetAll('initialization');
      $state = \Drupal::state()->get(self::STATE_KEY);
    }

    if (!is_array($state)) {
      $state = [
        'resetAt' => null,
        'resetReason' => '',
        'stages' => [],
        'tests' => [],
      ];
    }

    $state = self::mergeDefinitionsIntoState($state);
    $state = self::applyEvidenceInference($state);

    return $state;
  }

  /**
   * Mark a stage as started.
   */
  public static function markStageStarted(string $stageId, string $message = ''): void {
    $state = self::getState();
    if (!isset($state['stages'][$stageId])) {
      return;
    }

    $state['stages'][$stageId]['executed'] = true;
    $state['stages'][$stageId]['lastExecutionStatus'] = 'running';
    $state['stages'][$stageId]['lastExecutionAt'] = \Drupal::time()->getCurrentTime();
    $state['stages'][$stageId]['lastExecutionMessage'] = $message;

    \Drupal::state()->set(self::STATE_KEY, $state);
  }

  /**
   * Mark a stage as finished and update the primary stage smoke test result.
   */
  public static function markStageResult(string $stageId, bool $success, string $message = ''): void {
    $state = self::getState();
    if (!isset($state['stages'][$stageId])) {
      return;
    }

    $now = \Drupal::time()->getCurrentTime();
    $state['stages'][$stageId]['executed'] = true;
    $state['stages'][$stageId]['lastExecutionStatus'] = $success ? 'pass' : 'fail';
    $state['stages'][$stageId]['lastExecutionAt'] = $now;
    $state['stages'][$stageId]['lastExecutionMessage'] = $message;

    $definitions = self::definitions();
    if (!empty($definitions[$stageId]['tests'][0]['id'])) {
      $primaryTestId = $definitions[$stageId]['tests'][0]['id'];
      $state['tests'][$stageId][$primaryTestId] = [
        'status' => $success ? 'pass' : 'fail',
        'lastRunAt' => $now,
        'message' => $message,
      ];
    }

    \Drupal::state()->set(self::STATE_KEY, $state);
  }

  /**
   * Record an explicit test result for a stage/test pair.
   */
  public static function recordTestResult(string $stageId, string $testId, string $status, string $message = ''): void {
    $state = self::getState();
    if (!isset($state['tests'][$stageId]) || !is_array($state['tests'][$stageId])) {
      return;
    }

    $normalized = strtolower(trim($status));
    if (!in_array($normalized, ['pass', 'fail', 'running', 'not_run'], true)) {
      $normalized = 'not_run';
    }

    $state['tests'][$stageId][$testId] = [
      'status' => $normalized,
      'lastRunAt' => \Drupal::time()->getCurrentTime(),
      'message' => $message,
    ];

    \Drupal::state()->set(self::STATE_KEY, $state);
  }

  /**
   * Merge any newly-defined stages/tests into old state safely.
   */
  private static function mergeDefinitionsIntoState(array $state): array {
    $definitions = self::definitions();

    if (!isset($state['stages']) || !is_array($state['stages'])) {
      $state['stages'] = [];
    }
    if (!isset($state['tests']) || !is_array($state['tests'])) {
      $state['tests'] = [];
    }

    foreach ($definitions as $stageId => $stageDef) {
      if (!isset($state['stages'][$stageId]) || !is_array($state['stages'][$stageId])) {
        $state['stages'][$stageId] = [
          'executed' => false,
          'lastExecutionStatus' => 'not_run',
          'lastExecutionAt' => null,
          'lastExecutionMessage' => '',
        ];
      }

      if (!isset($state['tests'][$stageId]) || !is_array($state['tests'][$stageId])) {
        $state['tests'][$stageId] = [];
      }

      foreach ($stageDef['tests'] as $testDef) {
        if (!isset($state['tests'][$stageId][$testDef['id']]) || !is_array($state['tests'][$stageId][$testDef['id']])) {
          $state['tests'][$stageId][$testDef['id']] = [
            'status' => 'not_run',
            'lastRunAt' => null,
            'message' => '',
          ];
        }
      }
    }

    return $state;
  }

  /**
   * Infer stage/test evidence from existing KG data when explicit tracker
   * records are missing.
   */
  private static function applyEvidenceInference(array $state): array {
    try {
      $api = \Drupal::service('rep.api_connector');
    }
    catch (\Throwable $e) {
      return $state;
    }

    $namespaces = self::loadNamespaceMap($api);

    // 1) Explicit evidence first, then inference fallback.

    // PMSR Config Bootstrap evidence.
    if (!self::isStageExecuted($state, 'pmsr_config_bootstrap')) {
      // Explicit signal: default namespace row plus non-empty URI is a persisted record.
      if (isset($namespaces['default']) && trim((string) ($namespaces['default']['uri'] ?? '')) !== '') {
        self::markEvidence(
          $state,
          'pmsr_config_bootstrap',
          'bootstrap-smoke',
          true,
          'Explicit record found in namespace table: default namespace row exists.'
        );
      }
      // Fallback inference: key setup namespaces present suggests bootstrap happened.
      elseif (!empty($namespaces['pmsr']) || !empty($namespaces['hasco'])) {
        self::markEvidence(
          $state,
          'pmsr_config_bootstrap',
          'bootstrap-smoke',
          true,
          'Inferred from namespace table evidence (pmsr/hasco entries present).'
        );
      }
    }

    // PMSR ontologies evidence.
    if (!self::isStageExecuted($state, 'ingest_pmsr_ontologies')) {
      $hasPmsr = isset($namespaces['pmsr']);
      $hasUberon = isset($namespaces['uberon']);
      $hasNcit = isset($namespaces['ncit']);
      $pmsrTriples = (int) ($namespaces['pmsr']['triples'] ?? 0);
      $uberonTriples = (int) ($namespaces['uberon']['triples'] ?? 0);
      $ncitTriples = (int) ($namespaces['ncit']['triples'] ?? 0);

      $loadedCount = 0;
      $loadedCount += $pmsrTriples > 0 ? 1 : 0;
      $loadedCount += $uberonTriples > 0 ? 1 : 0;
      $loadedCount += $ncitTriples > 0 ? 1 : 0;

      // Explicit records: namespace rows exist for any target ontology.
      $hasAnyTargetNamespace = $hasPmsr || $hasUberon || $hasNcit;

      if ($hasPmsr && $hasUberon && $hasNcit && ($pmsrTriples > 0 || $uberonTriples > 0 || $ncitTriples > 0)) {
        self::markEvidence(
          $state,
          'ingest_pmsr_ontologies',
          'ontology-ingestion-smoke',
          true,
          'Explicit record found in namespace table for pmsr/uberon/ncit (triples: pmsr=' . $pmsrTriples . ', uberon=' . $uberonTriples . ', ncit=' . $ncitTriples . ').'
        );
      }
      elseif ($hasAnyTargetNamespace) {
        // Stage executed but incomplete/partial load evidence.
        self::markEvidence(
          $state,
          'ingest_pmsr_ontologies',
          'ontology-ingestion-smoke',
          false,
          'Explicit namespace records show partial ontology state (loaded ' . $loadedCount . '/3: pmsr=' . $pmsrTriples . ', uberon=' . $uberonTriples . ', ncit=' . $ncitTriples . ').'
        );
      }
      // Fallback inference when explicit namespace rows are absent.
      elseif ($loadedCount > 0) {
        self::markEvidence(
          $state,
          'ingest_pmsr_ontologies',
          'ontology-ingestion-smoke',
          false,
          'Inferred from partial ontology triples (loaded ' . $loadedCount . '/3 targets).'
        );
      }
    }

    // INS stage evidence.
    if (!self::isStageExecuted($state, 'ingest_ins_instruments')) {
      $insTriples = (int) ($namespaces['ins']['triples'] ?? 0);
      $hasInsTemplate = self::hasEntityWithLabel($api, 'ins', 'INS-PMSR-V3');

      // Explicit: INS template entity in DB.
      if ($hasInsTemplate) {
        self::markEvidence(
          $state,
          'ingest_ins_instruments',
          'ins-ingestion-smoke',
          true,
          'Explicit record found: INS template entity INS-PMSR-V3 exists.'
        );
      }
      // Fallback inference from namespace graph triples.
      elseif ($insTriples > 0) {
        self::markEvidence(
          $state,
          'ingest_ins_instruments',
          'ins-ingestion-smoke',
          true,
          'Inferred from namespace table evidence (ins triples=' . $insTriples . ').'
        );
      }
    }

    // KGR Geography evidence.
    if (!self::isStageExecuted($state, 'ingest_kgr_geography')) {
      $geoLabels = [
        'KGR-COUNTRIES-URI',
        'KGR-DISTRITOS-URI',
        'KGR-CONCELHOS-URI',
        'KGR-INST-POSTAL-URI',
        'KGR-FACULDADES-URI',
      ];

      foreach ($geoLabels as $label) {
        if (self::hasEntityWithLabel($api, 'kgr', $label)) {
          self::markEvidence(
            $state,
            'ingest_kgr_geography',
            'kgr-geo-ingestion-smoke',
            true,
            'Explicit record found: KGR geography entity exists (' . $label . ').'
          );
          break;
        }
      }
    }

    // KGR People evidence.
    if (!self::isStageExecuted($state, 'ingest_kgr_people')) {
      $hasPeopleKgr = self::hasEntityWithLabel($api, 'kgr', 'KGR-PEOPLE');
      $hasDp2Pmsr = self::hasEntityWithLabel($api, 'dp2', 'DP2-PMSR-V3');
      $hasDp2Piaget = self::hasEntityWithLabel($api, 'dp2', 'DP2-PIAGET-V3');

      if ($hasPeopleKgr || $hasDp2Pmsr || $hasDp2Piaget) {
        $evidence = [];
        if ($hasPeopleKgr) {
          $evidence[] = 'KGR-PEOPLE';
        }
        if ($hasDp2Pmsr) {
          $evidence[] = 'DP2-PMSR-V3';
        }
        if ($hasDp2Piaget) {
          $evidence[] = 'DP2-PIAGET-V3';
        }

        self::markEvidence(
          $state,
          'ingest_kgr_people',
          'kgr-people-ingestion-smoke',
          true,
          'Explicit record found in DB entities: ' . implode(', ', $evidence) . '.'
        );
      }
    }

    return $state;
  }

  /**
   * Load namespace table into a normalized label map.
   */
  private static function loadNamespaceMap($api): array {
    $map = [];

    try {
      $raw = $api->namespaceList();
      $obj = json_decode((string) $raw);
      if (!is_object($obj) || empty($obj->isSuccessful) || !isset($obj->body) || !is_array($obj->body)) {
        return $map;
      }

      foreach ($obj->body as $ns) {
        if (!is_object($ns)) {
          continue;
        }
        $label = strtolower(trim((string) ($ns->label ?? '')));
        if ($label === '') {
          continue;
        }
        $map[$label] = [
          'uri' => (string) ($ns->uri ?? ''),
          'triples' => (int) ($ns->numberOfLoadedTriples ?? 0),
          'source' => (string) ($ns->source ?? ''),
          'sourceMime' => (string) ($ns->sourceMime ?? ''),
          'comment' => (string) ($ns->comment ?? ''),
        ];
      }
    }
    catch (\Throwable $e) {
      return $map;
    }

    return $map;
  }

  /**
   * Check whether an element type has an exact matching label.
   */
  private static function hasEntityWithLabel($api, string $elementType, string $label): bool {
    try {
      $raw = $api->listByKeyword($elementType, $label, 100, 0);
      $obj = json_decode((string) $raw);
      if (!is_object($obj) || empty($obj->isSuccessful) || !isset($obj->body) || !is_array($obj->body)) {
        return false;
      }

      foreach ($obj->body as $item) {
        if (!is_object($item)) {
          continue;
        }
        if (trim((string) ($item->label ?? '')) === $label) {
          return true;
        }
      }
    }
    catch (\Throwable $e) {
      return false;
    }

    return false;
  }

  /**
   * Whether a stage already has explicit execution status.
   */
  private static function isStageExecuted(array $state, string $stageId): bool {
    return !empty($state['stages'][$stageId]['executed']);
  }

  /**
   * Apply inferred evidence without overwriting explicit runtime timestamps.
   */
  private static function markEvidence(array &$state, string $stageId, string $primaryTestId, bool $success, string $message): void {
    if (!isset($state['stages'][$stageId]) || !isset($state['tests'][$stageId])) {
      return;
    }

    $state['stages'][$stageId]['executed'] = true;
    $state['stages'][$stageId]['lastExecutionStatus'] = $success ? 'pass' : 'fail';
    $state['stages'][$stageId]['lastExecutionAt'] = $state['stages'][$stageId]['lastExecutionAt'] ?? null;
    $state['stages'][$stageId]['lastExecutionMessage'] = $message;

    if (isset($state['tests'][$stageId][$primaryTestId])) {
      $state['tests'][$stageId][$primaryTestId]['status'] = $success ? 'pass' : 'fail';
      $state['tests'][$stageId][$primaryTestId]['lastRunAt'] = $state['tests'][$stageId][$primaryTestId]['lastRunAt'] ?? null;
      $state['tests'][$stageId][$primaryTestId]['message'] = $message;
    }
  }

}
