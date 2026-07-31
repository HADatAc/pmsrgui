<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;
use Drupal\pmsr\Support\PmsrSetupTracker;

/**
 * Controller for the PMSR Tests dashboard page.
 */
class PmsrTestsController extends ControllerBase {

  /**
   * Render the PMSR Tests page.
   */
  public function content() {
    $definitions = PmsrSetupTracker::definitions();
    $state = PmsrSetupTracker::getState();

    $rows = [];

    foreach ($definitions as $stageId => $stageDef) {
      $stageState = $state['stages'][$stageId] ?? [];
      $stageExecuted = !empty($stageState['executed']);
      $stageStatus = (string) ($stageState['lastExecutionStatus'] ?? 'not_run');
      $stageMsg = trim((string) ($stageState['lastExecutionMessage'] ?? ''));
      $stageWhen = $this->formatTimestamp($stageState['lastExecutionAt'] ?? null);
      if (($stageState['lastExecutionAt'] ?? null) === null && $stageExecuted) {
        $stageWhen = 'Unknown (evidence-based)';
      }

      $executedMarkup = $stageExecuted
        ? '<strong>Yes</strong><br><small>Status: ' . Html::escape($this->prettyStatus($stageStatus)) . '</small><br><small>When: ' . Html::escape($stageWhen) . '</small>'
        : '<strong>No</strong>';

      if ($stageMsg !== '') {
        $executedMarkup .= '<br><small>Message: ' . Html::escape($stageMsg) . '</small>';
      }

      $testsLines = [];
      foreach ($stageDef['tests'] as $testDef) {
        $testState = $state['tests'][$stageId][$testDef['id']] ?? [
          'status' => 'not_run',
          'lastRunAt' => null,
          'message' => '',
        ];

        $testsLines[] = '<strong>' . Html::escape($testDef['label']) . '</strong>';
      }

      $resultLines = [];
      foreach ($stageDef['tests'] as $testDef) {
        $testState = $state['tests'][$stageId][$testDef['id']] ?? [
          'status' => 'not_run',
          'lastRunAt' => null,
          'message' => '',
        ];

        $status = $this->prettyStatus((string) ($testState['status'] ?? 'not_run'));
        $when = $this->formatTimestamp($testState['lastRunAt'] ?? null);
        $message = trim((string) ($testState['message'] ?? ''));
        if (($testState['lastRunAt'] ?? null) === null && strtolower($status) !== 'not run') {
          $when = 'Unknown (evidence-based)';
        }

        $line = Html::escape($status) . ' - ' . Html::escape($when);
        if ($message !== '') {
          $line .= '<br><small>' . Html::escape($message) . '</small>';
        }

        $resultLines[] = '<div><strong>' . Html::escape($testDef['label']) . ':</strong><br>' . $line . '</div>';
      }

      $rows[] = [
        'stage' => ['data' => Markup::create('<strong>' . Html::escape($stageDef['label']) . '</strong>')],
        'tests' => ['data' => Markup::create(implode('<hr style="margin: 0.5rem 0;">', $testsLines))],
        'executed' => ['data' => Markup::create($executedMarkup)],
        'last_result' => ['data' => Markup::create(implode('<hr style="margin: 0.5rem 0;">', $resultLines))],
      ];
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('PMSR Setup Stage'),
          $this->t('Associated Tests'),
          $this->t('Stage Executed?'),
          $this->t('Last Test Result'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No PMSR setup stage definitions found.'),
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mt-3']],
        'close' => [
          '#type' => 'markup',
          '#markup' => Markup::create('<button class="btn btn-secondary" type="button" onclick="history.back()">' . $this->t('Close') . '</button>'),
        ],
      ],
    ];
  }

  /**
   * Human-friendly status labels.
   */
  private function prettyStatus(string $status): string {
    switch (strtolower(trim($status))) {
      case 'pass':
        return 'PASS';

      case 'fail':
        return 'FAIL';

      case 'running':
        return 'RUNNING';

      case 'not_run':
      default:
        return 'NOT RUN';
    }
  }

  /**
   * Convert unix timestamp to display value.
   */
  private function formatTimestamp($timestamp): string {
    if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
      return 'Never';
    }

    return \Drupal::service('date.formatter')->format((int) $timestamp, 'custom', 'Y-m-d H:i:s');
  }

}
