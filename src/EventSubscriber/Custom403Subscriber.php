<?php

namespace Drupal\pmsr\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects 403 requests to a custom page.
 */
class Custom403Subscriber implements EventSubscriberInterface {

  /**
   * Responds to kernel.request events for access denied (403) responses.
   */
  public function onKernelRequest(RequestEvent $event) {
    $request = $event->getRequest();

    // Evitar redirecionamento para a própria página personalizada.
    if ($request->getPathInfo() === '/acess-denied') {
        return;
    }

    // Certificar-se de que a rota é 403 e que nenhuma resposta foi enviada.
    if ($request->attributes->get('_route') === 'system.403' && !$event->hasResponse()) {
        // Redirecionar para a página personalizada.
        $response = new RedirectResponse('sir.landing_page');
        $event->setResponse($response);
    }
}


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'kernel.request' => ['onKernelRequest', 10],
    ];
  }

}
