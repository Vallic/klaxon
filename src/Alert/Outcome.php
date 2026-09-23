<?php

declare(strict_types=1);

namespace Drupal\klaxon\Alert;

use Drupal\klaxon\Message;
use Drupal\klaxon\Reading;

/**
 * What came of running an alert once, and why.
 *
 * An alert that says nothing is the normal case, and until this the only way
 * to tell a threshold that was not crossed from a cooldown, a repeat it was
 * told not to make, or an alert with nowhere to send was to read the code.
 * Running one by hand now says which of them it was.
 */
final class Outcome {

  /**
   * There is no such alert.
   */
  public const UNKNOWN = 'unknown';

  /**
   * The alert is switched off.
   */
  public const DISABLED = 'disabled';

  /**
   * The alert type could not read; the failure is in the log.
   */
  public const UNREADABLE = 'unreadable';

  /**
   * The reading did not cross the line the alert watches for.
   */
  public const NOT_FIRING = 'not_firing';

  /**
   * It is firing, and was told to speak only when that changes.
   */
  public const ALREADY_FIRING = 'already_firing';

  /**
   * It is firing, and within the quiet spell that follows a fire.
   */
  public const COOLDOWN = 'cooldown';

  /**
   * It had something to say and no channel to say it to.
   */
  public const NO_CHANNELS = 'no_channels';

  /**
   * It fired and the message went out.
   */
  public const DELIVERED = 'delivered';

  public function __construct(
    public readonly string $reason,
    public readonly ?Message $message = NULL,
    public readonly ?Reading $reading = NULL,
    public readonly int $delivered = 0,
    public readonly int $silentFor = 0,
  ) {}

  /**
   * The same outcome, with what delivery made of it.
   *
   * @param int $delivered
   *   How many channels took the message.
   *
   * @return self
   *   The outcome to report.
   */
  public function delivered(int $delivered): self {
    return new self(
      $delivered > 0 ? self::DELIVERED : self::NO_CHANNELS,
      $this->message,
      $this->reading,
      $delivered,
    );
  }

  /**
   * Whether the alert spoke.
   */
  public function spoke(): bool {
    return $this->reason === self::DELIVERED;
  }

}
