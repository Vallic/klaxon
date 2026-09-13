<?php

declare(strict_types=1);

namespace Drupal\klaxon;

/**
 * A rendered alert, ready for any transport to deliver.
 *
 * Transports receive plain text and an optional richer body plus a list of
 * facts, and each renders what it can. A transport never sees the source, the
 * reading or the alert configuration, so adding a source never touches a
 * transport and the reverse holds too.
 */
final class Message {

  public const SEVERITY_INFO = 'info';
  public const SEVERITY_WARNING = 'warning';
  public const SEVERITY_CRITICAL = 'critical';
  public const SEVERITY_RECOVERED = 'recovered';

  /**
   * Builds a rendered alert.
   *
   * @param string $subject
   *   One line. Used as the mail subject and the notification title.
   * @param string $body
   *   Plain text body. Always populated; transports that cannot render
   *   anything richer use this.
   * @param array $facts
   *   Label to value pairs rendered as a table or a field list.
   * @param string $severity
   *   One of the SEVERITY_* constants.
   * @param string|null $url
   *   Absolute URL to the thing the alert is about, when there is one.
   */
  public function __construct(
    public readonly string $subject,
    public readonly string $body,
    public readonly array $facts = [],
    public readonly string $severity = self::SEVERITY_WARNING,
    public readonly ?string $url = NULL,
  ) {}

  /**
   * The message as plain data, for anything that cannot carry an object.
   *
   * A queue backend is under no obligation to preserve PHP objects. The core
   * database queue serializes and they survive; RabbitMQ and several others
   * encode as JSON and they do not, coming back as bare arrays. A message is
   * only ever scalars, so traveling as scalars costs nothing and works
   * everywhere.
   */
  public function toArray(): array {
    return [
      'subject' => $this->subject,
      'body' => $this->body,
      'facts' => $this->facts,
      'severity' => $this->severity,
      'url' => $this->url,
    ];
  }

  /**
   * Rebuilds a message from whatever came back out of a queue.
   */
  public static function fromArray(array $data): self {
    $facts = [];

    foreach ((array) ($data['facts'] ?? []) as $label => $value) {
      if (is_scalar($value)) {
        $facts[(string) $label] = (string) $value;
      }
    }

    return new self(
      (string) ($data['subject'] ?? ''),
      (string) ($data['body'] ?? ''),
      $facts,
      (string) ($data['severity'] ?? self::SEVERITY_WARNING),
      isset($data['url']) && $data['url'] !== '' ? (string) $data['url'] : NULL,
    );
  }

  /**
   * The body with the facts appended, for transports without a fact concept.
   */
  public function flatten(): string {
    if ($this->facts === []) {
      return $this->body;
    }

    $lines = [];
    foreach ($this->facts as $label => $value) {
      $lines[] = sprintf('%s: %s', $label, $value);
    }

    return $this->body . "\n\n" . implode("\n", $lines);
  }

}
