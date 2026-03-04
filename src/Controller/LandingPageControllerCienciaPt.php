<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
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
