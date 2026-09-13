<?php

declare(strict_types=1);

namespace Drupal\klaxon\AlertType;

/**
 * An alert type cron evaluates on a schedule.
 */
interface ScheduledAlertInterface extends AlertTypeInterface {

  /**
   * TRUE when cron should evaluate the alert now.
   *
   * @param int $now
   *   The current request time.
   * @param int|null $last_run
   *   When this alert last ran, or NULL if it never has.
   */
  public function isDue(int $now, ?int $last_run): bool;

}
