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
    
    $output .= '<div class="container mt-4">';
    $output .= '<h1>Ingest PMSR Ontologies</h1>';
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
    $output .= '<button class="btn btn-primary btn-lg" onclick="startIngestion()">Start Ingestion</button>';
    $output .= '<a href="/pmsr" class="btn btn-secondary btn-lg ms-2">Cancel</a>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    // Add JavaScript for ingestion
    $output .= '
    <script>
    function startIngestion() {
      document.getElementById("ingestion-status").style.display = "block";
      document.getElementById("status-message").textContent = "Ingesting ontologies...";
      
      fetch("/hascoapi/api/pmsr/ingest/ontologies", {
        method: "POST"
      })
      .then(response => response.json())
      .then(data => {
        document.getElementById("ingestion-status").style.display = "none";
        
        const resultsDiv = document.getElementById("ingestion-results");
        resultsDiv.style.display = "block";
        
        if (data.isSuccessful) {
          resultsDiv.innerHTML = `
            <div class="alert alert-success">
              <h4>✓ Ingestion Completed Successfully</h4>
              <p>${data.message || "All ontologies have been ingested."}</p>
            </div>
          `;
        } else {
          resultsDiv.innerHTML = `
            <div class="alert alert-danger">
              <h4>✗ Ingestion Failed</h4>
              <p>${data.message || "An error occurred during ingestion."}</p>
            </div>
          `;
        }
      })
      .catch(error => {
        document.getElementById("ingestion-status").style.display = "none";
        document.getElementById("ingestion-results").innerHTML = `
          <div class="alert alert-danger">
            <h4>✗ Error</h4>
            <p>Failed to connect to the ingestion service.</p>
          </div>
        `;
      });
    }
    </script>
    ';
    
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
        ],
      ],
    ];
  }

  /**
   * Ingest INS instruments.
   */
  public function ingestInstruments() {
    $output = '';
    
    $output .= '<div class="container mt-4">';
    $output .= '<h1>Ingest INS Instruments</h1>';
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
    $output .= '<button class="btn btn-primary btn-lg" onclick="startIngestion()">Start Ingestion</button>';
    $output .= '<a href="/pmsr" class="btn btn-secondary btn-lg ms-2">Cancel</a>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    // Add JavaScript for ingestion
    $output .= '
    <script>
    function startIngestion() {
      document.getElementById("ingestion-status").style.display = "block";
      document.getElementById("status-message").textContent = "Ingesting instruments...";
      
      fetch("/hascoapi/api/pmsr/ingest/instruments", {
        method: "POST"
      })
      .then(response => response.json())
      .then(data => {
        document.getElementById("ingestion-status").style.display = "none";
        
        const resultsDiv = document.getElementById("ingestion-results");
        resultsDiv.style.display = "block";
        
        if (data.isSuccessful) {
          resultsDiv.innerHTML = `
            <div class="alert alert-success">
              <h4>✓ Ingestion Completed Successfully</h4>
              <p>${data.message || "All instruments have been ingested."}</p>
            </div>
          `;
        } else {
          resultsDiv.innerHTML = `
            <div class="alert alert-danger">
              <h4>✗ Ingestion Failed</h4>
              <p>${data.message || "An error occurred during ingestion."}</p>
            </div>
          `;
        }
      })
      .catch(error => {
        document.getElementById("ingestion-status").style.display = "none";
        document.getElementById("ingestion-results").innerHTML = `
          <div class="alert alert-danger">
            <h4>✗ Error</h4>
            <p>Failed to connect to the ingestion service.</p>
          </div>
        `;
      });
    }
    </script>
    ';
    
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
        ],
      ],
    ];
  }

  /**
   * Ingest KRG geography and organizations.
   */
  public function ingestGeography() {
    $output = '';
    
    $output .= '<div class="container mt-4">';
    $output .= '<h1>Ingest KRG Geography and Organizations</h1>';
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
    $output .= '<button class="btn btn-primary btn-lg" onclick="startIngestion()">Start Ingestion</button>';
    $output .= '<a href="/pmsr" class="btn btn-secondary btn-lg ms-2">Cancel</a>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    // Add JavaScript for ingestion
    $output .= '
    <script>
    function startIngestion() {
      document.getElementById("ingestion-status").style.display = "block";
      document.getElementById("status-message").textContent = "Ingesting geography data...";
      
      fetch("/hascoapi/api/pmsr/ingest/geography", {
        method: "POST"
      })
      .then(response => response.json())
      .then(data => {
        document.getElementById("ingestion-status").style.display = "none";
        
        const resultsDiv = document.getElementById("ingestion-results");
        resultsDiv.style.display = "block";
        
        if (data.isSuccessful) {
          resultsDiv.innerHTML = `
            <div class="alert alert-success">
              <h4>✓ Ingestion Completed Successfully</h4>
              <p>${data.message || "Geography and organizations have been ingested."}</p>
            </div>
          `;
        } else {
          resultsDiv.innerHTML = `
            <div class="alert alert-danger">
              <h4>✗ Ingestion Failed</h4>
              <p>${data.message || "An error occurred during ingestion."}</p>
            </div>
          `;
        }
      })
      .catch(error => {
        document.getElementById("ingestion-status").style.display = "none";
        document.getElementById("ingestion-results").innerHTML = `
          <div class="alert alert-danger">
            <h4>✗ Error</h4>
            <p>Failed to connect to the ingestion service.</p>
          </div>
        `;
      });
    }
    </script>
    ';
    
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
        ],
      ],
    ];
  }

  /**
   * Ingest KGR people.
   */
  public function ingestPeople() {
    $output = '';
    
    $output .= '<div class="container mt-4">';
    $output .= '<h1>Ingest KGR People</h1>';
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
    $output .= '<button class="btn btn-primary btn-lg" onclick="startIngestion()">Start Ingestion</button>';
    $output .= '<a href="/pmsr" class="btn btn-secondary btn-lg ms-2">Cancel</a>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-status" class="mt-4" style="display:none;">';
    $output .= '<div class="alert alert-info">';
    $output .= '<div class="spinner-border text-primary me-2" role="status"></div>';
    $output .= '<span id="status-message">Processing...</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    $output .= '<div id="ingestion-results" class="mt-4" style="display:none;"></div>';
    
    $output .= '</div>'; // End container
    
    // Add JavaScript for ingestion
    $output .= '
    <script>
    function startIngestion() {
      document.getElementById("ingestion-status").style.display = "block";
      document.getElementById("status-message").textContent = "Ingesting people data...";
      
      fetch("/hascoapi/api/pmsr/ingest/people", {
        method: "POST"
      })
      .then(response => response.json())
      .then(data => {
        document.getElementById("ingestion-status").style.display = "none";
        
        const resultsDiv = document.getElementById("ingestion-results");
        resultsDiv.style.display = "block";
        
        if (data.isSuccessful) {
          resultsDiv.innerHTML = `
            <div class="alert alert-success">
              <h4>✓ Ingestion Completed Successfully</h4>
              <p>${data.message || "People data has been ingested."}</p>
            </div>
          `;
        } else {
          resultsDiv.innerHTML = `
            <div class="alert alert-danger">
              <h4>✗ Ingestion Failed</h4>
              <p>${data.message || "An error occurred during ingestion."}</p>
            </div>
          `;
        }
      })
      .catch(error => {
        document.getElementById("ingestion-status").style.display = "none";
        document.getElementById("ingestion-results").innerHTML = `
          <div class="alert alert-danger">
            <h4>✗ Error</h4>
            <p>Failed to connect to the ingestion service.</p>
          </div>
        `;
      });
    }
    </script>
    ';
    
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
        ],
      ],
    ];
  }

}
