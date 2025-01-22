<?php

namespace Drupal\pmsr\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Drupal\file\Entity\File;

/**
 * Event subscriber to inject HTML into the footer of all pages.
 */
class FooterOverrideSubscriber implements EventSubscriberInterface {

  /**
   * Registers the method that will respond to the Kernel's RESPONSE event.
   *
   * @return array
   *   Array with the event and the method to call.
   */
  public static function getSubscribedEvents() {
    // The -100 guarantees that this occurs near the end of the response pipeline.
    return [
      KernelEvents::RESPONSE => ['injectFooter', -100],
    ];
  }

  /**
   * Injects HTML before </body> in all HTML responses on the site.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event object, which contains the response.
   */
  public function injectFooter(ResponseEvent $event) {
    // Load config
    $config = \Drupal::config('pmsr.settings');

    // Module path
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');

    // Check if it's really an HTML response.
    $response = $event->getResponse();
    $content_type = $response->headers->get('content-type');
    if ($content_type && str_contains($content_type, 'html')) {

      // Get the current HTML of the page.
      $content = $response->getContent();

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

      $partners_logo_fid = $config->get('partners_logo');
      if (!empty($partners_logo_fid) && is_array($partners_logo_fid)) {
        $file = File::load($partners_logo_fid[0]);
        if ($file) {
          $partners_logo = file_create_url($file->getFileUri());
        } else {
          $partners_logo = base_path() . $module_path . '/images/graxiom.png';
        }
      } else {
        $partners_logo = base_path() . $module_path . '/images/graxiom.png';
      }

      $partners_2_logo_fid = $config->get('partners_2_logo');
      if (!empty($partners_2_logo_fid) && is_array($partners_2_logo_fid)) {
        $file = File::load($partners_2_logo_fid[0]);
        if ($file) {
          $partners_2_logo = file_create_url($file->getFileUri());
        } else {
          $partners_2_logo = base_path() . $module_path . '/images/piaget.png';
        }
      } else {
        $partners_2_logo = base_path() . $module_path . '/images/piaget.png';
      }

      // Set the HTML to inject here:
      $footer_html = <<<HTML
        <div id="landing_footer" class="py-3">
          <div class="container h-100">
            <div class="row h-100 align-items-center">
              <div class="col text-center">
                <img height="40" src="$footer_logo" alt="footer logo">
              </div>
            </div>
          </div>
        </div>
        <div id="partners_footer" class="py-1">
          <div class="container h-20 w-100" style="text-align: right;padding-right: 0px!important;">
            <div class="row h-100">
              <div class="col text-right">
                <b><small class="pt-2">Powered by:</small></b> <a href="https://graxiom.com/" target="_blank"><img height="25" src="$partners_logo" alt="Tech Partners"></a></a>
              </div>
            </div>
          </div>
        </div>
      HTML;

      // Insert logo before </body>.
      // IMPORTANT: this depends on there being a "</body>" in the final HTML.
      // If your theme/module generates BODY uppercase or other, it may be necessary
      // to use a case-insensitive replace, or other logic.
      $content = str_replace('</footer>', $footer_html . '</footer>', $content);

      // Update the response content.
      $response->setContent($content);
    }
  }

}
