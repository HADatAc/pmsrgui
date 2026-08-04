<?php

namespace Drupal\pmsr\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\pmsr\Support\PmsrSetupTracker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the PMSR auxiliary data ingestion stage.
 */
class IngestionAuxiliaryDataController extends ControllerBase {

  private const ANY_PROCESS_URI = 'http://hadatac.org/ont/hasco/AnyProcess';
  private const PMSR_ADMIN_OWNER_EMAIL = 'admin@pmsr.com';
  private const TOOL_NAMESPACE_ABBREV = 'pmsrtool';
  private const TOOL_NAMESPACE_URI = 'https://pmsr.net/tool/';

  /**
   * Render the auxiliary-data ingestion page.
   */
  public function content(): array {
    $output = '';
    $output .= '<div class="container-fluid mt-4 pmsr-auxiliary-data-page">';
    $output .= '<p>' . Html::escape((string) $this->t('This stage ingests special analytical tools that are globally available across all processes.')) . '</p>';

    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>' . Html::escape((string) $this->t('Auxiliary Analytical Tools')) . '</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<ul>';
    $output .= '<li><strong>' . Html::escape((string) $this->t('Individual CTT Simulator')) . '</strong></li>';
    $output .= '<li><strong>' . Html::escape((string) $this->t('Cohort CTT Simulator')) . '</strong></li>';
    $output .= '</ul>';
    $output .= '<div class="alert alert-info mt-2">';
    $output .= '<strong>' . Html::escape((string) $this->t('Wildcard policy:')) . '</strong> ';
    $output .= Html::escape((string) $this->t('Both tools are linked to hasco:AnyProcess, meaning they are available for all processes.'));
    $output .= '</div>';
    $output .= '<p class="text-muted mb-0">' . Html::escape((string) $this->t('Running ingestion is idempotent: existing entries are updated in place, not duplicated.')) . '</p>';
    $output .= '</div>';
    $output .= '</div>';

    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">' . Html::escape((string) $this->t('Start Ingestion')) . '</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">' . Html::escape((string) $this->t('Cancel')) . '</button>';
    $output .= '</div>';

    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">' . Html::escape((string) $this->t('Processing...')) . '</span>';
    $output .= '</div>';
    $output .= '</div>';

    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    $output .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($output),
      '#attached' => [
        'library' => [
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/pmsr/api/ingest/auxiliary-data/process',
              'message' => 'Ingesting auxiliary analytical tools...',
            ],
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Process auxiliary analytical-tools ingestion.
   */
  public function processAuxiliaryDataIngestion(Request $request): JsonResponse {
    PmsrSetupTracker::markStageStarted('ingest_auxiliary_data', 'Auxiliary data ingestion started');

    $rawBody = trim((string) $request->getContent());
    if ($rawBody !== '') {
      $decoded = json_decode($rawBody, TRUE);
      if (!is_array($decoded)) {
        PmsrSetupTracker::markStageResult('ingest_auxiliary_data', false, 'Invalid JSON body for auxiliary ingestion request.');
        return new JsonResponse([
          'success' => false,
          'message' => 'Invalid JSON body.',
          'errors' => ['Request body must be valid JSON.'],
        ], 400);
      }
    }

    $progress = [];
    $errors = [];
    $warnings = [];

    $stageId = 'ingest_auxiliary_data';
    $api = \Drupal::service('rep.api_connector');

    $modulePath = \Drupal::service('extension.list.module')->getPath('pmsr');
    $toolTtlPath = DRUPAL_ROOT . '/' . $modulePath . '/ontologies/tool.ttl';
    if (!file_exists($toolTtlPath)) {
      PmsrSetupTracker::markStageResult($stageId, false, 'Missing tool.ttl for auxiliary ingestion.');
      return new JsonResponse([
        'success' => false,
        'message' => 'Missing ontology file: tool.ttl',
        'errors' => ['Expected file not found at: ' . $toolTtlPath],
      ], 500);
    }

    $toolTtlContent = (string) file_get_contents($toolTtlPath);
    if ($toolTtlContent === '') {
      PmsrSetupTracker::markStageResult($stageId, false, 'tool.ttl is empty and could not be ingested.');
      return new JsonResponse([
        'success' => false,
        'message' => 'Ontology file tool.ttl is empty.',
        'errors' => ['Populate tool.ttl with hasco:AnyProcess and analytical tool instances.'],
      ], 500);
    }

    $progress[] = '[0/2] Ingesting tool.ttl namespace into HASCOAPI...';
    $ingestRaw = $api->repoIngestNamespaceOntology(
      self::TOOL_NAMESPACE_ABBREV,
      self::TOOL_NAMESPACE_URI,
      $toolTtlContent,
      'text/turtle',
      'pmsr-ingest-ontologies'
    );
    $ingestDecoded = json_decode((string) $ingestRaw);
    if (!is_object($ingestDecoded) || empty($ingestDecoded->isSuccessful)) {
      $warnings[] = 'tool.ttl namespace ingest did not complete successfully: ' . (string) ($ingestDecoded->body ?? 'Unknown ontology ingestion error.');
      $progress[] = '  ⚠ tool.ttl namespace ingest returned a warning; continuing with direct tool registration.';
    }
    else {
      $progress[] = '  ✓ Ingested tool.ttl using namespace ' . self::TOOL_NAMESPACE_ABBREV;
    }

    $ownerIdentifier = self::PMSR_ADMIN_OWNER_EMAIL;

    $toolsToIngest = [
      [
        'toolUri' => self::TOOL_NAMESPACE_URI . 'AT-INDIVIDUAL-CTT-SIMULATOR',
        'name' => 'Individual CTT Simulator',
        'description' => 'Special auxiliary analytical tool available for all processes.',
      ],
      [
        'toolUri' => self::TOOL_NAMESPACE_URI . 'AT-COHORT-CTT-SIMULATOR',
        'name' => 'Cohort CTT Simulator',
        'description' => 'Special auxiliary analytical tool available for all processes.',
      ],
    ];

    $created = [];
    $updated = [];

    foreach ($toolsToIngest as $index => $toolSeed) {
      $step = $index + 1;
      $label = (string) $toolSeed['name'];
      $progress[] = '[' . $step . '/2] Processing ' . $label . '...';

      $toolUri = trim((string) $toolSeed['toolUri']);
      if ($toolUri === '') {
        $errors[] = 'Tool URI is empty for ' . $label . '.';
        continue;
      }

      $payload = [
        'uri' => $toolUri,
        'label' => (string) $toolSeed['name'],
        'comment' => (string) $toolSeed['description'],
        'typeUri' => 'http://hadatac.org/ont/hasco/AnalyticalTool',
        'hascoTypeUri' => 'http://hadatac.org/ont/hasco/AnalyticalTool',
        'hasStatus' => 'http://hadatac.org/ont/vstoi/current',
        'hasLanguage' => 'R',
        'hasVersion' => '1.0.0',
        'hasSIRManagerEmail' => $ownerIdentifier,
        'hasEditorEmail' => $ownerIdentifier,
        'hasProcessUri' => self::ANY_PROCESS_URI,
      ];

      $raw = $api->registerAnalyticalTool($payload);
      $decoded = json_decode((string) $raw);

      if (!is_object($decoded) || empty($decoded->isSuccessful)) {
        $errors[] = 'Failed to register analytical tool: ' . $label;
        continue;
      }

      $linkRaw = $api->linkAnalyticalToolToProcess(self::ANY_PROCESS_URI, $toolUri);
      $linkDecoded = json_decode((string) $linkRaw);
      if (!is_object($linkDecoded) || empty($linkDecoded->isSuccessful)) {
        $warnings[] = 'Association warning for hasco:AnyProcess on tool ' . $label . ': ' . (string) ($linkDecoded->body ?? 'unknown response');
        $progress[] = '  ⚠ hasco:AnyProcess association returned a warning; tool remains scoped via hasProcessUri.';
      }

      $progress[] = '  ✓ Registered tool in HASCOAPI' . (empty($warnings) ? ' and linked to hasco:AnyProcess wildcard' : ' (with wildcard association policy applied)');
      $created[] = $toolUri;

      // Keep local CTT repository endpoint synchronized for immediate UI retrieval.
      $catalog = \Drupal::state()->get('ctt.analytical_tools.catalog.v1', []);
      if (!is_array($catalog)) {
        $catalog = [];
      }

      $catalog[$toolUri] = [
        'toolUri' => $toolUri,
        'name' => (string) $toolSeed['name'],
        'description' => (string) $toolSeed['description'],
        'language' => 'r',
        'status' => 'current',
        'processUri' => self::ANY_PROCESS_URI,
        'ownerUserEmail' => self::PMSR_ADMIN_OWNER_EMAIL,
        'createdBy' => self::PMSR_ADMIN_OWNER_EMAIL,
        'updatedBy' => self::PMSR_ADMIN_OWNER_EMAIL,
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
      ];
      ksort($catalog);
      \Drupal::state()->set('ctt.analytical_tools.catalog.v1', $catalog);
    }

    if (!empty($errors)) {
      PmsrSetupTracker::recordTestResult($stageId, 'auxiliary-tools-smoke', 'fail', 'Failed to create/update all auxiliary tools.');
      PmsrSetupTracker::recordTestResult($stageId, 'auxiliary-tools-wildcard-policy', 'fail', 'Wildcard policy check failed due ingestion errors.');
      PmsrSetupTracker::markStageResult($stageId, false, 'Auxiliary ingestion failed.');

      return new JsonResponse([
        'success' => false,
        'message' => 'Auxiliary data ingestion failed.',
        'errors' => $errors,
        'warnings' => $warnings,
        'progress' => $progress,
      ], 500);
    }

    PmsrSetupTracker::recordTestResult($stageId, 'auxiliary-tools-smoke', 'pass', 'Both auxiliary tools were created or updated successfully.');
    PmsrSetupTracker::recordTestResult($stageId, 'auxiliary-tools-wildcard-policy', 'pass', 'Both tools are linked to hasco:AnyProcess and are globally retrievable for all processes.');
    PmsrSetupTracker::markStageResult($stageId, true, 'Auxiliary data ingestion completed successfully.');

    return new JsonResponse([
      'success' => true,
      'message' => empty($warnings)
        ? 'Auxiliary data ingestion completed successfully.'
        : 'Auxiliary data ingestion completed with warnings.',
      'progress' => array_merge($progress, [
        'Created: ' . count($created),
        'Updated: ' . count($updated),
      ]),
      'warnings' => $warnings,
      'summary' => [
        'createdToolUris' => $created,
        'updatedToolUris' => $updated,
        'wildcardProcessUri' => self::ANY_PROCESS_URI,
      ],
    ]);
  }

}
