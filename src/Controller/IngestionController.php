<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for PMSR data ingestion operations.
 */
class IngestionController extends ControllerBase {

  /**
   * Ingest PMSR ontologies (pmsr, uberon, ncit) from code.
   */
  public function ingestOntologies() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest PMSR, UBERON, and NCIT ontologies from code into Apache Fuseki.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>Ontologies to be ingested:</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<ul>';
    $output .= '<li><strong>PMSR Ontology</strong> - Medical Simulation Process ontology</li>';
    $output .= '<li><strong>UBERON Ontology</strong> - Anatomical structures and entities</li>';
    $output .= '<li><strong>NCIT Ontology</strong> - NCI Thesaurus subset for medical devices</li>';
    $output .= '</ul>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">Cancel</button>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/hascoapi/api/pmsr/ingest/ontologies',
              'message' => 'Ingesting ontologies...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Ingest INS instruments.
   */
  public function ingestInstruments() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest instrument definitions from INS templates.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>Instrument Ingestion</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<p>This will process all INS (Instrument Specification) templates and create instrument instances in the system.</p>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">Cancel</button>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/hascoapi/api/pmsr/ingest/instruments',
              'message' => 'Ingesting instruments...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Ingest KRG geography and organizations.
   */
  public function ingestGeography() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest geography data and organizational structures from KRG templates.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>KRG Geography & Organizations</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<p>This will process KRG (Knowledge Representation for Geography) templates including:</p>';
    $output .= '<ul>';
    $output .= '<li>Geographic locations and regions</li>';
    $output .= '<li>Organizational hierarchies</li>';
    $output .= '<li>Institutional affiliations</li>';
    $output .= '</ul>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">Cancel</button>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/hascoapi/api/pmsr/ingest/geography',
              'message' => 'Ingesting geography data...',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Ingest KGR people.
   */
  public function ingestPeople() {
    $output = '';
    
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<p>This page will ingest people data from KGR templates.</p>';
    
    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-primary text-white">';
    $output .= '<h4>KGR People Data</h4>';
    $output .= '</div>';
    $output .= '<div class="card-body">';
    $output .= '<p>This will process KGR (Knowledge Representation for Resources) templates including:</p>';
    $output .= '<ul>';
    $output .= '<li>Person profiles</li>';
    $output .= '<li>Roles and affiliations</li>';
    $output .= '<li>Contact information</li>';
    $output .= '</ul>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div class="mt-4">';
    $output .= '<button class="btn btn-primary btn-lg btn-start-ingestion">Start Ingestion</button>';
    $output .= '<button class="btn btn-secondary btn-lg ms-2" onclick="history.back()">Cancel</button>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
          'pmsr/ingestion',
        ],
        'drupalSettings' => [
          'pmsr' => [
            'ingestion' => [
              'endpoint' => '/hascoapi/api/pmsr/ingest/people',
              'message' => 'Ingesting people data...',
            ],
          ],
        ],
      ],
    ];
  }

}
