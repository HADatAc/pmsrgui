<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Shared ingestion utilities.
 */
class IngestionController extends ControllerBase {

  /**
   * Invalidate statistics cache for specific ontologies.
   *
   * @param array $ontologies
   *   Array of ontology abbreviations to invalidate: ins, pmsr, uberon, ncit.
   */
  public static function invalidateStatisticsCache(array $ontologies) {
    $cache_invalidator = \Drupal::service('cache_tags.invalidator');
    $tags_to_invalidate = [
      'pmsr_statistics:global',
      'pmsr_statistics:ontologies',
      'pmsr_statistics:classes',
      'pmsr_statistics:instances',
    ];

    $stats_by_ontology = [
      'ins' => 'pmsr_statistics:instruments',
      'pmsr' => 'pmsr_statistics:procedures',
      'uberon' => 'pmsr_statistics:anatomy',
      'ncit' => 'pmsr_statistics:devices',
    ];

    foreach ($ontologies as $abbrev) {
      $lower = strtolower($abbrev);
      $tags_to_invalidate[] = 'pmsr_ontology:' . $lower;
      if (isset($stats_by_ontology[$lower])) {
        $tags_to_invalidate[] = $stats_by_ontology[$lower];
      }
    }

    $tags_to_invalidate = array_values(array_unique($tags_to_invalidate));

    if (!empty($tags_to_invalidate)) {
      $cache_invalidator->invalidateTags($tags_to_invalidate);
      \Drupal::logger('pmsr')->info('Invalidated statistics cache for ontologies: @ontologies', [
        '@ontologies' => implode(', ', $ontologies),
      ]);
    }
  }
}
