<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides statistics page for PMSR.
 * 
 * CACHING SYSTEM:
 * ===============
 * This controller implements ontology-specific caching to improve performance
 * and ensure statistics are only recalculated when their source ontologies change.
 * 
 * Cache Structure:
 * - Each statistic has its own cache entry with a permanent lifetime
 * - Cache keys: pmsr_statistics:{type} where type is instruments|procedures|anatomy|devices
 * - Each cache is tagged with its source ontology: pmsr_ontology:{ontology}
 * 
 * Cache Tags Mapping:
 * - pmsr_ontology:ins     → Instruments count (INS ontology)
 * - pmsr_ontology:pmsr    → Clinical Procedures count (PMSR ontology)
 * - pmsr_ontology:uberon  → Anatomical Structures count (UBERON ontology)
 * - pmsr_ontology:ncit    → Medical Devices count (NCIT ontology)
 * 
 * Cache Invalidation:
 * - Caches are invalidated ONLY when their specific ontology is updated/deleted
 * - Invalidation occurs in IngestionController::processOntologyIngestion()
 * - Manual invalidation: IngestionController::invalidateStatisticsCache(['ins', 'pmsr', ...])
 * 
 * Benefits:
 * - Fast page loads (no API calls when cached)
 * - Precise invalidation (only affected statistics are recalculated)
 * - Independent statistics (one ontology update doesn't affect others)
 */
class StatisticsController extends ControllerBase {

  /**
   * Returns the Statistics page.
   */
  public function content() {
    // Get cache service
    $cache = \Drupal::cache('data');
    
    // Get API connector service
    $api = \Drupal::service('rep.api_connector');
    
    // Fetch statistics from cache or API with ontology-specific cache tags
    
    // 1. Instruments (INS ontology)
    $instrumentsCount = 0;
    $cache_instruments = $cache->get('pmsr_statistics:instruments');
    if ($cache_instruments) {
      $instrumentsCount = $cache_instruments->data;
    } else {
      try {
        $instrumentsResponse = $api->statisticsInstrumentCount();
        $instrumentsData = $api->parseObjectResponse($instrumentsResponse, 'statisticsInstrumentCount');
        if ($instrumentsData && isset($instrumentsData->total)) {
          $instrumentsCount = $instrumentsData->total;
          // Cache with INS ontology tag
          $cache->set('pmsr_statistics:instruments', $instrumentsCount, \Drupal\Core\Cache\Cache::PERMANENT, ['pmsr_ontology:ins']);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch instruments count: ' . $e->getMessage());
      }
    }
    
    // 2. Clinical Procedures (PMSR ontology)
    $proceduresCount = 0;
    $cache_procedures = $cache->get('pmsr_statistics:procedures');
    if ($cache_procedures) {
      $proceduresCount = $cache_procedures->data;
    } else {
      try {
        $proceduresResponse = $api->statisticsProceduresCount();
        $proceduresData = $api->parseObjectResponse($proceduresResponse, 'statisticsProceduresCount');
        if ($proceduresData && isset($proceduresData->total)) {
          $proceduresCount = $proceduresData->total;
          // Cache with PMSR ontology tag
          $cache->set('pmsr_statistics:procedures', $proceduresCount, \Drupal\Core\Cache\Cache::PERMANENT, ['pmsr_ontology:pmsr']);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch procedures count: ' . $e->getMessage());
      }
    }
    
    // 3. Anatomical Structures (UBERON ontology)
    $anatomyCount = 0;
    $cache_anatomy = $cache->get('pmsr_statistics:anatomy');
    if ($cache_anatomy) {
      $anatomyCount = $cache_anatomy->data;
    } else {
      try {
        $anatomyResponse = $api->statisticsAnatomyCount();
        $anatomyData = $api->parseObjectResponse($anatomyResponse, 'statisticsAnatomyCount');
        if ($anatomyData && isset($anatomyData->total)) {
          $anatomyCount = $anatomyData->total;
          // Cache with UBERON ontology tag
          $cache->set('pmsr_statistics:anatomy', $anatomyCount, \Drupal\Core\Cache\Cache::PERMANENT, ['pmsr_ontology:uberon']);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch anatomy count: ' . $e->getMessage());
      }
    }

    // 4. Medical Devices (NCIT ontology)
    $devicesCount = 0;
    $cache_devices = $cache->get('pmsr_statistics:devices');
    if ($cache_devices) {
      $devicesCount = $cache_devices->data;
    } else {
      try {
        $devicesResponse = $api->statisticsMedicalDevicesCount();
        $devicesData = $api->parseObjectResponse($devicesResponse, 'statisticsMedicalDevicesCount');
        if ($devicesData && isset($devicesData->total)) {
          $devicesCount = $devicesData->total;
          // Cache with NCIT ontology tag
          $cache->set('pmsr_statistics:devices', $devicesCount, \Drupal\Core\Cache\Cache::PERMANENT, ['pmsr_ontology:ncit']);
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
    $output .= '</div>';
    $output .= '</div>';

    $output .= '<div class="row mt-3">';
    
    // Card 1: Simulator Models
    $output .= '<div class="col-md-3">';
    $output .= '<div class="card text-center">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title"><i class="fas fa-desktop fa-2x mb-3" style="color: #0d6efd;"></i></h5>';
    $output .= '<p class="card-text"><strong># Simulator Models</strong></p>';
    $output .= '<h1 class="text-primary" style="font-size: 4rem; font-weight: bold;">' . $instrumentsCount . '</h1>';
    $output .= '<div class="mt-3 text-start small" style="color: #6c757d;">';
    $output .= '<div><strong>Source:</strong> INS Ontology</div>';
    $output .= '<div><strong>Named Graph:</strong> <code style="color: #6c757d;">http://hadatac.org/ont/ins</code></div>';
    $output .= '<div><strong>Entry Point:</strong> <code style="color: #6c757d;">vstoi:Instrument</code></div>';
    $output .= '<div><strong>Bound to:</strong> <code style="color: #6c757d;">hasco:InstrumentEntryPoint</code></div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    // Card 2: Clinical Procedures
    $output .= '<div class="col-md-3">';
    $output .= '<div class="card text-center">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title"><i class="fas fa-stethoscope fa-2x mb-3" style="color: #0d6efd;"></i></h5>';
    $output .= '<p class="card-text"><strong># Clinical Procedures</strong></p>';
    $output .= '<h1 class="text-primary" style="font-size: 4rem; font-weight: bold;">' . $proceduresCount . '</h1>';
    $output .= '<div class="mt-3 text-start small" style="color: #6c757d;">';
    $output .= '<div><strong>Source:</strong> NCIT-PMSR</div>';
    $output .= '<div><strong>Named Graph:</strong> <code style="color: #6c757d;">http://pmsr.net/ont/pmsr</code></div>';
    $output .= '<div><strong>Entry Point:</strong> <code style="color: #6c757d;">pmsr:MedicalSimulationProcessStem</code></div>';
    $output .= '<div><strong>Bound to:</strong> <code style="color: #6c757d;">hasco:WorkflowStemEntryPoint</code></div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    // Card 3: Anatomical Structures
    $output .= '<div class="col-md-3">';
    $output .= '<div class="card text-center">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title"><i class="fas fa-user fa-2x mb-3" style="color: #0d6efd;"></i></h5>';
    $output .= '<p class="card-text"><strong># Anatomical Structures</strong></p>';
    $output .= '<h1 class="text-primary" style="font-size: 4rem; font-weight: bold;">' . $anatomyCount . '</h1>';
    $output .= '<div class="mt-3 text-start small" style="color: #6c757d;">';
    $output .= '<div><strong>Source:</strong> UBERON</div>';
    $output .= '<div><strong>Named Graph:</strong> <code style="color: #6c757d;">http://purl.obolibrary.org/obo/uberon.owl</code></div>';
    $output .= '<div><strong>Entry Point:</strong> <code style="color: #6c757d;">UBERON_0001062</code></div>';
    $output .= '<div><strong>Bound to:</strong> <code style="color: #6c757d;">hasco:AnatomicalPartEntryPoint</code></div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    // Card 4: Medical Devices
    $output .= '<div class="col-md-3">';
    $output .= '<div class="card text-center">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title"><i class="fas fa-syringe fa-2x mb-3" style="color: #0d6efd;"></i></h5>';
    $output .= '<p class="card-text"><strong># Medical Devices</strong></p>';
    $output .= '<h1 class="text-primary" style="font-size: 4rem; font-weight: bold;">' . $devicesCount . '</h1>';
    $output .= '<div class="mt-3 text-start small" style="color: #6c757d;">';
    $output .= '<div><strong>Source:</strong> NCIT</div>';
    $output .= '<div><strong>Named Graph:</strong> <code style="color: #6c757d;">http://purl.obolibrary.org/obo/ncit.owl</code></div>';
    $output .= '<div><strong>Entry Point:</strong> <code style="color: #6c757d;">NCIT_C97325</code></div>';
    $output .= '<div><strong>Bound to:</strong> <code style="color: #6c757d;">hasco:MedicalDeviceEntryPoint</code></div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    $output .= '</div>'; // End stats cards row

    // Section (b) Statistics by Organization
    $output .= '<div class="row mt-5">';
    $output .= '<div class="col-12">';
    $output .= '<h2>Statistics by Organization</h2>';
    $output .= '</div>';
    $output .= '</div>';

    $output .= '<div class="row mt-3">';
    $output .= '<div class="col-12">';
    $output .= '<p class="text-muted">Coming soon...</p>';
    $output .= '</div>';
    $output .= '</div>';

    $output .= '</div>'; // End container

    return [
      '#title' => 'Statistics',
      '#markup' => Markup::create($output),
    ];
  }
}
