<?php

declare(strict_types=1);

namespace Drupal\klaxon\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\klaxon\Alert\Dispatcher;
use Drupal\klaxon\Alert\Outcome;
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
    $outcome = $this->dispatcher->fireNowExplained((string) $klaxon_alert->id());
    $arguments = ['%label' => $klaxon_alert->label()];

    // Saying nothing is the normal case, so say which of the several ways it
    // was: a run that reports only "nothing to say" sends you to the code to
    // find out whether the threshold, the cooldown or the channels did it.
    match (TRUE) {
      $outcome->spoke() => $this->messenger()->addStatus($this->t('%label fired and was delivered. This one went straight out rather than through the queue, so you can see the result now.', $arguments)),

      $outcome->reason === Outcome::NOT_FIRING => $this->messenger()->addStatus($this->t('%label ran and did not fire: it measured @measure, and it watches for @test.', $arguments + [
        '@measure' => $outcome->reading?->measure() ?? 0,
        '@test' => $klaxon_alert->getType()->summary(),
      ])),

      $outcome->reason === Outcome::COOLDOWN => $this->messenger()->addWarning($this->t('%label is firing, but it is in its cooldown for another @seconds seconds.', $arguments + [
        '@seconds' => $outcome->silentFor,
      ])),

      $outcome->reason === Outcome::ALREADY_FIRING => $this->messenger()->addWarning($this->t('%label is already firing, and it is set to speak only when that changes.', $arguments)),

      $outcome->reason === Outcome::NO_CHANNELS => $this->messenger()->addWarning($this->t('%label fired, but it has no enabled channel to send to.', $arguments)),

      $outcome->reason === Outcome::DISABLED => $this->messenger()->addWarning($this->t('%label is switched off.', $arguments)),

      default => $this->messenger()->addError($this->t('%label could not take its reading. The Klaxon log has the failure.', $arguments)),
    };

    return $this->redirect('entity.klaxon_alert.collection');
  }

}
