<?php

declare(strict_types=1);

namespace Drupal\klaxon\Alert;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\klaxon\AlertType\EntityEventAlertInterface;
use Drupal\klaxon\Entity\AlertInterface;
use Psr\Log\LoggerInterface;

/**
 * The one entry point for making alerts happen.
 *
 * Cron, the entity hooks and custom code all come through here, so evaluation
 * and delivery are wired together in exactly one place.
 *
 * Delivery is queued, so nothing here waits on a chat API or a mail server.
 *
 * Custom code fires an alert by id:
 * @code
 * \Drupal::service('klaxon.dispatcher')->fire('nightly_backup', [
 *   'facts' => ['Server' => $server->label(), 'Size' => $size],
 * ]);
 * @endcode
 * Give that alert the "when code fires it" type, so nothing else ever
 * evaluates it and the message says whatever the caller passed in.
 */
class Dispatcher {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AlertRunner $runner,
    protected readonly Deliverer $deliverer,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Evaluates one alert now and delivers whatever it produces.
   *
   * @param string $alert_id
   *   The alert to fire.
   * @param array $context
   *   Passed to the alert type. A code-fired alert reads 'value', 'rows' and
   *   'facts' from here; any entity in the array becomes available to tokens.
   *
   * @return bool
   *   TRUE when a message was produced and queued for at least one channel.
   *   Queued, not delivered: whether it arrives is the queue's business, and
   *   calling code should not wait to find out.
   */
  public function fire(string $alert_id, array $context = []): bool {
    return $this->evaluate($alert_id, $context, FALSE);
  }

  /**
   * As fire(), but delivers without the queue and reports what happened.
   *
   * For the Run now button, where someone is watching and wants to know
   * straight away whether the alert works. Everything else should use fire().
   *
   * @param string $alert_id
   *   The alert to fire.
   * @param array $context
   *   Passed to the alert type, as with fire().
   *
   * @return bool
   *   TRUE when a message was produced and a channel accepted it.
   */
  public function fireNow(string $alert_id, array $context = []): bool {
    return $this->evaluate($alert_id, $context, TRUE);
  }

  /**
   * Runs one alert and hands off whatever it produced.
   *
   * @param string $alert_id
   *   The alert to run.
   * @param array $context
   *   Passed to the alert type.
   * @param bool $immediate
   *   Whether to bypass the queue.
   *
   * @return bool
   *   TRUE when a message was produced and handed on.
   */
  protected function evaluate(string $alert_id, array $context, bool $immediate): bool {
    $alert = $this->entityTypeManager->getStorage('klaxon_alert')->load($alert_id);

    if (!$alert instanceof AlertInterface) {
      $this->logger->warning('Cannot fire unknown alert @id.', ['@id' => $alert_id]);
      return FALSE;
    }

    if (!$alert->status()) {
      return FALSE;
    }

    $message = $this->runner->run($alert, $context);

    if ($message === NULL) {
      return FALSE;
    }

    return $immediate
      ? $this->deliverer->send($alert, $message) > 0
      : $this->deliverer->enqueue($alert, $message) > 0;
  }

  /**
   * Runs every alert that is due. Called from cron.
   *
   * @return int
   *   How many alerts produced a message and had it queued.
   */
  public function runDue(): int {
    $sent = 0;

    foreach ($this->runner->runDue() as $alert_id => $message) {
      $alert = $this->entityTypeManager->getStorage('klaxon_alert')->load($alert_id);
      if ($alert instanceof AlertInterface && $this->deliverer->enqueue($alert, $message) > 0) {
        $sent++;
      }
    }

    return $sent;
  }

  /**
   * Runs the alerts watching for this change.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that changed.
   * @param string $operation
   *   One of insert, update or delete.
   */
  public function runForEntity(EntityInterface $entity, string $operation): void {
    foreach ($this->runner->enabledAlerts() as $alert) {
      $type = $alert->getType();

      if (!$type instanceof EntityEventAlertInterface) {
        continue;
      }

      if (!$type->applies($entity->getEntityTypeId()) || !$type->matches($entity, $operation)) {
        continue;
      }

      $context = [
        'entity' => $entity,
        'operation' => $operation,
      ];

      $message = $this->runner->run($alert, $context);
      if ($message !== NULL) {
        // Queued without exception: an entity save must never fail because a
        // chat API is slow, and the person saving is not waiting on this.
        $this->deliverer->enqueue($alert, $message);
      }
    }
  }

}
