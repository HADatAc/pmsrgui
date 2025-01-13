<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

class LandingPageController extends ControllerBase {
  public function content() {

    // LOAD CONFIG
    $config = \Drupal::config('pmsr.settings');

    // Module path
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');

    $title = $config->get('title') ?? 'Repositório Médico Português';

    // Load Logo
    $logo_fid = $config->get('logo');
    $logo_url = base_path() . $module_path . '/images/cienciapt_logo_150.png';
    if (!empty($logo_fid)) {
      $file = File::load($logo_fid[0]);
      if ($file) {
        $logo_url = file_create_url($file->getFileUri());
      }
    }

    // Load image 1
    $image_1_fid = $config->get('image_1');
    $img_1 = base_path() . $module_path . '/images/img1.jpg';
    if (!empty($image_1_fid)) {
        $file = File::load($image_1_fid[0]);
      if ($file) {
        $img_1 = file_create_url($file->getFileUri());
      }
    }

    // Load image 2
    $image_2_fid = $config->get('image_2');
    $img_2 = base_path() . $module_path . '/images/img2.jpg';
    if (!empty($image_2_fid)) {
        $file = File::load($image_2_fid[0]);
      if ($file) {
        $img_2 = file_create_url($file->getFileUri());
      }
    }

    // Load image 3
    $image_3_fid = $config->get('image_3');
    $img_3 = base_path() . $module_path . '/images/img3.jpg';
    if (!empty($image_3_fid)) {
      $file = File::load($image_3_fid[0]);
      if ($file) {
        $img_3 = file_create_url($file->getFileUri());
      }
    }

    // Buttons definition
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

    // Check if user is authenticated
    $current_user = \Drupal::currentUser();
    if ($current_user->isAuthenticated()) {
      // User authenticated: show Profile and Log Out options
      $user_profile_url = Url::fromRoute('entity.user.canonical', ['user' => $current_user->id()])->toString();
      $logout_url = Url::fromRoute('user.logout')->toString();
      $user_links = '
        <a class="nav-link" href="' . $user_profile_url . '">Profile</a>&nbsp;|&nbsp;
        <a class="nav-link" href="' . $logout_url . '">Log Out</a>
      ';
    } else {
      // Anonymous user: show Login and Sign Up options
      $login_url = Url::fromRoute('user.login')->toString();
      $signup_url = Url::fromRoute('user.register')->toString();
      $user_links = '
        <a class="nav-link" href="' . $login_url . '?destination=pmsr">Login</a>&nbsp;|&nbsp;
        <a class="nav-link" href="' . $signup_url . '?destination=pmsr">Sign Up</a>
      ';
    }

    // HTML HEADER
    $output = '<div id="landing_header" class=" background-portuguese-flag">';
    $output .= '
      <div class="row pt-3">
        <div class="col d-flex align-items-center">
          <img class="px-5" height="150" src="'.$logo_url.'" />
        </div>
        <div class="col-8 d-flex align-items-center">
          <h2 class="text-white">'.$title.'</h2>
        </div>
        <div class="col d-flex align-items-center text-align-center">
          ' . $user_links . '
        </div>
      </div>
    ';
    $output .= '</div>';

    // HTML IMAGES ROW
    $output .= '<div class="container-image">
                  <div class="row text-center p-0">
                    <div class="col-md-4 p-0">
                      <img src="'.$img_1.'" class="custom-img" alt="Image 1">
                    </div>
                    <div class="col-md-4 p-0">
                      <img src="'.$img_2.'" class="custom-img" alt="Image 2">
                    </div>
                    <div class="col-md-4 p-0">
                      <img src="'.$img_3.'" class="custom-img" alt="Image 3">
                    </div>
                  </div>
                </div>';

    // HTML FOR BUTTONS
    $output .= '<div class="container text-center my-5">';
    $output .= '<div class="row">';

    // Column 1
    $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
    foreach ($buttons_col1 as $button) {
      $output .= '<a href="' . $button['url'] . '" class="btn btn-success btn-lg my-2 d-flex align-items-center justify-content-center custom-button">';
      $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;';
      $output .= '<h5>' . $button['label'] . '</h5>';
      $output .= '</a>';
    }
    $output .= '</div>';

    // Column 2
    $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
    foreach ($buttons_col2 as $button) {
      $output .= '<a href="' . $button['url'] . '" class="btn btn-success btn-lg my-2 d-flex align-items-center justify-content-center custom-button">';
      $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;';
      $output .= '<h5>' . $button['label'] . '</h5>';
      $output .= '</a>';
    }
    $output .= '</div>';

    // Column 3
    $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
    foreach ($buttons_col3 as $button) {
      $output .= '<a href="' . $button['url'] . '" class="btn btn-success btn-lg my-2 d-flex align-items-center justify-content-center custom-button">';
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
