(function (Drupal, once, drupalSettings) {
  'use strict';

  function toggle(el, show) {
    if (!el) return;
    el.style.display = show ? 'block' : 'none';
  }

  Drupal.behaviors.pmsrProjectContributorsMap = {
    attach: function (context) {
      once('pmsrProjectResolutionDebug', 'body', context).forEach(function () {
        var debug = (drupalSettings && drupalSettings.pmsrLandingDebug && drupalSettings.pmsrLandingDebug.projectResolution)
          ? drupalSettings.pmsrLandingDebug.projectResolution
          : null;
        if (!debug || typeof debug !== 'object' || Array.isArray(debug)) {
          console.warn('[pmsrLanding] consumer project resolution missing/invalid', debug);
          return;
        }

        console.log('[pmsrLanding] consumer project resolution', debug);
        try {
          console.log('[pmsrLanding][COPY_JSON]', JSON.stringify(debug, null, 2));
        } catch (err) {
          console.log('[pmsrLanding][COPY_JSON_FALLBACK]', String(debug));
        }

        if (debug.dynamicCall && Object.prototype.hasOwnProperty.call(debug.dynamicCall, 'parsed')) {
          console.log('[pmsrLanding] social API object result', debug.dynamicCall.parsed);
          try {
            console.log('[pmsrLanding][COPY_SOCIAL_PARSED]', JSON.stringify(debug.dynamicCall.parsed, null, 2));
          } catch (err) {
            console.log('[pmsrLanding][COPY_SOCIAL_PARSED_FALLBACK]', String(debug.dynamicCall.parsed));
          }
        }
      });

      once('pmsrProjectContribMap', '.pmsr-project-map-toggle', context).forEach(function (btn) {
        var wrapper = document.getElementById('pmsr-project-map-wrapper');
        var container = document.getElementById('pmsr-project-map-container');
        if (!wrapper || !container) return;

        var initialized = false;

        btn.addEventListener('click', function (e) {
          e.preventDefault();

          var isHidden = wrapper.style.display === 'none' || wrapper.style.display === '';
          var show = isHidden;

          if (show && !initialized) {
            // Create the element that the existing social/initiative_map behavior expects.
            container.innerHTML = '<div id="initiative-map" class="social-leaflet-map"></div>';
            Drupal.attachBehaviors(container);
            initialized = true;
          }

          toggle(wrapper, show);
          btn.setAttribute('aria-expanded', show ? 'true' : 'false');

          if (show) {
            setTimeout(function () {
              try {
                window.dispatchEvent(new Event('resize'));
              } catch (err) {}
            }, 120);
          }
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
