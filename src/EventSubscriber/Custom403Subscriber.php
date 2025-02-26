<?php

namespace Drupal\pmsr\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Event Subscriber para redirecionar quando ocorrer erro de acesso negado (403).
 */
class Custom403Subscriber implements EventSubscriberInterface {

  /**
   * Retorna a lista de eventos aos quais vamos nos inscrever.
   *
   * @return array
   *   Uma matriz de eventos e seus manipuladores.
   */
  public static function getSubscribedEvents() {
    return [
      // Escutar o evento de EXCEPTION do Kernel.
      KernelEvents::EXCEPTION => ['onException', 10],
    ];
  }

  /**
   * Manipulador do evento EXCEPTION.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   O evento que contém a exceção lançada.
   */
  public function onException(ExceptionEvent $event) {
    $exception = $event->getThrowable();

    // Verifica se é um erro de acesso negado (403).
    if ($exception instanceof AccessDeniedHttpException) {
      // Obter a rota atual para evitar redirecionamento em loop.
      $current_route = \Drupal::routeMatch()->getRouteName();

      // Evita loop se já estiver na rota de "pmsr.access_denied".
      if ($current_route === 'pmsr.access_denied') {
        return;
      }

      // Verifica se o usuário atual é anônimo.
      $account = \Drupal::currentUser();
      if ($account->isAnonymous()) {
        // Redireciona o usuário anônimo para a tela de login.
        $login_url = Url::fromRoute('user.login')->toString();
        $event->setResponse(new RedirectResponse($login_url));
        return;
      }

      // Para usuários logados (sem permissão), redirecionar para página 403 custom.
      // Garanta que pmsr.access_denied seja livre (requirements: _access: 'TRUE').
      $denied_url = Url::fromRoute('pmsr.access_denied')->toString();
      $event->setResponse(new RedirectResponse($denied_url));
    }
  }

}
