<?php

namespace Drupal\pmsr\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Handles well-known probe endpoints.
 */
class WellKnownController {

  /**
   * Returns an empty successful response for Chrome devtools probe.
   */
  public function chromeDevTools(): Response {
    return new Response('', Response::HTTP_NO_CONTENT, [
      'Content-Type' => 'application/json',
      'Cache-Control' => 'public, max-age=3600',
    ]);
  }

}
