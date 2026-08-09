/**
 * @file
 * JavaScript for PMSR data ingestion pages.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  const INS_STEP_TITLES = [
    'Locate INS-PMSR-V3.xlsx file',
    'Create Drupal file entity',
    'Generate URIs',
    'Check existing INS instance data',
    'Check existing INS templates',
    'Create DataFile and INS entities',
    'Upload file content',
    'Trigger ingestion',
    'Verify ingestion status',
    'Invalidate statistics cache'
  ];

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderINSLiveProgressShell(resultsDiv) {
    let cards = '';
    INS_STEP_TITLES.forEach(function (title, idx) {
      const step = idx + 1;
      cards +=
        '<div class="ins-step-card ins-step-pending" data-ins-step="' + step + '">' +
          '<div class="ins-step-header">' +
            '<span class="ins-step-index">Step ' + step + '/10</span>' +
            '<span class="ins-step-badge">Pending</span>' +
          '</div>' +
          '<div class="ins-step-title">' + escapeHtml(title) + '</div>' +
          '<div class="ins-step-detail" data-ins-step-detail="' + step + '"></div>' +
        '</div>';
    });

    resultsDiv.innerHTML =
      '<div class="ins-live-progress">' +
        '<div id="ins-live-summary" class="alert alert-info mb-3">Initializing INS ingestion...</div>' +
        '<div class="ins-step-grid">' + cards + '</div>' +
        '<div class="mt-3">' +
          '<strong>Live log</strong>' +
          '<ul id="ins-live-log" class="list-unstyled mt-2"></ul>' +
        '</div>' +
      '</div>';
  }

  function parseStepStates(progressLines, currentStep, overallStatus) {
    const states = {};
    let stepCursor = 0;

    for (let i = 1; i <= 10; i += 1) {
      states[i] = {
        status: 'pending',
        detail: ''
      };
    }

    if (typeof currentStep === 'number' && currentStep > 0) {
      for (let s = 1; s < currentStep && s <= 10; s += 1) {
        states[s].status = 'success';
      }
      if (currentStep <= 10 && overallStatus === 'RUNNING') {
        states[currentStep].status = 'running';
      }
    }

    (progressLines || []).forEach(function (line) {
      const text = String(line || '');
      const stepMatch = text.match(/^\[(\d+)\/10\]/);
      if (stepMatch) {
        stepCursor = parseInt(stepMatch[1], 10);
        if (stepCursor >= 1 && stepCursor <= 10 && states[stepCursor].status === 'pending') {
          states[stepCursor].status = 'running';
        }
        return;
      }

      if (stepCursor < 1 || stepCursor > 10) {
        return;
      }

      if (text.indexOf('✗') !== -1) {
        states[stepCursor].status = 'failed';
        states[stepCursor].detail = text;
      } else if (text.indexOf('✓') !== -1) {
        if (states[stepCursor].status !== 'failed') {
          states[stepCursor].status = 'success';
          states[stepCursor].detail = text;
        }
      } else if (states[stepCursor].detail === '') {
        states[stepCursor].detail = text;
      }
    });

    if (overallStatus === 'SUCCESS') {
      for (let i = 1; i <= 10; i += 1) {
        if (states[i].status !== 'failed') {
          states[i].status = 'success';
        }
      }
    }

    if (overallStatus === 'FAILED') {
      let failedStep = (typeof currentStep === 'number' && currentStep >= 1 && currentStep <= 10) ? currentStep : 10;
      for (let i = 1; i <= 10; i += 1) {
        if (states[i].status === 'failed') {
          failedStep = i;
          break;
        }
      }
      states[failedStep].status = 'failed';
    }

    return states;
  }

  function updateINSLiveProgressUI(resultsDiv, statusPayload) {
    const status = String(statusPayload.status || 'UNKNOWN').toUpperCase();
    const progress = Array.isArray(statusPayload.progress) ? statusPayload.progress : [];
    const errors = Array.isArray(statusPayload.errors) ? statusPayload.errors : [];
    const currentStep = Number(statusPayload.currentStep || 0);
    const states = parseStepStates(progress, currentStep, status);

    Object.keys(states).forEach(function (k) {
      const step = Number(k);
      const card = resultsDiv.querySelector('[data-ins-step="' + step + '"]');
      if (!card) {
        return;
      }
      const state = states[step];
      const badge = card.querySelector('.ins-step-badge');
      const detail = card.querySelector('[data-ins-step-detail="' + step + '"]');

      card.classList.remove('ins-step-pending', 'ins-step-running', 'ins-step-success', 'ins-step-failed');
      card.classList.add('ins-step-' + state.status);

      if (badge) {
        badge.textContent = state.status.charAt(0).toUpperCase() + state.status.slice(1);
      }

      if (detail) {
        detail.textContent = state.detail || '';
      }
    });

    const summary = resultsDiv.querySelector('#ins-live-summary');
    if (summary) {
      summary.classList.remove('alert-info', 'alert-success', 'alert-danger', 'alert-warning');
      if (status === 'SUCCESS') {
        summary.classList.add('alert-success');
        summary.textContent = statusPayload.message || 'INS ingestion completed successfully.';
      } else if (status === 'FAILED') {
        summary.classList.add('alert-danger');
        summary.textContent = statusPayload.message || 'INS ingestion failed.';
      } else if (status === 'RUNNING') {
        summary.classList.add('alert-info');
        summary.textContent = statusPayload.message || 'INS ingestion is running...';
      } else {
        summary.classList.add('alert-warning');
        summary.textContent = statusPayload.message || 'INS ingestion status is unknown.';
      }
    }

    const log = resultsDiv.querySelector('#ins-live-log');
    if (log) {
      log.innerHTML = '';
      progress.slice(-40).forEach(function (line) {
        const li = document.createElement('li');
        li.textContent = line;
        log.appendChild(li);
      });
      errors.slice(-5).forEach(function (line) {
        const li = document.createElement('li');
        li.className = 'text-danger';
        li.textContent = line;
        log.appendChild(li);
      });
    }
  }

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
            const startEndpoint = drupalSettings.pmsr.ingestion.startEndpoint;
            const statusEndpoint = drupalSettings.pmsr.ingestion.statusEndpoint;
            const message = drupalSettings.pmsr.ingestion.message;
            
            // Check if this is a Drupal-based ingestion (ontology, INS, Geography, or People)
            const isOntologyIngestion = window.location.pathname.includes('/pmsr/ingest/ontologies');
            const isINSIngestion = window.location.pathname.includes('/pmsr/ingest/instruments');
            const isGeographyIngestion = window.location.pathname.includes('/pmsr/ingest/geography');
            const isPeopleIngestion = window.location.pathname.includes('/pmsr/ingest/people');
            const isAuxiliaryIngestion = window.location.pathname.includes('/pmsr/ingest/auxiliary-data');
            const isDrupalIngestion = isOntologyIngestion || isINSIngestion || isGeographyIngestion || isPeopleIngestion || isAuxiliaryIngestion;

            if (isINSIngestion && startEndpoint && statusEndpoint) {
              const btn = this;
              const resultsDiv = document.getElementById('ingestion-results');
              const statusDiv = document.getElementById('ingestion-status');
              const statusMessage = document.getElementById('status-message');

              $(btn).prop('disabled', true).addClass('disabled');
              statusDiv.style.display = 'block';
              statusMessage.textContent = message;
              resultsDiv.style.display = 'block';
              renderINSLiveProgressShell(resultsDiv);

              fetch(startEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/json'
                },
                body: JSON.stringify({})
              })
              .then(function (startRes) { return startRes.json(); })
              .then(function (startData) {
                if (!startData || !startData.success || !startData.jobId) {
                  throw new Error((startData && startData.message) ? startData.message : 'Could not start INS ingestion job.');
                }

                const jobId = startData.jobId;
                let pollingStopped = false;

                const stopPolling = function () {
                  pollingStopped = true;
                };

                const poll = function () {
                  if (pollingStopped) {
                    return;
                  }
                  fetch(statusEndpoint + '/' + encodeURIComponent(jobId), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                      'Content-Type': 'application/json'
                    }
                  })
                  .then(function (res) { return res.json(); })
                  .then(function (statusData) {
                    updateINSLiveProgressUI(resultsDiv, statusData || {});

                    const state = String((statusData && statusData.status) || 'UNKNOWN').toUpperCase();
                    if (state === 'SUCCESS' || state === 'FAILED') {
                      stopPolling();
                      statusDiv.style.display = 'none';
                      $('.btn-start-ingestion').prop('disabled', false).removeClass('disabled');
                      resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                      return;
                    }

                    setTimeout(poll, 2000);
                  })
                  .catch(function () {
                    setTimeout(poll, 2500);
                  });
                };

                poll();

                return fetch(endpoint, {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {
                    'Content-Type': 'application/json'
                  },
                  body: JSON.stringify({ jobId: jobId })
                })
                .then(function (res) { return res.json(); })
                .then(function (processData) {
                  updateINSLiveProgressUI(resultsDiv, processData || {});
                })
                .catch(function (err) {
                  const summary = resultsDiv.querySelector('#ins-live-summary');
                  if (summary) {
                    summary.classList.remove('alert-info');
                    summary.classList.add('alert-danger');
                    summary.textContent = 'INS ingestion request failed: ' + err.message;
                  }
                  stopPolling();
                  statusDiv.style.display = 'none';
                  $('.btn-start-ingestion').prop('disabled', false).removeClass('disabled');
                });
              })
              .catch(function (error) {
                statusDiv.style.display = 'none';
                $('.btn-start-ingestion').prop('disabled', false).removeClass('disabled');
                resultsDiv.innerHTML =
                  '<div class="alert alert-danger alert-dismissable fade show" role="alert">' +
                  '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                  '<span aria-hidden="true">&times;</span></button>' +
                  '<h4>✗ Error</h4>' +
                  '<p>Failed to start live INS ingestion: ' + error.message + '</p>' +
                  '</div>';
              });

              return;
            }
            
            // Prepare request body with token
            let requestBody = null;
            if (isGeographyIngestion) {
              const clearExistingImages = document.getElementById('clearExistingImages');
              requestBody = JSON.stringify({
                clearExistingImages: clearExistingImages ? clearExistingImages.checked : false,
                token: drupalSettings.pmsr.ingestion.token || null
              });
            } else if (isPeopleIngestion) {
              // People ingestion needs token but no other parameters
              requestBody = JSON.stringify({
                token: drupalSettings.pmsr.ingestion.token || null
              });
            }
            
            // Disable the button during ingestion
            $(this).prop('disabled', true).addClass('disabled');
            
            document.getElementById("ingestion-status").style.display = "block";
            document.getElementById("status-message").textContent = message;
            
            // Use the Drupal AJAX endpoint for ontologies/INS/Geography, backend endpoint for others
            const actualEndpoint = isDrupalIngestion ? endpoint : endpoint;
            
            fetch(actualEndpoint, {
              method: "POST",
              credentials: "same-origin",
              headers: {
                "Content-Type": "application/json"
              },
              body: requestBody
            })
            .then(response => response.json())
            .then(data => {
              document.getElementById("ingestion-status").style.display = "none";
              
              const resultsDiv = document.getElementById("ingestion-results");
              resultsDiv.style.display = "block";
              
              // Re-enable the button after completion
              $('.btn-start-ingestion').prop('disabled', false).removeClass('disabled');
              
              // Handle Drupal-based ingestion response (ontologies and INS - both have progress array)
              if (isDrupalIngestion) {
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
                const isSuccessful = data && (data.isSuccessful === true || data.success === true);
                if (isSuccessful) {
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
      
      // Attach click handler to Start Uningestion button
      $('.btn-start-uningest', context).each(function() {
        if (!$(this).data('pmsr-uningest-processed')) {
          $(this).data('pmsr-uningest-processed', true);
          $(this).on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to uningest the INS template? This will delete all INS-created instrument classes.')) {
              return;
            }
            
            const endpoint = drupalSettings.pmsr.uningest.endpoint;
            const message = drupalSettings.pmsr.uningest.message;
            
            // Disable the button during uningest
            $(this).prop('disabled', true).addClass('disabled');
            
            document.getElementById("ingestion-status").style.display = "block";
            document.getElementById("status-message").textContent = message;
            
            fetch(endpoint, {
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
              $('.btn-start-uningest').prop('disabled', false).removeClass('disabled');
              
              if (data.success) {
                let progressHTML = '<div class="alert alert-success alert-dismissable fade show" role="alert">' +
                  '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                  '<span aria-hidden="true">&times;</span></button>' +
                  '<h4>✓ Uningestion Completed Successfully</h4>' +
                  '<p>' + (data.message || "INS template uningested successfully.") + '</p>';
                
                if (data.progress && data.progress.length > 0) {
                  progressHTML += '<div class="mt-3"><strong>Uningestion Progress:</strong><ul class="list-unstyled mt-2">';
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
                  '<h4>✗ Uningestion Failed</h4>' +
                  '<p>' + (data.message || "An error occurred during uningestion.") + '</p>';
                
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
              
              // Scroll to results
              resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(error => {
              document.getElementById("ingestion-status").style.display = "none";
              
              // Re-enable the button after error
              $('.btn-start-uningest').prop('disabled', false).removeClass('disabled');
              
              const resultsDiv = document.getElementById("ingestion-results");
              resultsDiv.style.display = "block";
              resultsDiv.innerHTML = 
                '<div class="alert alert-danger alert-dismissable fade show" role="alert">' +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span></button>' +
                '<h4>✗ Error</h4>' +
                '<p>Failed to connect to the uningest service: ' + error.message + '</p>' +
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

      // Attach click handler to Sync Users/Person button
      $('.btn-sync-users-person', context).each(function() {
        if (!$(this).data('pmsr-sync-users-person-processed')) {
          $(this).data('pmsr-sync-users-person-processed', true);
          $(this).on('click', function(e) {
            e.preventDefault();

            const endpoint = drupalSettings.pmsr.syncUsersPerson.endpoint;
            const message = drupalSettings.pmsr.syncUsersPerson.message;
            const token = drupalSettings.pmsr.syncUsersPerson.token || null;

            $(this).prop('disabled', true).addClass('disabled');

            document.getElementById('ingestion-status').style.display = 'block';
            document.getElementById('status-message').textContent = message;

            fetch(endpoint, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({ token: token })
            })
            .then(response => response.json())
            .then(data => {
              document.getElementById('ingestion-status').style.display = 'none';
              $('.btn-sync-users-person').prop('disabled', false).removeClass('disabled');

              const resultsDiv = document.getElementById('ingestion-results');
              resultsDiv.style.display = 'block';

              const isSuccess = data.success === true;
              const alertClass = isSuccess ? 'alert-success' : 'alert-danger';
              const icon = isSuccess ? '✓' : '✗';
              const title = isSuccess ? 'Sync Completed' : 'Sync Completed With Errors';

              let html = '<div class="alert ' + alertClass + ' alert-dismissable fade show" role="alert">' +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span></button>' +
                '<h4>' + icon + ' ' + title + '</h4>' +
                '<p>' + (data.message || '') + '</p>';

              if (typeof data.updates_count !== 'undefined') {
                html += '<p><strong>Updates made:</strong> ' + data.updates_count + '</p>';
              }

              if (Array.isArray(data.updated_people) && data.updated_people.length > 0) {
                html += '<div class="mt-2"><strong>Updated KGR persons:</strong><ul>';
                data.updated_people.forEach(function(item) {
                  const label = item.label || item.uri || '(unknown person)';
                  const uri = item.uri || '';
                  html += '<li>' + label + (uri ? ' [' + uri + ']' : '') + '</li>';
                });
                html += '</ul></div>';
              }

              if (Array.isArray(data.errors) && data.errors.length > 0) {
                html += '<div class="mt-2"><strong>Errors:</strong><ul>';
                data.errors.forEach(function(err) {
                  html += '<li>' + err + '</li>';
                });
                html += '</ul></div>';
              }

              html += '</div>';
              resultsDiv.innerHTML = html;

              $(resultsDiv).find('.alert .close').on('click', function() {
                $(this).closest('.alert').fadeOut(300, function() {
                  $(this).remove();
                });
              });

              resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(error => {
              document.getElementById('ingestion-status').style.display = 'none';
              $('.btn-sync-users-person').prop('disabled', false).removeClass('disabled');

              const resultsDiv = document.getElementById('ingestion-results');
              resultsDiv.style.display = 'block';
              resultsDiv.innerHTML =
                '<div class="alert alert-danger alert-dismissable fade show" role="alert">' +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span></button>' +
                '<h4>✗ Error</h4>' +
                '<p>Failed to connect to Sync Users/Person service: ' + error.message + '</p>' +
                '</div>';

              $(resultsDiv).find('.alert .close').on('click', function() {
                $(this).closest('.alert').fadeOut(300, function() {
                  $(this).remove();
                });
              });

              resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
          });
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
