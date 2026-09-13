<?php

declare(strict_types=1);

namespace Drupal\klaxon\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\klaxon\Alert\Dispatcher;
use Drupal\klaxon\Entity\AlertInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Runs one alert on demand, so an editor can see what it would say.
 */
class RunAlert extends ControllerBase {

  public function __construct(
    protected readonly Dispatcher $dispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('klaxon.dispatcher'));
  }

  /**
   * Evaluates the alert now and reports what happened.
   */
  public function run(AlertInterface $klaxon_alert): RedirectResponse {
    if ($this->dispatcher->fireNow((string) $klaxon_alert->id())) {
      $this->messenger()->addStatus($this->t('%label fired and was delivered. This one went straight out rather than through the queue, so you can see the result now.', [
        '%label' => $klaxon_alert->label(),
      ]));
    }
    else {
      $this->messenger()->addWarning($this->t('%label ran but had nothing to say, or reached no channel. The log has the detail.', [
        '%label' => $klaxon_alert->label(),
      ]));
    }

    return $this->redirect('entity.klaxon_alert.collection');
  }

}
