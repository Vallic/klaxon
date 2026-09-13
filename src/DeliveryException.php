<?php

declare(strict_types=1);

namespace Drupal\klaxon;

/**
 * A transport could not deliver.
 *
 * Two things separate the failures worth retrying from the ones that are not.
 * A permanent failure is one no amount of waiting fixes, such as a channel with
 * no recipients configured; retrying it just fills the log. A rate-limited
 * failure carries the number of seconds the far end asked us to wait, and stops
 * the whole queue for the rest of the run rather than hammering it.
 */
class DeliveryException extends \RuntimeException {

  public function __construct(
    string $message,
    protected readonly bool $permanent = FALSE,
    protected readonly ?int $retryAfter = NULL,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * A failure that retrying cannot fix.
   *
   * @param string $message
   *   What went wrong.
   * @param \Throwable|null $previous
   *   The underlying error, when there is one.
   */
  public static function permanent(string $message, ?\Throwable $previous = NULL): self {
    return new self($message, TRUE, NULL, $previous);
  }

  /**
   * The far end asked us to slow down.
   *
   * @param string $message
   *   What the far end said.
   * @param int $seconds
   *   How long it wants us to wait.
   */
  public static function rateLimited(string $message, int $seconds): self {
    return new self($message, FALSE, max(1, $seconds));
  }

  /**
   * TRUE when retrying this delivery is pointless.
   */
  public function isPermanent(): bool {
    return $this->permanent;
  }

  /**
   * Seconds the far end asked us to wait, or NULL if it did not say.
   */
  public function getRetryAfter(): ?int {
    return $this->retryAfter;
  }

}
