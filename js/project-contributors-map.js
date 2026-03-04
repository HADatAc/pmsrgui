(function (Drupal, once, drupalSettings) {
  'use strict';

  function toggle(el, show) {
    if (!el) return;
    el.style.display = show ? 'block' : 'none';
  }

  Drupal.behaviors.pmsrProjectContributorsMap = {
    attach: function (context) {
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
