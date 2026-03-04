<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\rep\Form\DescribeForm;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Landing page: keeps the 5 buttons and renders a DESCRIBE of CienciaPT project.
 */
class LandingPageControllerCienciaPt extends ControllerBase {

  public function content(): array {

    // LOAD CONFIG
    $config = \Drupal::config('pmsr.settings');
    $user = $this->currentUser();

    // Buttons definition (exactly the current 5 buttons).
    $buttons_col1 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage<br /> Simulator Model', 'url' => 'sir/select/instrument/1/9'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Simulator<br /> By Hierarchy', 'url' => 'sir/list'],
    ];

    $buttons_col2 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage<br /> Simulator Instances', 'url' => 'dpl/select/instrumentinstance/1/9'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Organization<br /> By Geography', 'url' => 'social/initiative/project/_/_/_/_/1/12', 'disabled' => FALSE],
    ];

    $buttons_col3 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage Workflows', 'url' => 'std/select/workflow/1/9', 'disabled' => FALSE],
    ];

    // Feature flag: when disabled (or PMSR GUI bundle not present), fall back to
    // the original landing page implementation (big centered divs).
    if (!$this->isNewLandingEnabledForActiveTheme()) {
      $legacy = new LandingPageController();
      return $legacy->content();
    }

    $build = [
      '#attached' => [
        'library' => [
          'pmsr/pmsr-styles',
          'pmsr/pmsr-overrides',
          'pmsr/project_contributors_map',
          'rep/fontawesome',
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];

    // For anonymous users, show only the welcome message (no describe and no action buttons).
    if (!$user->isAuthenticated()) {
      $build['welcome'] = $this->buildButtonsBlock($config, FALSE, $buttons_col1, $buttons_col2, $buttons_col3);
      return $build;
    }

    // ---------- Describe block (authenticated) ----------
    $describe = $this->buildDescribeForConfiguredConsumerProject($buttons_col1, $buttons_col2, $buttons_col3);
    if (empty($describe)) {
      // If there is no mapped project, show only the landing buttons.
      $build['buttons_only'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['container-fluid', 'mt-4'],
          'style' => 'padding-left:110px; padding-right:110px;',
        ],
        'buttons' => $this->buildButtonsInlineRow(array_merge($buttons_col1, $buttons_col2, $buttons_col3)),
        '#cache' => ['max-age' => 0],
      ];
      return $build;
    }

    $build['describe'] = $describe;

    return $build;
  }

  protected function isNewLandingEnabledForActiveTheme(): bool {
    $enabled = (bool) \Drupal::config('rep.settings')->get('pmsr_new_landing_enabled');
    if (!$enabled) {
      return FALSE;
    }

    // In this codebase, the PMSR GUI bundle lives under themes/custom/pmsrgui
    // but it is not necessarily a Drupal theme (it contains the pmsr module).
    // Treat it as present when that folder exists and the pmsr module exists.
    try {
      $modulePath = \Drupal::service('extension.list.module')->getPath('pmsr');
      if (empty($modulePath)) {
        return FALSE;
      }
    }
    catch (\Throwable $e) {
      return FALSE;
    }

    $guiDir = rtrim((string) \Drupal::root(), '/\\') . '/themes/custom/pmsrgui';
    return is_dir($guiDir);
  }

  /**
   * Builds the DESCRIBE output for the project mapped to the configured OAuth consumer.
   */
  protected function buildDescribeForConfiguredConsumerProject(array $buttons_col1, array $buttons_col2, array $buttons_col3): array {
    $consumerId = trim((string) \Drupal::config('social.oauth.settings')->get('client_id'));
    if ($consumerId === '') {
      return [];
    }

    try {
      $projectUri = \Drupal::database()
        ->select('socialm_manageConsumers', 'mc')
        ->fields('mc', ['project_id'])
        ->condition('consumer_id', $consumerId)
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      \Drupal::logger('pmsr')->error('Failed to resolve project_id for consumer_id=@c: @msg', [
        '@c' => $consumerId,
        '@msg' => $e->getMessage(),
      ]);
      return [];
    }

    if (empty($projectUri)) {
      return [];
    }

    // Build contributor markers for the map (best-effort; cached geocoding).
    $markers = $this->buildContributorMapMarkers((string) $projectUri);

    // Build the Describe section using REP's own forms, in the same structure as /rep/uri/*,
    // but rendered inside the PMSR landing layout (no Drupal region/block placement required).
    $encoded = base64_encode($projectUri);

    $requestStack = \Drupal::service('request_stack');
    $currentRequest = $requestStack->getCurrentRequest();
    if (!$currentRequest) {
      return [];
    }

    // Many REP Describe forms infer elementuri from the current request path (/rep/uri/{base64}).
    // Create a sub-request with the same session so OAuth/session logic keeps working.
    $fakePath = '/rep/uri/' . rawurlencode($encoded);
    $fakeRequest = Request::create(
      $fakePath,
      'GET',
      $currentRequest->query->all(),
      $currentRequest->cookies->all(),
      [],
      $currentRequest->server->all()
    );

    if ($currentRequest->hasSession()) {
      $fakeRequest->setSession($currentRequest->getSession());
    }

    $requestStack->push($fakeRequest);
    try {
      // Left column: same sidebar content (header + data properties/main form).
      $headerBuild = \Drupal::formBuilder()->getForm('Drupal\\rep\\Form\\DescribeHeaderForm');
      $describeBuild = \Drupal::formBuilder()->getForm(DescribeForm::class, $encoded);

      // Right column: graph + associated elements + provenance/derivation.
      $associatesBuild = \Drupal::formBuilder()->getForm('Drupal\\rep\\Form\\DescribeAssociatesForm');
      $derivationBuild = \Drupal::formBuilder()->getForm('Drupal\\rep\\Form\\DescribeDerivationForm');
    }
    finally {
      $requestStack->pop();
    }

    $buttonsBuild = $this->buildButtonsInlineRow(array_merge($buttons_col1, $buttons_col2, $buttons_col3));

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['container-fluid', 'mt-4'],
        'style' => 'padding-left:110px; padding-right:110px;',
      ],
      '#attached' => [
        'drupalSettings' => [
          // Reuse the existing Social leaflet behavior.
          'socialInitiativeMap' => [
            'markers' => $markers,
          ],
        ],
      ],
      'row' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['row', 'g-4']],
        'left' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['col-12', 'col-lg-3']],
          'header' => $headerBuild,
          'describe' => $describeBuild,
        ],
        'right' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['col-12', 'col-lg-9']],
          // Buttons should sit above the graph (graph is inside DescribeAssociatesForm).
          'buttons' => $buttonsBuild,
          'map' => [
            '#type' => 'markup',
            '#markup' => '<div id="pmsr-project-map-wrapper" class="mb-3" style="display:none;">'
              . (empty($markers) ? '<div class="small text-muted mb-2">No contributor addresses found.</div>' : '')
              . '<div id="pmsr-project-map-container"></div>'
              . '</div>',
          ],
          'associates' => $associatesBuild,
          'derivation' => $derivationBuild,
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  protected function buildButtonsInlineRow(array $buttons): array {
    $links = [];
    foreach ($buttons as $button) {
      $isDisabled = !empty($button['disabled']);
      $href = $isDisabled ? '#' : Url::fromUserInput('/' . ltrim((string) $button['url'], '/'))->toString();

      // Keep icon but avoid oversized fa-2xl.
      $iconClass = trim(str_replace('fa-2xl', '', (string) ($button['icon'] ?? '')));
      $iconClass = htmlspecialchars($iconClass);
      $labelText = htmlspecialchars(strip_tags((string) ($button['label'] ?? '')));

      $classes = 'btn btn-primary text-nowrap';
      if ($isDisabled) {
        $classes .= ' disabled';
      }

      $links[] = '<a class="' . $classes . '" href="' . $href . '"><i class="' . $iconClass . ' me-1"></i>' . $labelText . '</a>';
    }

    // Map toggle icon (after the 5 buttons).
    $links[] = '<button type="button" class="btn btn-outline-primary pmsr-project-map-toggle" aria-expanded="false" aria-controls="pmsr-project-map-wrapper" title="Map">'
      . '<i class="fa fa-map"></i>'
      . '</button>';

    $markup = '';
    $markup .= '<div class="mb-3">';
    $markup .= '  <div class="d-flex flex-nowrap justify-content-start gap-2">';
    $markup .= implode('', $links);
    $markup .= '  </div>';
    $markup .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($markup),
    ];
  }

  /**
   * Best-effort marker generation for project contributors.
   */
  protected function buildContributorMapMarkers(string $projectUri): array {
    /** @var \Drupal\rep\ApiConnectorInterface $api */
    $api = \Drupal::service('rep.api_connector');
    // Social module provides Leaflet assets + Nominatim geocoder. If it's not
    // enabled, the map feature should degrade gracefully.
    try {
      /** @var \Drupal\social\Service\NominatimGeocoder $geocoder */
      $geocoder = \Drupal::service('social.geocoder');
    }
    catch (\Throwable $e) {
      return [];
    }
    /** @var \Drupal\Core\Cache\CacheBackendInterface $cache */
    $cache = \Drupal::cache();

    $raw = $api->getUri(Utils::plainUri($projectUri));
    if (!$raw) {
      return [];
    }
    $project = $api->parseObjectResponse($raw, 'getUri');
    if (!is_object($project)) {
      return [];
    }

    $contributors = [];
    if (!empty($project->contributors) && is_array($project->contributors)) {
      foreach ($project->contributors as $c) {
        if (is_object($c) && !empty($c->uri)) {
          $contributors[(string) $c->uri] = $c;
        }
      }
    }
    // Use contributorUris only as fallback when contributors are absent.
    if (empty($contributors) && !empty($project->contributorUris) && is_array($project->contributorUris)) {
      foreach ($project->contributorUris as $u) {
        if (is_string($u) && $u !== '') {
          $contributors[$u] = (object) ['uri' => $u];
        }
        elseif (is_object($u) && !empty($u->uri)) {
          $contributors[(string) $u->uri] = $u;
        }
      }
    }

    if (empty($contributors)) {
      return [];
    }

    $placeholder = Utils::placeholderImage('', 'organization', '/');
    $markers = [];
    $max_live_geocodes = 40;
    $live_geocodes = 0;

    foreach (array_keys($contributors) as $uri) {
      $uri = (string) $uri;
      if ($uri === '') {
        continue;
      }

      $coords = NULL;
      $nominatimDisplay = '';

      $obj = $contributors[$uri] ?? (object) ['uri' => $uri];

      // Always consult the contributor URI (CienciaPT) for address data.
      $full = NULL;
      $fullRaw = $api->getUri(Utils::plainUri($uri));
      if ($fullRaw) {
        $parsed = $api->parseObjectResponse($fullRaw, 'getUri');
        if (is_object($parsed)) {
          $full = $parsed;
        }
      }

      // Build geocoding queries from address attributes (most specific first).
      $queries = $this->buildPortugalAddressQueries($full ?: $obj);
      $query = $queries[0] ?? NULL;
      $queryHash = $query ? sha1(mb_strtolower(trim($query))) : '';

      // Prefer cached coords *only* when they match the current query.
      // Versioned to avoid stale centroid results from earlier implementations.
      $cid = 'pmsr_contrib_geocode:v3:' . sha1($uri);
      if ($cacheItem = $cache->get($cid)) {
        if (
          is_array($cacheItem->data)
          && isset($cacheItem->data['lat'], $cacheItem->data['lng'], $cacheItem->data['queryHash'])
          && $queryHash !== ''
          && hash_equals((string) $cacheItem->data['queryHash'], (string) $queryHash)
        ) {
          $coords = [
            'lat' => (float) $cacheItem->data['lat'],
            'lng' => (float) $cacheItem->data['lng'],
          ];
        }
      }

      // Try explicit coordinates, but only at the address/locality/region
      // level. Do NOT use country-level coordinates (Portugal centroid).
      if (!$coords) {
        $c = $this->findLatLngFromAddressWithSource($full ?: $obj);
        if ($c && isset($c['lat'], $c['lng'])) {
          $coords = ['lat' => (float) $c['lat'], 'lng' => (float) $c['lng']];
        }
      }

      // Live geocode if needed.
      if (!$coords && $live_geocodes < $max_live_geocodes) {
        $usedQuery = '';
        foreach ($queries as $candidateQuery) {
          if ($live_geocodes >= $max_live_geocodes) {
            break;
          }
          $geo = $geocoder->geocodePortugal($candidateQuery);
          $live_geocodes++;
          if ($geo && isset($geo['lat'], $geo['lng'])) {
            $coords = ['lat' => (float) $geo['lat'], 'lng' => (float) $geo['lng']];
            $usedQuery = (string) $candidateQuery;
            $query = $usedQuery;
            $queryHash = sha1(mb_strtolower(trim($usedQuery)));
            $nominatimDisplay = (string) ($geo['display_name'] ?? '');
            $cache->set($cid, [
              'lat' => $coords['lat'],
              'lng' => $coords['lng'],
              'queryHash' => $queryHash,
            ], time() + 2592000, ['social:geocode']);
            break;
          }
        }
      }

      if (!$coords) {
        continue;
      }

      $title = '';
      if (is_object($obj)) {
        $title = (string) ($obj->label ?? ($obj->name ?? ''));
      }
      if ($title === '' && $full && is_object($full)) {
        $title = (string) ($full->label ?? ($full->name ?? ''));
      }
      if ($title === '') {
        $title = Utils::namespaceUri($uri);
      }

      $imageUrl = '';
      if (is_object($obj) && !empty($obj->hasImageUri)) {
        $imageUrl = Utils::getAPIImage($uri, (string) $obj->hasImageUri, $placeholder);
      }
      elseif ($full && is_object($full) && !empty($full->hasImageUri)) {
        $imageUrl = Utils::getAPIImage($uri, (string) $full->hasImageUri, $placeholder);
      }

      $externalUrl = '';
      $candidate = '';
      if (is_object($obj) && !empty($obj->hasWebDocument)) {
        $candidate = (string) $obj->hasWebDocument;
      }
      elseif ($full && is_object($full)) {
        $candidate = (string) ($full->hasWebDocument ?? ($full->hasURL ?? ($full->url ?? '')));
      }
      if ($candidate !== '' && UrlHelper::isValid($candidate, TRUE)) {
        $externalUrl = UrlHelper::filterBadProtocol($candidate);
      }

      $openHref = Url::fromUserInput('/rep/uri/' . base64_encode($uri))->toString();

      $popup = '<div class="small">'
        . ($imageUrl !== ''
          ? '<div class="mb-2"><img src="' . Html::escape($imageUrl) . '" alt="' . Html::escape($title) . '" style="width:48px;height:48px;object-fit:contain;border:1px solid #ddd;border-radius:6px;background:#fff;" /></div>'
          : '')
        . '<strong>' . Html::escape($title) . '</strong>'
        . '<div class="mt-2"><a target="_blank" rel="noopener" href="' . Html::escape($openHref) . '">Open</a></div>'
        . '</div>';

      $markers[] = [
        'lat' => $coords['lat'],
        'lng' => $coords['lng'],
        'title' => $title,
        'elementType' => 'organization',
        'imageUrl' => $imageUrl,
        'markerText' => 'ORG',
        'popupHtml' => $popup,
        'url' => $openHref,
      ];
    }

    return $markers;
  }

  protected function findLatLng($data): ?array {
    $found = $this->findLatLngRecursive($data);
    if (!$found) {
      return NULL;
    }
    $lat = $this->normalizeCoordinate($found['lat'], -90, 90);
    $lng = $this->normalizeCoordinate($found['lng'], -180, 180);
    if ($lat === NULL || $lng === NULL) {
      return NULL;
    }
    return ['lat' => $lat, 'lng' => $lng];
  }

  /**
   * Find coordinates from address-level data only.
   *
   * Some CienciaPT objects include coordinates for the country (Portugal)
   * which would place the marker in the country's centroid. This method
   * intentionally ignores country-level coordinates.
   */
  protected function findLatLngFromAddress($data): ?array {
    if (!is_object($data) && !is_array($data)) {
      return NULL;
    }

    // 1) Prefer PostalAddress itself.
    $postal = $this->findPostalAddressObject($data);
    if ($postal) {
      // Address-level coordinates.
      $coords = $this->findDirectLatLng($postal);
      if ($coords) {
        return $coords;
      }

      // Locality-level coordinates.
      if (isset($postal->hasAddressLocality) && is_object($postal->hasAddressLocality)) {
        $coords = $this->findDirectLatLng($postal->hasAddressLocality);
        if ($coords) {
          return $coords;
        }
      }

      // Region-level coordinates.
      if (isset($postal->hasAddressRegion) && is_object($postal->hasAddressRegion)) {
        $coords = $this->findDirectLatLng($postal->hasAddressRegion);
        if ($coords) {
          return $coords;
        }
      }

      // Intentionally ignore hasAddressCountry coordinates.
      return NULL;
    }

    // 2) If no PostalAddress object was found, check common direct containers.
    if (is_object($data)) {
      foreach (['hasAddress', 'address'] as $k) {
        if (isset($data->{$k})) {
          $coords = $this->findLatLng($data->{$k});
          if ($coords) {
            return $coords;
          }
        }
      }
    }

    return NULL;
  }

  protected function findLatLngFromAddressWithSource($data): ?array {
    if (!is_object($data) && !is_array($data)) {
      return NULL;
    }

    $postal = $this->findPostalAddressObject($data);
    if ($postal) {
      $coords = $this->findDirectLatLng($postal);
      if ($coords) {
        $coords['source'] = 'postal';
        return $coords;
      }

      if (isset($postal->hasAddressLocality) && is_object($postal->hasAddressLocality)) {
        $coords = $this->findDirectLatLng($postal->hasAddressLocality);
        if ($coords) {
          $coords['source'] = 'locality';
          return $coords;
        }
      }

      if (isset($postal->hasAddressRegion) && is_object($postal->hasAddressRegion)) {
        $coords = $this->findDirectLatLng($postal->hasAddressRegion);
        if ($coords) {
          $coords['source'] = 'region';
          return $coords;
        }
      }

      return NULL;
    }

    // No PostalAddress object, bail out (avoid using country-level coords).
    return NULL;
  }

  /**
   * Read coordinate fields from the current object only (no recursion).
   *
   * This prevents falling through to nested country coordinates when evaluating
   * PostalAddress/locality/region objects.
   */
  protected function findDirectLatLng($data): ?array {
    if (is_object($data)) {
      $data = (array) $data;
    }
    if (!is_array($data)) {
      return NULL;
    }

    $lat = NULL;
    $lng = NULL;
    foreach ($data as $key => $value) {
      $k = strtolower((string) $key);
      if (in_array($k, ['lat', 'latitude', 'haslatitude'], TRUE)) {
        $lat = $value;
      }
      if (in_array($k, ['lng', 'lon', 'long', 'longitude', 'haslongitude', 'haslong'], TRUE)) {
        $lng = $value;
      }
    }

    if ($lat === NULL || $lng === NULL) {
      return NULL;
    }

    $latN = $this->normalizeCoordinate($lat, -90, 90);
    $lngN = $this->normalizeCoordinate($lng, -180, 180);
    if ($latN === NULL || $lngN === NULL) {
      return NULL;
    }

    return ['lat' => $latN, 'lng' => $lngN];
  }

  protected function findLatLngRecursive($data): ?array {
    if (is_object($data)) {
      $data = (array) $data;
    }
    if (!is_array($data)) {
      return NULL;
    }

    $lat = NULL;
    $lng = NULL;
    foreach ($data as $key => $value) {
      $k = strtolower((string) $key);
      if (in_array($k, ['lat', 'latitude', 'haslatitude'], TRUE)) {
        $lat = $value;
      }
      if (in_array($k, ['lng', 'lon', 'long', 'longitude', 'haslongitude', 'haslong'], TRUE)) {
        $lng = $value;
      }
    }
    if ($lat !== NULL && $lng !== NULL) {
      return ['lat' => $lat, 'lng' => $lng];
    }

    foreach ($data as $value) {
      $found = $this->findLatLngRecursive($value);
      if ($found) {
        return $found;
      }
    }

    return NULL;
  }

  protected function normalizeCoordinate($value, float $min, float $max): ?float {
    if ($value === NULL) {
      return NULL;
    }
    if (is_string($value)) {
      $value = str_replace(',', '.', trim($value));
    }
    if (!is_numeric($value)) {
      return NULL;
    }
    $num = (float) $value;
    if ($num < $min || $num > $max) {
      return NULL;
    }
    return $num;
  }

  protected function buildPortugalAddressQuery($data): ?string {
    $queries = $this->buildPortugalAddressQueries($data);
    return $queries[0] ?? NULL;
  }

  protected function buildPortugalAddressQueries($data): array {
    $postal = $this->findPostalAddressObject($data);
    if (!$postal) {
      return [];
    }

    $street = $this->firstNonEmpty([
      $postal->hasStreetAddress ?? NULL,
      $postal->streetAddress ?? NULL,
    ]);

    $postalCode = $this->firstNonEmpty([
      $postal->hasPostalCode ?? NULL,
      $postal->postalCode ?? NULL,
    ]);

    $locality = $this->firstNonEmpty([
      $postal->hasAddressLocality->label ?? NULL,
      $postal->hasAddressLocalityLabel ?? NULL,
    ]);

    $region = $this->firstNonEmpty([
      $postal->hasAddressRegion->label ?? NULL,
      $postal->hasAddressRegionLabel ?? NULL,
    ]);

    $country = $this->firstNonEmpty([
      $postal->hasAddressCountry->label ?? NULL,
      $postal->hasAddressCountryLabel ?? NULL,
    ]);

    // Don't geocode a generic "Portugal" query; require some locality/region/street/postal.
    $hasSpecific = FALSE;
    foreach ([$street, $postalCode, $locality, $region] as $v) {
      if (trim((string) $v) !== '') {
        $hasSpecific = TRUE;
        break;
      }
    }
    if (!$hasSpecific) {
      return [];
    }

    $parts = array_filter([
      (string) $street,
      trim((string) ($postalCode . ' ' . $locality)),
      (string) $region,
      (string) $country,
    ], static function ($v) {
      return trim((string) $v) !== '';
    });

    // Ensure country is present, but only after we have specific parts.
    if (!in_array('Portugal', $parts, TRUE)) {
      $parts[] = 'Portugal';
    }

    if (empty($parts)) {
      return [];
    }

    $queries = [];

    // Most specific (street + postal/locality + region + country).
    $queries[] = implode(', ', $parts);

    // Fallbacks for cases where street punctuation hurts geocoding.
    $postalLocality = trim((string) ($postalCode . ' ' . $locality));
    if ($postalLocality !== '') {
      $queries[] = $postalLocality . ', Portugal';
      if ($region !== '') {
        $queries[] = $postalLocality . ', ' . $region . ', Portugal';
      }
    }

    if ($locality !== '') {
      if ($region !== '') {
        $queries[] = $locality . ', ' . $region . ', Portugal';
      }
      $queries[] = $locality . ', Portugal';
    }

    // De-duplicate while preserving order.
    $seen = [];
    $out = [];
    foreach ($queries as $q) {
      $k = mb_strtolower(trim((string) $q));
      if ($k === '' || isset($seen[$k])) {
        continue;
      }
      $seen[$k] = TRUE;
      $out[] = trim((string) $q);
    }

    return $out;
  }

  protected function findPostalAddressObject($data): ?object {
    if (is_object($data)) {
      // Prefer explicit address containers.
      if (isset($data->hasAddress) || isset($data->address)) {
        $addr = $data->hasAddress ?? $data->address;
        if (is_object($addr)) {
          $found = $this->findPostalAddressObject($addr);
          if ($found) {
            return $found;
          }
        }
        if (is_array($addr)) {
          foreach ($addr as $a) {
            $found = $this->findPostalAddressObject($a);
            if ($found) {
              return $found;
            }
          }
        }
      }

      // Heuristic: a PostalAddress-like object.
      if (
        isset($data->hasStreetAddress)
        || isset($data->streetAddress)
        || isset($data->hasPostalCode)
        || isset($data->postalCode)
        || isset($data->hasAddressLocality)
        || isset($data->hasAddressRegion)
      ) {
        return $data;
      }

      foreach ((array) $data as $value) {
        $found = $this->findPostalAddressObject($value);
        if ($found) {
          return $found;
        }
      }
    }
    elseif (is_array($data)) {
      foreach ($data as $value) {
        $found = $this->findPostalAddressObject($value);
        if ($found) {
          return $found;
        }
      }
    }

    return NULL;
  }

  protected function firstNonEmpty(array $values): ?string {
    foreach ($values as $v) {
      if ($v === NULL) {
        continue;
      }
      $s = trim((string) $v);
      if ($s !== '') {
        return $s;
      }
    }
    return NULL;
  }

  protected function buildButtonsBlock($config, bool $isAuthenticated, array $buttons_col1, array $buttons_col2, array $buttons_col3): array {
    if (!$isAuthenticated) {
      $markup = '';
      $markup .= '<div class="container my-4">';
      $markup .= '<div class="text-center">';
      $markup .= '  <h2>' . $this->t('Welcome to @title', ['@title' => (string) ($config->get('title') ?? '')]) . '</h2>';
      $markup .= '</div>';
      $markup .= '<div class="mt-4">';
      $markup .= '  <p>' . $this->t('To access the content you must be authenticated') . '</p>';
      $markup .= '</div>';
      $markup .= '</div>';

      return [
        '#type' => 'markup',
        '#markup' => Markup::create($markup),
      ];
    }

    $columns = [$buttons_col1, $buttons_col2, $buttons_col3];
    $markup = '';
    $markup .= '<div class="container my-4">';
    $markup .= '  <div class="row g-3">';

    foreach ($columns as $buttons) {
      $markup .= '    <div class="col-12 col-md-4">';
      $markup .= '      <div class="d-grid gap-2">';
      foreach ($buttons as $button) {
        $isDisabled = !empty($button['disabled']);
        $href = $isDisabled ? '#' : Url::fromUserInput('/' . ltrim((string) $button['url'], '/'))->toString();
        $classes = 'btn btn-primary';
        if ($isDisabled) {
          $classes .= ' disabled';
        }

        $labelText = strip_tags((string) $button['label']);
        $icon = htmlspecialchars((string) $button['icon']);
        $markup .= '        <a class="' . $classes . '" href="' . $href . '">';
        $markup .= '          <i class="' . $icon . ' me-2"></i>' . htmlspecialchars($labelText);
        $markup .= '        </a>';
      }
      $markup .= '      </div>';
      $markup .= '    </div>';
    }

    $markup .= '  </div>';
    $markup .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($markup),
    ];
  }

}
