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
        // Get platform instances (using manager email '_' to get all)
        $response = $api->listByManagerEmail('platforminstance', '_', $pageSize, $offset);
        $platforms = $api->parseObjectResponse($response, 'listByManagerEmail');
        
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
        $response = $api->listByManagerEmail('platforminstance', '_', $pageSize, $offset);
        $batch = $api->parseObjectResponse($response, 'listByManagerEmail');
        
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
            if (is_object($deployment) && !empty($deployment->instrumentUri)) {
              $uniqueInstruments[$deployment->instrumentUri] = true;
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
   * Returns the Statistics page.
   */
  public function content() {
    // Get cache service
    $cache = \Drupal::cache('data');
    
    // Get API connector service
    $api = \Drupal::service('rep.api_connector');
    
    // Fetch GLOBAL statistics from cache or API (ontologies, classes, instances)
    
    // 1. Ontologies count
    $ontologiesCount = 0;
    $cache_ontologies = $cache->get('pmsr_statistics:ontologies');
    if ($cache_ontologies) {
      $ontologiesCount = $cache_ontologies->data;
    } else {
      try {
        $ontologiesResponse = $api->statisticsOntologiesCount();
        $ontologiesData = $api->parseObjectResponse($ontologiesResponse, 'statisticsOntologiesCount');
        if ($ontologiesData && isset($ontologiesData->total)) {
          $ontologiesCount = $ontologiesData->total;
          // Cache with global tag (invalidated on any ontology change)
          $cache->set('pmsr_statistics:ontologies', $ontologiesCount, \Drupal\Core\Cache\Cache::PERMANENT, ['pmsr_statistics:global']);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch ontologies count: ' . $e->getMessage());
      }
    }
    
    // 2. Classes count
    $classesCount = 0;
    $cache_classes = $cache->get('pmsr_statistics:classes');
    if ($cache_classes) {
      $classesCount = $cache_classes->data;
    } else {
      try {
        $classesResponse = $api->statisticsClassesCount();
        $classesData = $api->parseObjectResponse($classesResponse, 'statisticsClassesCount');
        if ($classesData && isset($classesData->total)) {
          $classesCount = $classesData->total;
          // Cache with global tag (invalidated on any ontology change)
          $cache->set('pmsr_statistics:classes', $classesCount, \Drupal\Core\Cache\Cache::PERMANENT, ['pmsr_statistics:global']);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch classes count: ' . $e->getMessage());
      }
    }
    
    // 3. Instances count
    $instancesCount = 0;
    $cache_instances = $cache->get('pmsr_statistics:instances');
    if ($cache_instances) {
      $instancesCount = $cache_instances->data;
    } else {
      try {
        $instancesResponse = $api->statisticsInstancesCount();
        $instancesData = $api->parseObjectResponse($instancesResponse, 'statisticsInstancesCount');
        if ($instancesData && isset($instancesData->total)) {
          $instancesCount = $instancesData->total;
          // Cache with global tag (invalidated on any ingestion)
          $cache->set('pmsr_statistics:instances', $instancesCount, \Drupal\Core\Cache\Cache::PERMANENT, ['pmsr_statistics:global', 'pmsr_statistics:instances']);
        }
      } catch (\Exception $e) {
        \Drupal::logger('pmsr')->error('Failed to fetch instances count: ' . $e->getMessage());
      }
    }
    
    // Fetch ONTOLOGY-SPECIFIC statistics from cache or API with ontology-specific cache tags
    
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
              
              // Fetch platform count for this organization
              $platformCount = $this->getPlatformCountByOrganization($api, $contributorUri);
              
              // Fetch simulator count for this organization
              $simulatorCount = $this->getSimulatorCountByOrganization($api, $contributorUri);
              
              $members[] = [
                'uri' => $contributorUri,
                'label' => $orgData->label ?? 'Unknown Organization',
                'shortName' => $orgData->hasShortName ?? $orgData->label ?? 'Unknown',
                'fullName' => $orgData->name ?? $orgData->label ?? 'Unknown Organization',
                'image' => $imageUrl,
                'peopleCount' => $peopleCount,
                'platformCount' => $platformCount,
                'simulatorCount' => $simulatorCount,
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
      
      // Row 6: Registered Simulators
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Simulators</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $simulatorCount = $member['simulatorCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $simulatorCount . '</strong>';
        $output .= '</td>';
      }
      $output .= '</tr>';
      
      // Row 7: Registered Platforms/Laboratories
      $output .= '<tr>';
      $output .= '<td class="align-middle" style="padding: 15px; background-color: #f8f9fa;"><strong>Registered Platforms</strong></td>';
      for ($i = 0; $i < $displayCount; $i++) {
        $member = $members[$i];
        $platformCount = $member['platformCount'] ?? 0;
        $output .= '<td class="text-center align-middle" style="padding: 15px; background-color: white;">';
        $output .= '<strong style="font-size: 2.4rem; color: #0d6efd;">' . $platformCount . '</strong>';
        $output .= '</td>';
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
      '#attached' => [
        'library' => [
          'pmsr/statistics',
        ],
      ],
    ];
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
    $cache = \Drupal::cache('data');
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
    
    // Try to get from cache first
    $cid = 'pmsr_statistics:global_combined';
    $cached = $cache->get($cid);
    
    if ($cached) {
      $result['data'] = $cached->data;
      $result['cached_at'] = date('c', $cached->created);
      $result['cache_age_seconds'] = time() - $cached->created;
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
      
      // Cache for 1 hour with global tag
      $cache->set($cid, $result['data'], time() + 3600, ['pmsr_statistics:global']);
      $result['cached_at'] = date('c');
      $result['cache_age_seconds'] = 0;
    }
    
    return new JsonResponse($result);
  }
  
  /**
   * API endpoint: Refresh (invalidate) statistics cache.
   * 
   * POST /pmsr/api/statistics/refresh
   * 
   * Returns:
   * {
   *   "success": true,
   *   "message": "Statistics cache invalidated",
   *   "cache_tags": ["pmsr_statistics:global", ...]
   * }
   */
  public function refreshStatisticsCache() {
    $tags = [
      'pmsr_statistics:global',
      'pmsr_statistics:ontologies',
      'pmsr_statistics:classes',
      'pmsr_statistics:instances',
    ];
    
    \Drupal\Core\Cache\Cache::invalidateTags($tags);
    
    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Statistics cache invalidated',
      'cache_tags' => $tags,
    ]);
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
