<?php

declare(strict_types=1);

namespace Drupal\klaxon\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\klaxon\AlertType\AlertTypeInterface;

/**
 * One thing worth knowing about, and what to do when it becomes true.
 */
interface AlertInterface extends ConfigEntityInterface {

  /**
   * Send every time the condition holds.
   */
  public const NOTIFY_EVERY = 'every';

  /**
   * Send only when the alert crosses from quiet into firing.
   */
  public const NOTIFY_CHANGE = 'change';

  /**
   * As NOTIFY_CHANGE, plus a message when it returns to quiet.
   */
  public const NOTIFY_CHANGE_AND_RECOVERY = 'change_and_recovery';

  /**
   * The plugin deciding when this alert runs, what it reads and when it fires.
   */
  public function getType(): AlertTypeInterface;

  /**
   * The alert type plugin ID.
   */
  public function getTypeId(): string;

  /**
   * The channel ids this alert delivers to.
   *
   * @return string[]
   *   Channel entity IDs.
   */
  public function getChannelIds(): array;

  /**
   * One of the NOTIFY_* constants.
   */
  public function getNotifyOn(): string;

  /**
   * Seconds to stay silent after firing. Zero disables the cooldown.
   */
  public function getCooldown(): int;

  /**
   * TRUE when this alert reports each matching row at most once, ever.
   *
   * A subscription renewing within the hour must be announced once, not on
   * every cron run for the rest of that hour. With this on, rows already recorded in
   * the ledger are dropped before the condition is evaluated.
   */
  public function isPerRow(): bool;

  /**
   * The severity every message from this alert carries.
   */
  public function getSeverity(): string;

  /**
   * The subject template, falling back to the label.
   */
  public function getSubjectTemplate(): string;

  /**
   * The body template. Empty means build a default body.
   */
  public function getBodyTemplate(): string;

}
