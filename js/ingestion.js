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

  function fetchWithTimeout(url, options, timeoutMs) {
    const controller = new AbortController();
    const timerId = setTimeout(function () {
      controller.abort();
    }, timeoutMs);

    const requestOptions = Object.assign({}, options || {}, {
      signal: controller.signal
    });

    return fetch(url, requestOptions).finally(function () {
      clearTimeout(timerId);
    });
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

  function normalizeWKFCardClass(status) {
    const s = String(status || '').toUpperCase();
    if (s === 'PROCESSED' || s === 'INGESTED') {
      return 'wkf-card-processed';
    }
    if (s === 'WORKING') {
      return 'wkf-card-working';
    }
    if (s === 'PENDING' || s === 'UNINGESTED') {
      return 'wkf-card-pending';
    }
    return 'wkf-card-warning';
  }

  function renderWKFCards(cards) {
    const grid = document.getElementById('wkf-cards-grid');
    if (!grid) {
      return;
    }

    const data = Array.isArray(cards) ? cards : [];
    let html = '';
    data.forEach(function (card) {
      const filename = card && card.filename ? card.filename : 'WKF file';
      const status = String((card && card.status) || 'PENDING').toUpperCase();
      const detail = card && card.detail ? card.detail : '';
      const cls = normalizeWKFCardClass(status);

      html +=
        '<div class="wkf-card ' + cls + '" data-wkf-file="' + escapeHtml(filename) + '">' +
          '<div class="wkf-card-title"><strong>' + escapeHtml(filename) + '</strong></div>' +
          '<div class="wkf-card-status">' + escapeHtml(status) + '</div>' +
          '<div class="wkf-card-detail text-muted small">' + escapeHtml(detail) + '</div>' +
        '</div>';
    });

    grid.innerHTML = html;
  }

  function renderWKFSummary(payload) {
    const summary = document.getElementById('wkf-ingestion-summary');
    if (!summary) {
      return;
    }

    const cards = Array.isArray(payload.cards) ? payload.cards : [];
    let processed = 0;
    cards.forEach(function (card) {
      const status = String((card && card.status) || '').toUpperCase();
      if (status === 'PROCESSED' || status === 'INGESTED') {
        processed += 1;
      }
    });

    const total = cards.length;
    const status = String(payload.status || '').toUpperCase();
    const msg = payload.message || '';
    const alertClass = status === 'SUCCESS' ? 'alert-success' : (status === 'FAILED' ? 'alert-warning' : 'alert-info');

    summary.innerHTML =
      '<div class="alert ' + alertClass + '">' +
      '<strong>' + escapeHtml(msg) + '</strong><br>' +
      'Processed: ' + processed + ' / ' + total +
      '</div>';
  }

  let wkfPollingTimer = null;
  let wkfPollingJobId = null;

  function stopWKFPolling() {
    if (wkfPollingTimer) {
      clearTimeout(wkfPollingTimer);
      wkfPollingTimer = null;
    }
    wkfPollingJobId = null;
  }

  function setWKFBusy(isBusy, message) {
    const statusDiv = document.getElementById('ingestion-status');
    const statusMessage = document.getElementById('status-message');
    if (statusDiv) {
      statusDiv.style.display = isBusy ? 'block' : 'none';
    }
    if (statusMessage && message) {
      statusMessage.textContent = message;
    }

    if (isBusy) {
      $('.btn-start-ingestion').prop('disabled', true).addClass('disabled');
    } else {
      $('.btn-start-ingestion').prop('disabled', false).removeClass('disabled');
    }
  }

  function pollWKFJob(settingsObj, jobId) {
    const statusEndpoint = settingsObj.statusEndpoint;
    if (!statusEndpoint || !jobId) {
      setWKFBusy(false);
      return;
    }

    if (wkfPollingJobId && wkfPollingJobId !== jobId) {
      stopWKFPolling();
    }
    wkfPollingJobId = jobId;

    const tick = function () {
      fetch(statusEndpoint + '/' + encodeURIComponent(jobId), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' }
      })
      .then(function (res) { return res.json(); })
      .then(function (statusData) {
        renderWKFCards(statusData.cards || []);
        renderWKFSummary(statusData || {});

        const state = String((statusData && statusData.status) || '').toUpperCase();
        if (state === 'SUCCESS' || state === 'FAILED') {
          stopWKFPolling();
          setWKFBusy(false);
          return;
        }

        wkfPollingTimer = setTimeout(tick, 2000);
      })
      .catch(function () {
        wkfPollingTimer = setTimeout(tick, 2500);
      });
    };

    tick();
  }

  function requestWKFProcess(settingsObj, jobId) {
    const processEndpoint = settingsObj.processEndpoint;
    if (!processEndpoint || !jobId) {
      return Promise.resolve(null);
    }

    return fetch(processEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ jobId: jobId })
    })
    .then(function (res) { return res.json(); })
    .then(function (processData) {
      if (processData) {
        renderWKFCards(processData.cards || []);
        renderWKFSummary(processData || {});
      }
      return processData;
    })
    .catch(function () {
      return null;
    });
  }

  function renderWKFCachedView(settingsObj, message) {
    const cachedCards = Array.isArray(settingsObj.cachedCards) ? settingsObj.cachedCards : [];
    renderWKFCards(cachedCards);
    renderWKFSummary({
      status: 'RUNNING',
      message: message || 'Loaded WKF status from cache.',
      cards: cachedCards
    });
    setWKFBusy(false);
  }

  function resumeWKFScenariosIngestion(settingsObj, jobId, message) {
    const statusEndpoint = settingsObj.statusEndpoint;
    if (!statusEndpoint || !jobId) {
      renderWKFCachedView(settingsObj, 'No active WKF ingestion job. Showing cached WKF state.');
      return;
    }

    setWKFBusy(true, message || settingsObj.message || 'Ingesting WKF scenarios...');
    settingsObj.activeJobId = jobId;

    fetch(statusEndpoint + '/' + encodeURIComponent(jobId), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }
    })
    .then(function (res) { return res.json(); })
    .then(function (statusData) {
      renderWKFCards(statusData.cards || []);
      renderWKFSummary(statusData || {});

      const state = String((statusData && statusData.status) || '').toUpperCase();
      if (state === 'SUCCESS' || state === 'FAILED') {
        setWKFBusy(false);
        return;
      }

      pollWKFJob(settingsObj, jobId);
      return requestWKFProcess(settingsObj, jobId);
    })
    .catch(function (error) {
      setWKFBusy(false);
      const summary = document.getElementById('wkf-ingestion-summary');
      if (summary) {
        summary.innerHTML = '<div class="alert alert-danger">Could not resume WKF ingestion: ' + escapeHtml(error.message) + '</div>';
      }
    });
  }

  function runWKFScenariosIngestionFlow(btn, settingsObj, fromScratch, organizationUri) {
    const startEndpoint = settingsObj.startEndpoint;
    const statusEndpoint = settingsObj.statusEndpoint;
    const processEndpoint = settingsObj.processEndpoint;
    const resultsDiv = document.getElementById('ingestion-results');

    if (!startEndpoint || !statusEndpoint || !processEndpoint) {
      if (resultsDiv) {
        resultsDiv.style.display = 'block';
        resultsDiv.innerHTML = '<div class="alert alert-danger">Missing WKF ingestion endpoint configuration.</div>';
      }
      return;
    }

    setWKFBusy(true, settingsObj.message || 'Ingesting WKF scenarios...');

    fetch(startEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        fromScratch: !!fromScratch,
        organizationUri: String(organizationUri || '').trim()
      })
    })
    .then(function (res) { return res.json(); })
    .then(function (startData) {
      if (!startData || !startData.success || !startData.jobId) {
        throw new Error((startData && startData.message) ? startData.message : 'Could not start WKF scenarios ingestion job.');
      }

      const jobId = startData.jobId;
      settingsObj.activeJobId = jobId;
      settingsObj.selectedOrganizationUri = String((startData && startData.organizationUri) || organizationUri || '');
      if (Array.isArray(startData.cards)) {
        renderWKFCards(startData.cards);
        settingsObj.cachedCards = startData.cards;
      }

      pollWKFJob(settingsObj, jobId);
      return requestWKFProcess(settingsObj, jobId);
    })
    .catch(function (error) {
      setWKFBusy(false);
      if (resultsDiv) {
        resultsDiv.style.display = 'block';
      }
      const summary = document.getElementById('wkf-ingestion-summary');
      if (summary) {
        summary.innerHTML = '<div class="alert alert-danger">WKF scenarios ingestion error: ' + escapeHtml(error.message) + '</div>';
      }
    });
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

            const pmsrSettings = drupalSettings.pmsr || {};
            const ingestionSettings = pmsrSettings.ingestion || null;
            const wkfSettings = pmsrSettings.wkfIngestion || null;

            const isWKFScenariosIngestion = window.location.pathname.includes('/pmsr/ingest/wkf-scenarios');
            if (isWKFScenariosIngestion && wkfSettings) {
              const organizationSelect = document.getElementById('wkf-deploy-organization');
              const selectedOrganizationUri = organizationSelect ? String(organizationSelect.value || '').trim() : '';
              const requireOrganizationSelection = !!wkfSettings.requireOrganizationSelection;

              if (requireOrganizationSelection && !selectedOrganizationUri) {
                const summary = document.getElementById('wkf-ingestion-summary');
                if (summary) {
                  summary.innerHTML = '<div class="alert alert-warning">Please select a deployment organization before starting WKF ingestion.</div>';
                }
                return;
              }

              const fromScratch = window.confirm(
                'Start from scratch?\n\nOK = uningest cached ingested WKFs first, then ingest all again.\nCancel = keep cache and ingest only non-ingested WKFs.'
              );
              runWKFScenariosIngestionFlow(this, wkfSettings, fromScratch, selectedOrganizationUri);
              return;
            }

            if (!ingestionSettings) {
              const fallbackResults = document.getElementById('ingestion-results');
              if (fallbackResults) {
                fallbackResults.style.display = 'block';
                fallbackResults.innerHTML = '<div class="alert alert-danger">Ingestion configuration is missing for this page.</div>';
              }
              return;
            }

            const endpoint = ingestionSettings.endpoint;
            const startEndpoint = ingestionSettings.startEndpoint;
            const statusEndpoint = ingestionSettings.statusEndpoint;
            const message = ingestionSettings.message;
            
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
                token: ingestionSettings.token || null
              });
            } else if (isPeopleIngestion) {
              // People ingestion needs token but no other parameters
              requestBody = JSON.stringify({
                token: ingestionSettings.token || null
              });
            }
            
            // Disable the button during ingestion
            $(this).prop('disabled', true).addClass('disabled');
            
            document.getElementById("ingestion-status").style.display = "block";
            document.getElementById("status-message").textContent = message;
            
            // Use the Drupal AJAX endpoint for ontologies/INS/Geography, backend endpoint for others
            const actualEndpoint = isDrupalIngestion ? endpoint : endpoint;
            
            fetchWithTimeout(actualEndpoint, {
              method: "POST",
              credentials: "same-origin",
              headers: {
                "Content-Type": "application/json"
              },
              body: requestBody
            }, 15000)
            .then(response => {
              return response.text().then(text => {
                let data = null;
                try {
                  data = JSON.parse(text);
                } catch (e) {
                  const snippet = (text || '').trim().slice(0, 220);
                  throw new Error('HTTP ' + response.status + ' from ingestion endpoint. ' + (snippet || 'Non-JSON response body.'));
                }
                return data;
              });
            })
            .then(data => {
              document.getElementById("ingestion-status").style.display = "none";
              
              const resultsDiv = document.getElementById("ingestion-results");
              resultsDiv.style.display = "block";
              
              // Re-enable the button after completion
              $('.btn-start-ingestion').prop('disabled', false).removeClass('disabled');
              
              // Handle Drupal-based ingestion response (ontologies and INS - both have progress array)
              if (isDrupalIngestion) {
                const progressList = Array.isArray(data.progress) ? data.progress : [];
                const errorsList = Array.isArray(data.errors) ? data.errors : [];
                const hasProgressIssues = progressList.some(function(step) {
                  const txt = String(step || '');
                  return txt.indexOf('✗') !== -1 || txt.indexOf('⚠') !== -1 || txt.indexOf('[ERROR]') !== -1;
                });
                const strictSuccess = data.success === true && errorsList.length === 0 && !hasProgressIssues;

                if (strictSuccess) {
                  let progressHTML = '<div class="alert alert-success alert-dismissable fade show" role="alert">' +
                    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                    '<span aria-hidden="true">&times;</span></button>' +
                    '<h4>✓ Ingestion Completed Successfully</h4>' +
                    '<p>' + (data.message || "All ontologies ingested successfully.") + '</p>';
                  
                  if (progressList.length > 0) {
                    progressHTML += '<div class="mt-3"><strong>Ingestion Progress:</strong><ul class="list-unstyled mt-2">';
                    progressList.forEach(function(step) {
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
                  
                  if (errorsList.length > 0) {
                    errorHTML += '<div class="mt-3"><strong>Errors:</strong><ul>';
                    errorsList.forEach(function(error) {
                      errorHTML += '<li>' + error + '</li>';
                    });
                    errorHTML += '</ul></div>';
                  }
                  
                  if (progressList.length > 0) {
                    errorHTML += '<div class="mt-3"><strong>Progress before error:</strong><ul class="list-unstyled mt-2">';
                    progressList.forEach(function(step) {
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
              const message = error && error.name === 'AbortError'
                ? 'Request timed out after 15 seconds. hascoapi is likely down or unreachable at localhost:9001.'
                : ('Failed to connect to the ingestion service: ' + error.message);
              resultsDiv.innerHTML = 
                '<div class="alert alert-danger alert-dismissable fade show" role="alert">' +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span></button>' +
                '<h4>✗ Error</h4>' +
                '<p>' + message + '</p>' +
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

      // Attach click handler to Refresh button for WKF scenarios page.
      $('.btn-refresh-ingestion', context).each(function() {
        if (!$(this).data('pmsr-wkf-refresh-processed')) {
          $(this).data('pmsr-wkf-refresh-processed', true);
          $(this).on('click', function(e) {
            e.preventDefault();
            const pmsrSettings = drupalSettings.pmsr || {};
            const wkfSettings = pmsrSettings.wkfIngestion || null;
            const isWKFScenariosIngestion = window.location.pathname.includes('/pmsr/ingest/wkf-scenarios');
            if (!isWKFScenariosIngestion || !wkfSettings) {
              return;
            }

            const jobId = wkfSettings.activeJobId || '';
            if (jobId) {
              resumeWKFScenariosIngestion(wkfSettings, jobId, 'Refreshing WKF ingestion state...');
            } else {
              renderWKFCachedView(wkfSettings, 'Refresh loaded WKF state from cache.');
            }
          });
        }
      });

      // Auto-resume active WKF ingestion job when the page loads/refreshed.
      const pmsrSettings = drupalSettings.pmsr || {};
      const wkfSettings = pmsrSettings.wkfIngestion || null;
      const isWKFScenariosIngestion = window.location.pathname.includes('/pmsr/ingest/wkf-scenarios');
      if (isWKFScenariosIngestion && wkfSettings && !wkfSettings._autoResumeDone) {
        wkfSettings._autoResumeDone = true;
        if (wkfSettings.activeJobId) {
          resumeWKFScenariosIngestion(wkfSettings, wkfSettings.activeJobId, wkfSettings.activeMessage || 'Resuming WKF ingestion...');
        }
      }
      
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
