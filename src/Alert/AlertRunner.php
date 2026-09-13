<?php

declare(strict_types=1);

namespace Drupal\klaxon\Alert;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\klaxon\AlertType\ScheduledAlertInterface;
use Drupal\klaxon\Entity\AlertInterface;
use Drupal\klaxon\Message;
use Drupal\klaxon\Reading;
use Psr\Log\LoggerInterface;

/**
 * Evaluates alerts and decides whether there is anything worth saying.
 *
 * The runner never delivers. It returns a message or nothing, which keeps the
 * decision testable without a transport and lets delivery be queued.
 */
class AlertRunner {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly StateStore $state,
    protected readonly MessageRenderer $renderer,
    protected readonly LoggerInterface $logger,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Evaluates one alert now, whether or not it was due.
   *
   * @param \Drupal\klaxon\Entity\AlertInterface $alert
   *   The alert to evaluate.
   * @param array $context
   *   Context for the alert type. An entity event supplies the entity here;
   *   a code-fired alert supplies whatever the calling code passed.
   *
   * @return \Drupal\klaxon\Message|null
   *   The message to deliver, or NULL when there is nothing to say.
   */
  public function run(AlertInterface $alert, array $context = []): ?Message {
    $now = $this->time->getRequestTime();
    $state = $this->state->get((string) $alert->id());
    $state->lastRun = $now;
    $type = $alert->getType();

    try {
      $reading = $type->read($context);
    }
    catch (\Throwable $e) {
      // An alert that cannot read is broken, not quiet. Leave the status
      // untouched so a later successful read still counts as a change.
      $this->logger->error('Alert @id could not take its reading: @message', [
        '@id' => $alert->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->state->save($state);
      return NULL;
    }

    // Per-row alerts report each row once, ever. Filtering before the condition
    // runs is what makes "row count is at least one" mean "there is something
    // new" rather than "there is something".
    $new_keys = [];
    if ($alert->isPerRow() && $reading->rows !== []) {
      $new_keys = $this->state->unseenRowKeys(
        (string) $alert->id(),
        array_map('strval', array_keys($reading->rows)),
      );
      $reading = $reading->only($new_keys);
    }

    $fires = $type->fires($reading);
    $message = $this->message($alert, $state, $reading, $fires, $now);

    if ($message !== NULL) {
      $state->lastFired = $now;
      $state->fireCount++;

      if ($alert->isPerRow() && $new_keys !== []) {
        $this->state->markRowsReported((string) $alert->id(), $new_keys, $now);
      }
    }

    $state->status = $fires ? AlertState::FIRING : AlertState::OK;
    $state->lastValue = (float) $reading->measure();
    $this->state->save($state);

    return $message;
  }

  /**
   * Evaluates every enabled alert that is scheduled and now due.
   *
   * @return array<string, \Drupal\klaxon\Message>
   *   Messages to deliver, keyed by alert id.
   */
  public function runDue(): array {
    $now = $this->time->getRequestTime();
    $messages = [];

    foreach ($this->enabledAlerts() as $alert) {
      $state = $this->state->get((string) $alert->id());

      $type = $alert->getType();

      if (!$type instanceof ScheduledAlertInterface || !$type->isDue($now, $state->lastRun)) {
        continue;
      }

      $message = $this->run($alert);
      if ($message !== NULL) {
        $messages[(string) $alert->id()] = $message;
      }
    }

    return $messages;
  }

  /**
   * Every alert that is currently switched on.
   *
   * @return \Drupal\klaxon\Entity\AlertInterface[]
   *   Enabled alerts, keyed by ID.
   */
  public function enabledAlerts(): array {
    $alerts = [];

    foreach ($this->entityTypeManager->getStorage('klaxon_alert')->loadMultiple() as $id => $alert) {
      if ($alert instanceof AlertInterface && $alert->status()) {
        $alerts[(string) $id] = $alert;
      }
    }

    return $alerts;
  }

  /**
   * Applies the notification policy to a decided condition.
   */
  protected function message(AlertInterface $alert, AlertState $state, Reading $reading, bool $fires, int $now): ?Message {
    if ($fires) {
      return $this->suppressed($alert, $state, $now)
        ? NULL
        : $this->renderer->render($alert, $reading, $alert->getSeverity());
    }

    // Not firing. Only a recovery message is left, and only if it was firing.
    if ($state->isFiring() && $alert->getNotifyOn() === AlertInterface::NOTIFY_CHANGE_AND_RECOVERY) {
      return $this->renderer->renderRecovery($alert, $reading);
    }

    return NULL;
  }

  /**
   * TRUE when the notification policy says to stay quiet.
   */
  protected function suppressed(AlertInterface $alert, AlertState $state, int $now): bool {
    if ($state->inCooldown($now, $alert->getCooldown())) {
      return TRUE;
    }

    // Per-row alerts are deduplicated by the ledger, one row at a time. Asking
    // them to also stay quiet while "already firing" would silence every row
    // after the first.
    if ($alert->isPerRow()) {
      return FALSE;
    }

    return match ($alert->getNotifyOn()) {
      AlertInterface::NOTIFY_CHANGE, AlertInterface::NOTIFY_CHANGE_AND_RECOVERY => $state->isFiring(),
      default => FALSE,
    };
  }

}
