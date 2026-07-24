(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.pmsr_bootstrap_progress = {
    attach: function (context, settings) {
      // Only run once
      const container = document.getElementById('bootstrap-live-progress');
      if (!container || container.dataset.initialized) {
        return;
      }
      container.dataset.initialized = 'true';

      // Create structure: ontology cards area + status message area
      container.innerHTML = `
        <div class="ontology-cards-container" id="ontology-cards"></div>
        <div class="status-message-area" id="status-messages"></div>
      `;
      
      const cardsContainer = document.getElementById('ontology-cards');
      const statusArea = document.getElementById('status-messages');

      const apiUrl = drupalSettings.pmsr.bootstrapApiUrl;
      
      fetch(apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        }
      })
      .then(response => {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        
        function processText({done, value}) {
          if (done) {
            return;
          }
          
          const text = decoder.decode(value);
          const lines = text.split('\n');
          
          lines.forEach(line => {
            if (line.trim()) {
              try {
                const data = JSON.parse(line);
                updateProgress(data);
              } catch (e) {
                console.error('Parse error:', e, line);
              }
            }
          });
          
          return reader.read().then(processText);
        }
        
        return reader.read().then(processText);
      })
      .catch(error => {
        console.error('Bootstrap error:', error);
        statusArea.innerHTML = 
          '<div class="status-message error"><span class="status-icon">❌</span><span class="status-text">Error: ' + error.message + '</span></div>';
      });
      
      function updateProgress(data) {
        if (data.type === 'ontology-list') {
          // Create all ontology cards upfront
          data.ontologies.forEach(ont => {
            const card = document.createElement('div');
            card.className = 'ontology-card ' + (ont.status || 'pending');
            card.id = 'ont-card-' + ont.label.replace(/[^a-zA-Z0-9]/g, '-');
            card.dataset.label = ont.label;
            
            card.innerHTML = `
              <div class="card-header">
                <span class="card-icon">⏳</span>
                <span class="card-label">${ont.label}</span>
              </div>
              <div class="card-body">
                <div class="card-comment">${ont.comment || ''}</div>
                <div class="card-status">Pending...</div>
                <div class="card-triples"></div>
              </div>
            `;
            
            cardsContainer.appendChild(card);
          });
          
        } else if (data.type === 'step') {
          // Update status message area (show only latest message)
          const statusDiv = document.createElement('div');
          statusDiv.className = 'status-message ' + (data.status || '');
          
          const icon = data.status === 'success' ? '✅' : 
                      data.status === 'error' ? '❌' : 
                      data.status === 'warning' ? '⚠️' : 
                      data.status === 'info' ? 'ℹ️' : '⏳';
          
          statusDiv.innerHTML = '<span class="status-icon">' + icon + '</span><span class="status-text">' + data.message + '</span>';
          
          if (data.details) {
            statusDiv.innerHTML += '<div class="status-details">' + data.details + '</div>';
          }
          
          // Replace content (show only latest)
          statusArea.innerHTML = '';
          statusArea.appendChild(statusDiv);
          
        } else if (data.type === 'ontology') {
          // Update ontology card
          const ontId = 'ont-card-' + data.ontology.replace(/[^a-zA-Z0-9]/g, '-');
          const card = document.getElementById(ontId);
          
          if (!card) return;
          
          // Update card status class
          card.className = 'ontology-card ' + data.status;
          
          // Update icon
          const iconSpan = card.querySelector('.card-icon');
          if (iconSpan) {
            iconSpan.textContent = data.status === 'success' ? '✅' : 
                                   data.status === 'verified' ? '✅' :
                                   data.status === 'error' ? '❌' : 
                                   data.status === 'warning' ? '⚠️' :
                                   data.status === 'info' ? 'ℹ️' :
                                   data.status === 'loading' ? '⏳' : '⏳';
          }
          
          // Update status text
          const statusDiv = card.querySelector('.card-status');
          if (statusDiv && data.message) {
            statusDiv.textContent = data.message;
          }
          
          // Update triple count
          const triplesDiv = card.querySelector('.card-triples');
          if (triplesDiv && data.triples) {
            triplesDiv.textContent = data.triples.toLocaleString() + ' triples';
            triplesDiv.style.display = 'block';
          }
          
        } else if (data.type === 'complete') {
          // Show completion message in status area
          const completeDiv = document.createElement('div');
          completeDiv.className = 'status-message complete ' + (data.status || 'success');
          
          const icon = data.status === 'error' ? '❌' : '✅';
          completeDiv.innerHTML = '<h2>' + icon + ' ' + data.message + '</h2>';
          
          if (data.details) {
            completeDiv.innerHTML += '<p class="complete-details">' + data.details + '</p>';
          }
          
          statusArea.innerHTML = '';
          statusArea.appendChild(completeDiv);
        }
      }
    }
  };
})(Drupal, drupalSettings);
