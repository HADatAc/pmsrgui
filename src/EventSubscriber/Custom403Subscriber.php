<?php

namespace Drupal\pmsr\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects 403 requests to a custom page.
 */
class Custom403Subscriber implements EventSubscriberInterface {

  /**
   * Responds to kernel.request events for access denied (403) responses.
   */
  public function onKernelRequest(RequestEvent $event) {
    $request = $event->getRequest();

    // Check if the response is a 403.
    if ($request->attributes->get('_route') === 'system.403') {
      $response = new RedirectResponse('/acess-denied');
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
