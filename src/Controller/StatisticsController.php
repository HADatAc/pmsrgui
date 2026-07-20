<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\rep\Utils;
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
    $output .= '<h1 class="text-primary" style="font-size: 3.4rem; font-weight: bold;">' . $instrumentsCount . '</h1>';
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
    $output .= '<h1 class="text-primary" style="font-size: 3.4rem; font-weight: bold;">' . $proceduresCount . '</h1>';
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
    $output .= '<h1 class="text-primary" style="font-size: 3.4rem; font-weight: bold;">' . $anatomyCount . '</h1>';
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
    $output .= '<h1 class="text-primary" style="font-size: 3.4rem; font-weight: bold;">' . $devicesCount . '</h1>';
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

    // Section (b) PMSR Project Members
    $output .= '<div class="row mt-5">';
    $output .= '<div class="col-12">';
    $output .= '<h2>PMSR Project Members</h2>';
    $output .= '</div>';
    $output .= '</div>';

    // Fetch PMSR project and its members
    $projectUri = 'https://pmsr.net/ont/PJT1742783481383251';
    $members = [];
    
    try {
      $projectResponse = $api->getUri($projectUri);
      $projectData = $api->parseObjectResponse($projectResponse, 'getUri');
      
      if ($projectData && isset($projectData->contributorUris) && is_array($projectData->contributorUris)) {
        // Fetch each contributor organization
        foreach ($projectData->contributorUris as $contributorUri) {
          try {
            $orgResponse = $api->getUri($contributorUri);
            $orgData = $api->parseObjectResponse($orgResponse, 'getUri');
            if ($orgData) {
              // Use Utils::getAPIImage() to properly construct image URL
              $rep_module_path = \Drupal::service('extension.list.module')->getPath('rep');
              $placeholderImage = base_path() . $rep_module_path . '/images/organization_placeholder.png';
              $imageUrl = null;
              if (!empty($orgData->hasImageUri)) {
                $imageUrl = Utils::getAPIImage($contributorUri, $orgData->hasImageUri, $placeholderImage);
              } else {
                $imageUrl = $placeholderImage;
              }
              
              // Fetch people count for this organization (including sub-organizations)
              $peopleCount = $this->getTotalPeopleCount($api, $contributorUri);
              
              $members[] = [
                'uri' => $contributorUri,
                'label' => $orgData->label ?? 'Unknown Organization',
                'shortName' => $orgData->hasShortName ?? $orgData->label ?? 'Unknown',
                'fullName' => $orgData->name ?? $orgData->label ?? 'Unknown Organization',
                'image' => $imageUrl,
                'peopleCount' => $peopleCount,
              ];
            }
          } catch (\Exception $e) {
            \Drupal::logger('pmsr')->warning('Failed to fetch organization ' . $contributorUri . ': ' . $e->getMessage());
          }
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('pmsr')->error('Failed to fetch PMSR project: ' . $e->getMessage());
    }

    // Display members table (up to 10 columns + Description column)
    if (!empty($members)) {
      $output .= '<div class="row mt-3">';
      $output .= '<div class="col-12">';
      $output .= '<div class="table-responsive">';
      $output .= '<table class="table table-bordered" style="border-color: #adb5bd;">';
      
      $displayCount = min(count($members), 10);
      
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
      $output .= '</tr>';
      
      // Row 4: People Registered in PMSR
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>People Registered in PMSR</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $peopleCount = $member['peopleCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $peopleCount . '</strong>';
        $output .= '</td>';
      }
      $output .= '</tr>';
      
      // Row 5: Registered Scenarios (placeholder)
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Scenarios</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $output .= '<td class="text-center align-middle text-muted" style="padding: 15px; background-color: white;">-</td>';
      }
      $output .= '</tr>';
      
      // Row 6: Registered Simulators (placeholder)
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Simulators</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $output .= '<td class="text-center align-middle text-muted" style="padding: 15px; background-color: white;">-</td>';
      }
      $output .= '</tr>';
      
      // Row 7: Registered Laboratories/Rooms (placeholder)
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Laboratories/Rooms</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $output .= '<td class="text-center align-middle text-muted" style="padding: 15px; background-color: white;">-</td>';
      }
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
      $output .= '<p class="text-muted">No project members found.</p>';
      $output .= '</div>';
      $output .= '</div>';
    }

    $output .= '</div>'; // End container

    return [
      '#title' => 'Statistics',
      '#markup' => Markup::create($output),
    ];
  }
}
