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

    $title = 'Portuguese Medical Simulation Repository';

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

    $panels = [
      [
        'title' => 'Scenarios',
        'panelClass' => 'pmsr-landing-panel--scenarios',
        'actions' => [
          ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Scenarios and Access Data', 'url' => 'std/search/studies'],
          ['icon' => 'fas fa-cogs fa-2xl', 'label' => 'Generate Semantic Scenarios (WKF)', 'url' => 'rep/select/wkf/card/1/9/none'],
        ],
      ],
      [
        'title' => 'Simulators',
        'panelClass' => 'pmsr-landing-panel--simulators',
        'actions' => [
          ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Search Simulation Models', 'url' => 'sir/list'],
          ['icon' => 'fas fa-chart-bar fa-2xl', 'label' => 'Manage<br /> Simulator Instances', 'url' => 'dpl/select/instrumentinstance/1/9'],
        ],
      ],
      [
        'title' => 'Statistics',
        'panelClass' => 'pmsr-landing-panel--statistics',
        'actions' => [
          ['icon' => 'fas fa-chart-simple fa-2xl', 'label' => 'Repository Statistics', 'url' => 'pmsr/statistics'],
          ['icon' => 'fas fa-magnifying-glass fa-2xl', 'label' => 'Simulator Instances by Organizations', 'url' => 'social/geography-map/2268747470733a2f2f706d73722e6e65742f6f6e742f504a543137343237383334383133383332353122'],
        ],
      ],
    ];

    // INIT HTML
    $output = '<div class="container-fluid pmsr-landing-container my-5">';

    //USER IS AUTHENTICATED
    if ($user->isAuthenticated()) {
      $output .= '<div class="pmsr-landing-panels">';
      foreach ($panels as $panel) {
        $output .= '<section class="pmsr-landing-panel ' . $panel['panelClass'] . '">';
        $output .= '<div class="pmsr-landing-panel__overlay">';
        $output .= '<h3 class="pmsr-landing-panel__title">' . $panel['title'] . '</h3>';
        $output .= '<div class="pmsr-landing-panel__actions">';

        foreach ($panel['actions'] as $button) {
          $classes = 'btn btn-primary btn-lg d-flex align-items-center justify-content-center custom-button pmsr-landing-action';
          if (!empty($button['disabled'])) {
            $classes .= ' disabled-link';
            $href = '#';
          }
          else {
            $href = $button['url'];
          }

          $output .= '<a href="' . $href . '" class="' . $classes . '">';
          $output .= '<i class="' . $button['icon'] . ' me-2"></i><h5>' . $button['label'] . '</h5>';
          $output .= '</a>';
        }

        $output .= '</div>';
        $output .= '</div>';
        $output .= '</section>';
      }
      $output .= '</div>';
    }
    else {
      // USER IS NOT AUTHENTICATED
      $output .= '<div class="row">';
      $output .= '<div class="col-12 text-center">';
      $output .= '<h2>Welcome to ' . $title . '</h2>';
      $output .= '</div>';
      $output .= '</div>';

      $output .= '<div class="row">';
      $output .= '<div class="col-2"></div>';
      $output .= '<div class="col-8 mt-5 text-left" style="margin-top:2rem;">';
      $output .= '<p>To access the content you must be authenticated</p>';
      $output .= '</div>';
      $output .= '<div class="col-2"></div>';
      $output .= '</div>';
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
          'pmsr/pmsr-styles',
        ],
      ],
    ];
  }
}
