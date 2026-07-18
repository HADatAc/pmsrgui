<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides statistics page for PMSR.
 */
class StatisticsController extends ControllerBase {

  /**
   * Returns the Statistics page.
   */
  public function content() {
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
    $output .= '<div class="col-md-4">';
    $output .= '<div class="card text-center">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title"><i class="fas fa-desktop fa-2x mb-3" style="color: #0d6efd;"></i></h5>';
    $output .= '<p class="card-text"><strong># Simulator Models</strong></p>';
    $output .= '<h3 class="text-primary" id="simulator-models-count">Loading...</h3>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    // Card 2: Clinical Procedures
    $output .= '<div class="col-md-4">';
    $output .= '<div class="card text-center">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title"><i class="fas fa-stethoscope fa-2x mb-3" style="color: #0d6efd;"></i></h5>';
    $output .= '<p class="card-text"><strong># Clinical Procedures</strong></p>';
    $output .= '<h3 class="text-primary" id="clinical-procedures-count">Loading...</h3>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    // Card 3: Anatomical Structures
    $output .= '<div class="col-md-4">';
    $output .= '<div class="card text-center">';
    $output .= '<div class="card-body">';
    $output .= '<h5 class="card-title"><i class="fas fa-user fa-2x mb-3" style="color: #0d6efd;"></i></h5>';
    $output .= '<p class="card-text"><strong># Anatomical Structures</strong></p>';
    $output .= '<h3 class="text-primary" id="anatomical-structures-count">Loading...</h3>';
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

    // Add JavaScript to fetch real statistics from hascoapi
    $output .= '
    <script>
    (function() {
      // Fetch global statistics from hascoapi
      async function loadGlobalStats() {
        try {
          // Fetch Simulator Models (Instruments) count
          const instrumentsResponse = await fetch("/hascoapi/api/statistics/instruments/count");
          if (instrumentsResponse.ok) {
            const instrumentsData = await instrumentsResponse.json();
            if (instrumentsData.isSuccessful && instrumentsData.body) {
              const bodyData = typeof instrumentsData.body === "string" ? JSON.parse(instrumentsData.body) : instrumentsData.body;
              document.getElementById("simulator-models-count").textContent = bodyData.total || 0;
            }
          } else {
            document.getElementById("simulator-models-count").textContent = "Error";
          }

          // Fetch Clinical Procedures count
          const proceduresResponse = await fetch("/hascoapi/api/statistics/procedures/count");
          if (proceduresResponse.ok) {
            const proceduresData = await proceduresResponse.json();
            if (proceduresData.isSuccessful && proceduresData.body) {
              const bodyData = typeof proceduresData.body === "string" ? JSON.parse(proceduresData.body) : proceduresData.body;
              document.getElementById("clinical-procedures-count").textContent = bodyData.total || 0;
            }
          } else {
            document.getElementById("clinical-procedures-count").textContent = "Error";
          }

          // Fetch Anatomical Structures count
          const anatomyResponse = await fetch("/hascoapi/api/statistics/anatomy/count");
          if (anatomyResponse.ok) {
            const anatomyData = await anatomyResponse.json();
            if (anatomyData.isSuccessful && anatomyData.body) {
              const bodyData = typeof anatomyData.body === "string" ? JSON.parse(anatomyData.body) : anatomyData.body;
              document.getElementById("anatomical-structures-count").textContent = bodyData.total || 0;
            }
          } else {
            document.getElementById("anatomical-structures-count").textContent = "Error";
          }
        } catch (error) {
          console.error("Error loading statistics:", error);
          document.getElementById("simulator-models-count").textContent = "Error";
          document.getElementById("clinical-procedures-count").textContent = "Error";
          document.getElementById("anatomical-structures-count").textContent = "Error";
        }
      }

      // Load stats when page is ready
      loadGlobalStats();
    })();
    </script>
    ';

    return [
      '#title' => 'Statistics',
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
        ],
      ],
    ];
  }
}
