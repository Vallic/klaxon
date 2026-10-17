<?php

declare(strict_types=1);

namespace Drupal\klaxon\Alert;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\Token;
use Drupal\klaxon\Entity\AlertInterface;
use Drupal\klaxon\Message;
use Drupal\klaxon\Reading;

/**
 * Turns an alert and its reading into one message every transport can render.
 */
class MessageRenderer {

  use StringTranslationTrait;

  /**
   * How many rows are listed before the message says "and N more".
   */
  public const MAX_LISTED_ROWS = 20;

  public function __construct(
    protected readonly Token $token,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Builds the message for an alert that is firing.
   */
  public function render(AlertInterface $alert, Reading $reading, string $severity): Message {
    $data = $this->tokenData($reading);

    $subject = $this->replace($alert->getSubjectTemplate(), $alert, $reading, $data);
    $body = $this->replace($alert->getBodyTemplate(), $alert, $reading, $data);

    if (trim($body) === '') {
      $body = $this->defaultBody($alert, $reading);
    }

    return new Message(
      subject: $subject !== '' ? $subject : $alert->label(),
      body: $body,
      facts: $this->facts($reading),
      severity: $severity,
      url: $reading->context['url'] ?? NULL,
    );
  }

  /**
   * The message sent when an alert stops firing.
   */
  public function renderRecovery(AlertInterface $alert, Reading $reading): Message {
    return new Message(
      subject: $this->plain($this->t('Recovered: @label', ['@label' => $alert->label()])),
      body: $this->plain($this->t('@label is back to normal. Current value: @value.', [
        '@label' => $alert->label(),
        '@value' => $this->formatMeasure($reading->measure()),
      ])),
      facts: $this->facts($reading),
      severity: Message::SEVERITY_RECOVERED,
    );
  }

  /**
   * Substitutes the Klaxon placeholders, then any entity tokens.
   */
  protected function replace(string $template, AlertInterface $alert, Reading $reading, array $data): string {
    if ($template === '') {
      return '';
    }

    // Read as HTML, the way token replacement writes its values: what Klaxon
    // puts in is escaped to match, and the whole is made plain text after.
    $template = strtr($template, [
      '[klaxon:label]' => Html::escape((string) $alert->label()),
      '[klaxon:value]' => $this->formatMeasure($reading->measure()),
      '[klaxon:count]' => (string) count($reading->rows),
      '[klaxon:rows]' => Html::escape($this->rowList($reading)),
    ]);

    // Entity tokens, so a message can name the thing it is about.
    return $this->plain($this->token->replace($template, $data, ['clear' => TRUE]));
  }

  /**
   * The body used when the alert carries no template.
   */
  protected function defaultBody(AlertInterface $alert, Reading $reading): string {
    if ($reading->rows !== []) {
      return $this->plain($this->t("@label matched @count.\n\n@rows", [
        '@label' => $alert->label(),
        '@count' => count($reading->rows),
        '@rows' => $this->rowList($reading),
      ]));
    }

    return $this->plain($this->t('@label is at @value.', [
      '@label' => $alert->label(),
      '@value' => $this->formatMeasure($reading->measure()),
    ]));
  }

  /**
   * The matched rows as a plain text list, truncated when long.
   */
  protected function rowList(Reading $reading): string {
    if ($reading->rows === []) {
      return '';
    }

    $lines = [];
    foreach (array_slice($reading->rows, 0, self::MAX_LISTED_ROWS, TRUE) as $key => $row) {
      $lines[] = '- ' . $this->describeRow($key, $row);
    }

    $remaining = count($reading->rows) - self::MAX_LISTED_ROWS;
    if ($remaining > 0) {
      $lines[] = $this->plain($this->t('and @count more', ['@count' => $remaining]));
    }

    return implode("\n", $lines);
  }

  /**
   * One row rendered as a single readable line.
   */
  protected function describeRow(int|string $key, mixed $row): string {
    if (is_scalar($row)) {
      return (string) $row;
    }

    if (is_array($row)) {
      if (isset($row['label'])) {
        return (string) $row['label'];
      }

      $parts = [];
      foreach ($row as $label => $value) {
        if (is_scalar($value)) {
          $parts[] = sprintf('%s: %s', $label, $value);
        }
      }

      if ($parts !== []) {
        return implode(', ', $parts);
      }
    }

    return (string) $key;
  }

  /**
   * The scalar context values, as label and value pairs.
   */
  protected function facts(Reading $reading): array {
    $facts = [];
    foreach ($reading->context as $label => $value) {
      if (is_scalar($value)) {
        $facts[$label] = (string) $value;
      }
    }

    return $facts;
  }

  /**
   * Entities in context, keyed by type for the token service.
   */
  protected function tokenData(Reading $reading): array {
    $data = [];
    foreach ($reading->context as $value) {
      if ($value instanceof EntityInterface) {
        $data[$value->getEntityTypeId()] = $value;
      }
    }

    return $data;
  }

  /**
   * A measured number formatted for display.
   */
  protected function formatMeasure(int|float $measure): string {
    return is_float($measure) && fmod($measure, 1.0) !== 0.0
      ? number_format($measure, 2)
      : number_format((float) $measure);
  }

  /**
   * A message as the plain text a channel is sent.
   *
   * What `t()` and token replacement return is HTML: a value carried by an
   * `@` placeholder or a token is escaped for a page. Every channel takes
   * plain text and escapes for its own format — Slack its three characters,
   * Telegram its HTML, the mail formatter what it converts — so sent as it
   * came, an apostrophe reached Slack and Telegram as `&#039;`.
   */
  protected function plain(\Stringable|string $markup): string {
    return PlainTextOutput::renderFromHtml((string) $markup);
  }

}
