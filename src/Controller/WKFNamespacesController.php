<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;

/**
 * Controller for WKF-requested namespace management in PMSR Setup.
 */
class WKFNamespacesController extends ControllerBase {

  /**
   * Render and process the WKF-requested Namespaces page.
   */
  public function content() {
    $api = \Drupal::service('rep.api_connector');
    $wkfNamespaces = [];

    try {
      $raw = $api->wkfNamespaceList();
      $data = $api->parseObjectResponse($raw, 'wkfNamespaceList');
      if (is_array($data)) {
        $wkfNamespaces = $data;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('pmsr')->error('Failed to load WKF namespaces: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Failed to load WKF-requested namespaces.'));
    }

    usort($wkfNamespaces, function ($a, $b) {
      $left = $this->readField($a, 'hasAbbreviation', 'label');
      $right = $this->readField($b, 'hasAbbreviation', 'label');
      return strcasecmp((string) $left, (string) $right);
    });

    $output = '';
    $output .= '<div class="container-fluid mt-4">';
    $output .= '<h2>WKF-requested Namespaces</h2>';
    $output .= '<p class="text-muted">Entries are created only through WKF MT ingestion (Namespaces sheet). This page is read-only for creation by design, and does not upload ontology content.</p>';

    $output .= '<div class="card mt-4">';
    $output .= '<div class="card-header bg-light"><strong>Current WKF Namespaces</strong></div>';
    $output .= '<div class="card-body">';

    if (empty($wkfNamespaces)) {
      $output .= '<div class="alert alert-info mb-0">No WKF-requested namespaces found.</div>';
    }
    else {
      $output .= '<div class="table-responsive">';
      $output .= '<table class="table table-striped table-bordered align-middle">';
      $output .= '<thead class="table-light">';
      $output .= '<tr>';
      $output .= '<th style="width: 120px;">Abbrev</th>';
      $output .= '<th>NameSpace</th>';
      $output .= '<th>Source URL</th>';
      $output .= '<th style="width: 180px;">MIME Type</th>';
      $output .= '<th style="width: 260px;">Actions</th>';
      $output .= '</tr>';
      $output .= '</thead>';
      $output .= '<tbody>';

      foreach ($wkfNamespaces as $item) {
        $abbrev = $this->readField($item, 'hasAbbreviation', 'label');
        $namespaceUri = $this->readField($item, 'wkfNamespaceUri', 'uri');
        $source = $this->readField($item, 'source');
        $mime = $this->readField($item, 'sourceMime');

        $safeAbbrev = htmlspecialchars((string) $abbrev, ENT_QUOTES, 'UTF-8');
        $safeNamespace = htmlspecialchars((string) $namespaceUri, ENT_QUOTES, 'UTF-8');
        $safeSource = htmlspecialchars((string) $source, ENT_QUOTES, 'UTF-8');
        $safeMime = htmlspecialchars((string) $mime, ENT_QUOTES, 'UTF-8');

        $output .= '<tr>';
        $output .= '<td><strong>' . $safeAbbrev . '</strong></td>';
        $output .= '<td>' . $safeNamespace . '</td>';
        $output .= '<td>' . $safeSource . '</td>';
        $output .= '<td>' . $safeMime . '</td>';
        $output .= '<td><span class="badge bg-secondary">Ingestion-managed</span></td>';
        $output .= '</tr>';
      }

      $output .= '</tbody>';
      $output .= '</table>';
      $output .= '</div>';
    }

    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    return [
      '#title' => 'WKF-requested Namespaces',
      '#markup' => Markup::create($output),
    ];
  }

  /**
   * Read field value from object/array safely.
   */
  private function readField($item, string ...$keys): string {
    foreach ($keys as $key) {
      if (is_array($item) && array_key_exists($key, $item) && $item[$key] !== NULL) {
        return (string) $item[$key];
      }
      if (is_object($item) && isset($item->{$key}) && $item->{$key} !== NULL) {
        return (string) $item->{$key};
      }
    }
    return '';
  }

}
