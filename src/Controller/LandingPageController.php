<?php

namespace Drupal\pmsr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

class LandingPageController extends ControllerBase {
  public function content() {

    // LOAD CONFIG
    $config = \Drupal::config('pmsr.settings');
    $user = \Drupal::currentUser();

    // Module path
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');

    $title = $config->get('title') ?? 'Repositório Médico Português';

    // Load image 1
    $image_1_fid = $config->get('image_1');
    if (!empty($image_1_fid) && is_array($image_1_fid)) {
      $file = File::load($image_1_fid[0]);
      if ($file) {
        $img_1 = file_create_url($file->getFileUri());
      } else {
        $img_1 = base_path() . $module_path . '/images/img1.jpg';
      }
    } else {
      $img_1 = base_path() . $module_path . '/images/img1.jpg';
    }

    // Load image 2
    $image_2_fid = $config->get('image_2');
    if (!empty($image_2_fid) && is_array($image_2_fid)) {
      $file = File::load($image_2_fid[0]);
      if ($file) {
        $img_2 = file_create_url($file->getFileUri());
      } else {
        $img_2 = base_path() . $module_path . '/images/img2.jpg';
      }
    } else {
      $img_2 = base_path() . $module_path . '/images/img2.jpg';
    }

    // Load image 3
    $image_3_fid = $config->get('image_3');
    if (!empty($image_3_fid) && is_array($image_3_fid)) {
      $file = File::load($image_3_fid[0]);
      if ($file) {
        $img_3 = file_create_url($file->getFileUri());
      } else {
        $img_3 = base_path() . $module_path . '/images/img3.jpg';
      }
    } else {
      $img_3 = base_path() . $module_path . '/images/img3.jpg';
    }

    // Load footer logo
    $footer_logo_fid = $config->get('footer_logo');
    if (!empty($footer_logo_fid) && is_array($footer_logo_fid)) {
      $file = File::load($footer_logo_fid[0]);
      if ($file) {
        $footer_logo = file_create_url($file->getFileUri());
      } else {
        $footer_logo = base_path() . $module_path . '/images/footer.png';
      }
    } else {
      $footer_logo = base_path() . $module_path . '/images/footer.png';
    }

    // Buttons definition
    $buttons_col1 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage<br /> Simulator Model', 'url' => 'sir/select/instrument/1/9'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Simulator<br /> By Hierarchy', 'url' => 'sir/list'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Instances<br /> By Geography', 'url' => '#', 'disabled' => true],
    ];

    $buttons_col2 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage<br /> Simulator Instances', 'url' => 'dpl/select/instrumentinstance/1/9'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Simulator<br /> By Anatomy', 'url' => '#', 'disabled' => true],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Find and Access Data', 'url' => '#', 'disabled' => true],

    ];

    $buttons_col3 = [
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage Use Cases', 'url' => '#', 'disabled' => true],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Organization<br /> By Geography', 'url' => 'sir/list', 'disabled' => true],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search<br /> People by Geography', 'url' => '#', 'disabled' => true],
    ];

    // INIT HTML
    $output = '';

    // HTML FOR BUTTONS
    $output .= '<div class="container my-5">';
    $output .= '<div class="row">';

    //USER IS AUTHENTICATED
    if ($user->isAuthenticated()) {
      // Column 1
      $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
      // For each button, decide if it's disabled.
      foreach ($buttons_col1 as $button) {
        // Base classes for styling
        $classes = 'btn btn-primary btn-lg my-2 d-flex align-items-center justify-content-center custom-button';

        // If button['disabled'] is set, add our disabled-link class and set href="#"
        if (!empty($button['disabled'])) {
          $classes .= ' disabled-link'; // so pointer-events: none
          $href = '#'; // or "javascript:void(0)"
        } else {
          $href = $button['url'];
        }

        $output .= '<a href="' . $href . '" class="' . $classes . '">';
        $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;<h5>' . $button['label'] . '</h5>';
        $output .= '</a>';
      }

      $output .= '</div>';

      // Column 2
      $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
      // For each button, decide if it's disabled.
      foreach ($buttons_col2 as $button) {
        // Base classes for styling
        $classes = 'btn btn-primary btn-lg my-2 d-flex align-items-center justify-content-center custom-button';

        // If button['disabled'] is set, add our disabled-link class and set href="#"
        if (!empty($button['disabled'])) {
          $classes .= ' disabled-link'; // so pointer-events: none
          $href = '#'; // or "javascript:void(0)"
        } else {
          $href = $button['url'];
        }

        $output .= '<a href="' . $href . '" class="' . $classes . '">';
        $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;<h5>' . $button['label'] . '</h5>';
        $output .= '</a>';
      }

      $output .= '</div>';

      // Column 3
      $output .= '<div class="col-4 d-flex flex-column justify-content-between">';
      // For each button, decide if it's disabled.
      foreach ($buttons_col3 as $button) {
        // Base classes for styling
        $classes = 'btn btn-primary btn-lg my-2 d-flex align-items-center justify-content-center custom-button';

        // If button['disabled'] is set, add our disabled-link class and set href="#"
        if (!empty($button['disabled'])) {
          $classes .= ' disabled-link'; // so pointer-events: none
          $href = '#'; // or "javascript:void(0)"
        } else {
          $href = $button['url'];
        }

        $output .= '<a href="' . $href . '" class="' . $classes . '">';
        $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;<h5>' . $button['label'] . '</h5>';
        $output .= '</a>';
      }

      $output .= '</div>';
    } else {
      // USER IS NOT AUTHENTICATED

      $output .= '<div class="col-12 text-center">';
      $output .= '      <h2>Welcome to '.$config->get('title').'</h2>';
      $output .= '</div>';

      // CLOSE ROW
      $output .= '</div>';

      $output .= '<div class="row">';
      $output .= '<div class="col-2"></div>';
      $output .= '<div class="col-8 mt-5 text-left" style="margin-top:2rem;">';
      $output .= '  <p>To access the content you must be authenticated</p>';
      $output .= '  ';
      $output .= '</div>';
      $output .= '<div class="col-2"></div>';
    }

    // CLOSE CONTAINER
    $output .= '</div>';

    // HTML FOOTER
    // $output .= '<div id="landing_footer">
    //               <div class="container h-100">
    //                 <div class="row h-100 align-items-center">
    //                   <div class="col text-center">
    //                     <img height="40" src="'.$footer_logo.'" alt="footer logo">
    //                   </div>
    //                 </div>
    //               </div>
    //             </div>';

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
