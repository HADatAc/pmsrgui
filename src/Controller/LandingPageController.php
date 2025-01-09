<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;

class LandingPageController extends ControllerBase {
  public function content() {
    // Caminho para o logotipo
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');
    $logo_url = base_path() . $module_path . '/images/cienciapt_logo_150.png';

    // Definição dos botões
    $buttons_col1 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage<br /> Simulator Model', 'url' => '#'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Simulator', 'url' => '#'],

    ];

    $buttons_col2 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage<br /> Simulator Instances', 'url' => '#'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Data', 'url' => '#'],

    ];

    $buttons_col3 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage Use Cases', 'url' => '#'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Social Search', 'url' => '#'],
    ];

    // HTML HEADER
    $output = '<div id="landing_header" class="row background-portuguese-flag">';
    $output .= '
      <div class="col-2 d-flex align-items-center">
        <img class="px-5" height="150" src="'.$logo_url.'" />
      </div>
      <div class="col-9 d-flex align-items-center">
        <h2 class="text-white">Repositório Médico Português</h2>
      </div>
      <div class="col-1 d-flex align-items-center">
        <a class="nav-link" href="user/login">Login / Sign Up</a>
      </div>
    ';
    $output .= '</div>';

    // HTML IMAGES ROW
    $output .= '<div class="container-image">
                  <div class="row text-center p-0">
                    <div class="col-md-4 p-0">
                      <img src="https://images.stockcake.com/public/a/b/1/ab1fed88-d3a1-4768-9d47-a9b3e120eff9/high-tech-laboratory-interior-stockcake.jpg" class="custom-img" alt="Image 1">
                    </div>
                    <div class="col-md-4 p-0">
                      <img src="https://cdn2.picryl.com/photo/2013/02/20/working-in-laboratories-such-as-this-one-digital-forensics-840865-1024.jpg" class="custom-img" alt="Image 2">
                    </div>
                    <div class="col-md-4 p-0">
                      <img src="https://cdn2.picryl.com/photo/2011/02/04/optical-therapeutics-and-medical-nanophotonics-laboratory-5426179192-b63076-1024.jpg" class="custom-img" alt="Image 3">
                    </div>
                  </div>
                </div>';

    // HTML FOR BUTTONS
    $output .= '<div class="container text-center my-5">';
    $output .= '<div class="row">';

    // Column 1
    $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
    foreach ($buttons_col1 as $button) {
      $output .= '<a href="' . $button['url'] . '" class="btn btn-primary btn-lg my-2 d-flex align-items-center justify-content-center custom-button">';
      $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;';
      $output .= '<h5>' . $button['label'] . '</h5>';
      $output .= '</a>';
    }
    $output .= '</div>';

    // Column 2
    $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
    foreach ($buttons_col2 as $button) {
      $output .= '<a href="' . $button['url'] . '" class="btn btn-primary btn-lg my-2 d-flex align-items-center justify-content-center custom-button">';
      $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;';
      $output .= '<h5>' . $button['label'] . '</h5>';
      $output .= '</a>';
    }
    $output .= '</div>';

    // Column 3
    $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
    foreach ($buttons_col3 as $button) {
      $output .= '<a href="' . $button['url'] . '" class="btn btn-primary btn-lg my-2 d-flex align-items-center justify-content-center custom-button">';
      $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;';
      $output .= '<h5>' . $button['label'] . '</h5>';
      $output .= '</a>';
    }
    $output .= '</div>';

    $output .= '</div></div>';

    // Return HTML
    return [
      '#markup' => $output,
      '#attached' => [
        'library' => [
          'pmsr/styles',
        ],
      ],
    ];
  }
}
