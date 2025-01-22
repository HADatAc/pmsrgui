<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides a custom 403 page.
 */
class AccessDeniedController extends ControllerBase {

  /**
   * Returns the custom 403 Access Denied page.
   */
  public function accessDeniedPage() {
    return [
      '#markup' => $this->t('Desculpe, você não tem permissão para acessar esta página.'),
    ];
  }

}
