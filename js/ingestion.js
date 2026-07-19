/**
 * @file
 * JavaScript for PMSR data ingestion pages.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Start the ingestion process.
   */
  Drupal.behaviors.pmsrIngestion = {
    attach: function (context, settings) {
      // Attach click handler to Start Ingestion button
      $('.btn-start-ingestion', context).each(function() {
        if (!$(this).data('pmsr-ingestion-processed')) {
          $(this).data('pmsr-ingestion-processed', true);
          $(this).on('click', function(e) {
            e.preventDefault();
            
            const endpoint = drupalSettings.pmsr.ingestion.endpoint;
            const message = drupalSettings.pmsr.ingestion.message;
            
            // Check if this is the ontology ingestion (special handling)
            const isOntologyIngestion = window.location.pathname.includes('/pmsr/ingest/ontologies');
            
            document.getElementById("ingestion-status").style.display = "block";
            document.getElementById("status-message").textContent = message;
            
            // Use the Drupal AJAX endpoint for ontologies, backend endpoint for others
            const actualEndpoint = isOntologyIngestion ? 
              '/pmsr/api/ingest/ontologies/process' : endpoint;
            
            fetch(actualEndpoint, {
              method: "POST"
            })
            .then(response => response.json())
            .then(data => {
              document.getElementById("ingestion-status").style.display = "none";
              
              const resultsDiv = document.getElementById("ingestion-results");
              resultsDiv.style.display = "block";
              
              // Handle ontology ingestion response (has progress array)
              if (isOntologyIngestion) {
                if (data.success) {
                  let progressHTML = '<div class="alert alert-success">' +
                    '<h4>✓ Ingestion Completed Successfully</h4>' +
                    '<p>' + (data.message || "All ontologies ingested successfully.") + '</p>';
                  
                  if (data.progress && data.progress.length > 0) {
                    progressHTML += '<div class="mt-3"><strong>Ingestion Progress:</strong><ul class="list-unstyled mt-2">';
                    data.progress.forEach(function(step) {
                      progressHTML += '<li>' + step + '</li>';
                    });
                    progressHTML += '</ul></div>';
                  }
                  
                  progressHTML += '</div>';
                  resultsDiv.innerHTML = progressHTML;
                } else {
                  let errorHTML = '<div class="alert alert-danger">' +
                    '<h4>✗ Ingestion Failed</h4>' +
                    '<p>' + (data.message || "An error occurred during ingestion.") + '</p>';
                  
                  if (data.errors && data.errors.length > 0) {
                    errorHTML += '<div class="mt-3"><strong>Errors:</strong><ul>';
                    data.errors.forEach(function(error) {
                      errorHTML += '<li>' + error + '</li>';
                    });
                    errorHTML += '</ul></div>';
                  }
                  
                  if (data.progress && data.progress.length > 0) {
                    errorHTML += '<div class="mt-3"><strong>Progress before error:</strong><ul class="list-unstyled mt-2">';
                    data.progress.forEach(function(step) {
                      errorHTML += '<li>' + step + '</li>';
                    });
                    errorHTML += '</ul></div>';
                  }
                  
                  errorHTML += '</div>';
                  resultsDiv.innerHTML = errorHTML;
                }
              } else {
                // Handle standard hascoapi backend response
                if (data.isSuccessful) {
                  resultsDiv.innerHTML = 
                    '<div class="alert alert-success">' +
                    '<h4>✓ Ingestion Completed Successfully</h4>' +
                    '<p>' + (data.message || "Ingestion completed successfully.") + '</p>' +
                    '</div>';
                } else {
                  resultsDiv.innerHTML = 
                    '<div class="alert alert-danger">' +
                    '<h4>✗ Ingestion Failed</h4>' +
                    '<p>' + (data.message || "An error occurred during ingestion.") + '</p>' +
                    '</div>';
                }
              }
            })
            .catch(error => {
              document.getElementById("ingestion-status").style.display = "none";
              document.getElementById("ingestion-results").innerHTML = 
                '<div class="alert alert-danger">' +
                '<h4>✗ Error</h4>' +
                '<p>Failed to connect to the ingestion service: ' + error.message + '</p>' +
                '</div>';
            });
          });
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
