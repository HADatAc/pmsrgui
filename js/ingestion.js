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
            
            // Disable the button during ingestion
            $(this).prop('disabled', true).addClass('disabled');
            
            document.getElementById("ingestion-status").style.display = "block";
            document.getElementById("status-message").textContent = message;
            
            // Use the Drupal AJAX endpoint for ontologies, backend endpoint for others
            const actualEndpoint = isOntologyIngestion ? 
              '/pmsr/api/ingest/ontologies/process' : endpoint;
            
            fetch(actualEndpoint, {
              method: "POST",
              credentials: "same-origin",
              headers: {
                "Content-Type": "application/json"
              }
            })
            .then(response => response.json())
            .then(data => {
              document.getElementById("ingestion-status").style.display = "none";
              
              const resultsDiv = document.getElementById("ingestion-results");
              resultsDiv.style.display = "block";
              
              // Re-enable the button after completion
              $('.btn-start-ingestion').prop('disabled', false).removeClass('disabled');
              
              // Handle ontology ingestion response (has progress array)
              if (isOntologyIngestion) {
                if (data.success) {
                  let progressHTML = '<div class="alert alert-success alert-dismissable fade show" role="alert">' +
                    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                    '<span aria-hidden="true">&times;</span></button>' +
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
                  
                  // Add manual close handler for dynamically created alert
                  $(resultsDiv).find('.alert .close').on('click', function() {
                    $(this).closest('.alert').fadeOut(300, function() {
                      $(this).remove();
                    });
                  });
                } else {
                  let errorHTML = '<div class="alert alert-danger alert-dismissable fade show" role="alert">' +
                    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                    '<span aria-hidden="true">&times;</span></button>' +
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
                  
                  // Add manual close handler for dynamically created alert
                  $(resultsDiv).find('.alert .close').on('click', function() {
                    $(this).closest('.alert').fadeOut(300, function() {
                      $(this).remove();
                    });
                  });
                }
              } else {
                // Handle standard hascoapi backend response
                if (data.isSuccessful) {
                  resultsDiv.innerHTML = 
                    '<div class="alert alert-success alert-dismissable fade show" role="alert">' +
                    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                    '<span aria-hidden="true">&times;</span></button>' +
                    '<h4>✓ Ingestion Completed Successfully</h4>' +
                    '<p>' + (data.message || "Ingestion completed successfully.") + '</p>' +
                    '</div>';
                  
                  // Add manual close handler for dynamically created alert
                  $(resultsDiv).find('.alert .close').on('click', function() {
                    $(this).closest('.alert').fadeOut(300, function() {
                      $(this).remove();
                    });
                  });
                } else {
                  resultsDiv.innerHTML = 
                    '<div class="alert alert-danger alert-dismissable fade show" role="alert">' +
                    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                    '<span aria-hidden="true">&times;</span></button>' +
                    '<h4>✗ Ingestion Failed</h4>' +
                    '<p>' + (data.message || "An error occurred during ingestion.") + '</p>' +
                    '</div>';
                  
                  // Add manual close handler for dynamically created alert
                  $(resultsDiv).find('.alert .close').on('click', function() {
                    $(this).closest('.alert').fadeOut(300, function() {
                      $(this).remove();
                    });
                  });
                }
              }
              
              // Scroll to results
              resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(error => {
              document.getElementById("ingestion-status").style.display = "none";
              
              // Re-enable the button after error
              $('.btn-start-ingestion').prop('disabled', false).removeClass('disabled');
              
              const resultsDiv = document.getElementById("ingestion-results");
              resultsDiv.style.display = "block";
              resultsDiv.innerHTML = 
                '<div class="alert alert-danger alert-dismissable fade show" role="alert">' +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span></button>' +
                '<h4>✗ Error</h4>' +
                '<p>Failed to connect to the ingestion service: ' + error.message + '</p>' +
                '</div>';
              
              // Add manual close handler for dynamically created alert
              $(resultsDiv).find('.alert .close').on('click', function() {
                $(this).closest('.alert').fadeOut(300, function() {
                  $(this).remove();
                });
              });
              
              // Scroll to results
              resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
          });
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
