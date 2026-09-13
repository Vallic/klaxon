<?php

declare(strict_types=1);

namespace Drupal\klaxon\Alert;

/**
 * What an alert did last time it was evaluated.
 */
final class AlertState {

  public const OK = 'ok';
  public const FIRING = 'firing';

  public function __construct(
    public readonly string $alertId,
    public ?int $lastRun = NULL,
    public ?int $lastFired = NULL,
    public ?float $lastValue = NULL,
    public string $status = self::OK,
    public int $fireCount = 0,
  ) {}

  /**
   * TRUE when the alert was firing at the last evaluation.
   */
  public function isFiring(): bool {
    return $this->status === self::FIRING;
  }

  /**
   * TRUE when the alert is still inside its quiet period.
   */
  public function inCooldown(int $now, int $cooldown): bool {
    return $cooldown > 0 && $this->lastFired !== NULL && ($this->lastFired + $cooldown) > $now;
  }

}
