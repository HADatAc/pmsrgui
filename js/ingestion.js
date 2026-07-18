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
            
            document.getElementById("ingestion-status").style.display = "block";
            document.getElementById("status-message").textContent = message;
            
            fetch(endpoint, {
              method: "POST"
            })
            .then(response => response.json())
            .then(data => {
              document.getElementById("ingestion-status").style.display = "none";
              
              const resultsDiv = document.getElementById("ingestion-results");
              resultsDiv.style.display = "block";
              
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
            })
            .catch(error => {
              document.getElementById("ingestion-status").style.display = "none";
              document.getElementById("ingestion-results").innerHTML = 
                '<div class="alert alert-danger">' +
                '<h4>✗ Error</h4>' +
                '<p>Failed to connect to the ingestion service.</p>' +
                '</div>';
            });
          });
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
