<?php

namespace Drupal\pmsr\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Drupal\file\Entity\File;

/**
 * Subscritor de evento para injetar HTML no footer de todas as páginas.
 */
class FooterOverrideSubscriber implements EventSubscriberInterface {

  /**
   * Registra o método que irá reagir ao evento RESPONSE do Kernel.
   *
   * @return array
   *   Array com o evento e o método a chamar.
   */
  public static function getSubscribedEvents() {
    // O -100 garante que isso ocorra perto do final do pipeline de resposta.
    return [
      KernelEvents::RESPONSE => ['injectFooter', -100],
    ];
  }

  /**
   * Injeta HTML antes de </body> em todas as respostas HTML do site.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   O objeto de evento, que contém a resposta.
   */
  public function injectFooter(ResponseEvent $event) {
    // Load config
    $config = \Drupal::config('pmsr.settings');

    // Module path
    $module_path = \Drupal::service('extension.list.module')->getPath('pmsr');

    // Verifica se é realmente uma resposta HTML.
    $response = $event->getResponse();
    $content_type = $response->headers->get('content-type');
    if ($content_type && str_contains($content_type, 'html')) {

      // Obtenha o HTML atual da página.
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

      // Defina aqui o HTML que deseja injetar:
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
          <div class="container h-20">
            <div class="row h-100 align-items-center">
              <div class="col text-center">
                <b><small>Tech Partners:</small></b> <a href="https://graxiom.com/" target="_blank"><img height="25" src="$partners_logo" alt="Tech Partners"></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="https://www.ipiaget.org/" target="_blank"><img height="25" src="$partners_2_logo" alt="Tech Partners"></a>
              </div>
            </div>
          </div>
        </div>
      HTML;

      // Insere logo antes de </body>.
      // IMPORTANTE: isso depende de haver um "</body>" minúsculo no HTML final.
      // Se seu tema/módulo gerar BODY maiúsculo ou outro, pode ser necessário
      // um replace case-insensitive, ou outra lógica.
      $content = str_replace('</footer>', $footer_html . '</footer>', $content);

      // Atualiza o conteúdo da resposta.
      $response->setContent($content);
    }
  }

}
