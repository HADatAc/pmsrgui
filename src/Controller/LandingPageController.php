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

    // Buttons definition - 3x2 grid (3 columns, 2 rows)
    $buttons_row1 = [
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Scenarios and Access Data', 'url' => 'std/search/studies'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Simulator<br /> By Hierarchy', 'url' => 'sir/list'],
      ['icon' => 'fas fa-cogs fa-2xl', 'label' => 'Generate and Register Formal Scenario (WKF)', 'url' => 'rep/select/mt/wkf/table/1/9/none'],
    ];

    $buttons_row2 = [
      ['icon' => 'fas fa-chart-simple fa-2xl', 'label' => 'Repository Statistics', 'url' => 'pmsr/statistics'],
      ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Organizations and Simulator Instances By Geography', 'url' => 'social/geography-map/2268747470733a2f2f706d73722e6e65742f6f6e742f504a543137343237383334383133383332353122'],
      ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage<br /> Simulator Instances', 'url' => 'dpl/select/instrumentinstance/1/9'],
    ];

    // INIT HTML
    $output = '';

    // HTML FOR BUTTONS
    $output .= '<div class="container my-5">';
    $output .= '<div class="row">';

    //USER IS AUTHENTICATED
    if ($user->isAuthenticated()) {
      // Row 1 - 3 columns
      foreach ($buttons_row1 as $button) {
        $output .= '<div class="col-4 d-flex flex-column">';
        
        // Base classes for styling
        $classes = 'btn btn-primary btn-lg my-2 d-flex align-items-center justify-content-center custom-button';

        // If button['disabled'] is set, add our disabled-link class and set href="#"
        if (!empty($button['disabled'])) {
          $classes .= ' disabled-link';
          $href = '#';
        } else {
          $href = $button['url'];
        }

        $output .= '<a href="' . $href . '" class="' . $classes . '">';
        $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;<h5>' . $button['label'] . '</h5>';
        $output .= '</a>';
        
        $output .= '</div>';
      }
      
      // Close row and start new row for Row 2
      $output .= '</div>'; // Close first row
      $output .= '<div class="row">'; // Start second row
      
      // Row 2 - 3 columns
      foreach ($buttons_row2 as $button) {
        $output .= '<div class="col-4 d-flex flex-column">';
        
        // Base classes for styling
        $classes = 'btn btn-primary btn-lg my-2 d-flex align-items-center justify-content-center custom-button';

        // If button['disabled'] is set, add our disabled-link class and set href="#"
        if (!empty($button['disabled'])) {
          $classes .= ' disabled-link';
          $href = '#';
        } else {
          $href = $button['url'];
        }

        $output .= '<a href="' . $href . '" class="' . $classes . '">';
        $output .= '<i class="' . $button['icon'] . ' me-2"></i>&nbsp;<h5>' . $button['label'] . '</h5>';
        $output .= '</a>';
        
        $output .= '</div>';
      }
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
