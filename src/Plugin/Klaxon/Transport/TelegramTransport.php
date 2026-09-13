<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\Klaxon\Transport;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Attribute\Transport;
use Drupal\klaxon\DeliveryException;
use Drupal\klaxon\Message;
use Drupal\klaxon\Transport\HttpTransportBase;

/**
 * Sends the alert to a Telegram chat, group or channel.
 *
 * A bot token and a chat id. The bot has to be in the chat first, and for a
 * channel it has to be an administrator of it, which is the one part of this
 * nobody gets right the first time.
 */
#[Transport(
  id: 'telegram',
  label: new TranslatableMarkup('Telegram'),
  description: new TranslatableMarkup('Send to a Telegram chat, group or channel through a bot.'),
  category: new TranslatableMarkup('Chat'),
)]
class TelegramTransport extends HttpTransportBase {

  /**
   * Telegram rejects a message body longer than this.
   */
  protected const MAX_LENGTH = 4096;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'token' => '',
      'chat_id' => '',
      'silent' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bot token'),
      '#default_value' => $this->configuration['token'] ?? '',
      '#required' => TRUE,
      '#placeholder' => '123456789:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
      '#description' => $this->t('From BotFather. This is a credential and it is stored in configuration; set it in settings.php to keep it out of an exported site: <code>@override</code>', [
        '@override' => "\$config['klaxon.channel.CHANNEL_ID']['transport']['token'] = getenv('TELEGRAM_TOKEN');",
      ]),
    ];

    $form['chat_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Chat ID'),
      '#default_value' => $this->configuration['chat_id'] ?? '',
      '#required' => TRUE,
      '#placeholder' => '-1001234567890',
      '#description' => $this->t('A number for a person or group, or %handle for a public channel. The bot must already be in the chat, and an administrator of it if it is a channel.', [
        '%handle' => '@channelname',
      ]),
    ];

    $form['silent'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Deliver without a notification sound'),
      '#default_value' => (bool) ($this->configuration['silent'] ?? FALSE),
      '#description' => $this->t('For an alert people should see in the morning rather than at three.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function send(Message $message): void {
    $token = (string) ($this->configuration['token'] ?? '');
    $chat_id = (string) ($this->configuration['chat_id'] ?? '');

    if ($token === '' || $chat_id === '') {
      throw DeliveryException::permanent('This Telegram channel is missing its token or chat ID.');
    }

    $body = $this->post(sprintf('https://api.telegram.org/bot%s/sendMessage', $token), [
      'chat_id' => $chat_id,
      'text' => $this->text($message),
      'parse_mode' => 'HTML',
      'disable_web_page_preview' => TRUE,
      'disable_notification' => (bool) ($this->configuration['silent'] ?? FALSE),
    ]);

    // Telegram answers 200 with ok: false for some refusals, so the status
    // code alone is not enough to call it delivered.
    if (($body['ok'] ?? TRUE) === FALSE) {
      throw DeliveryException::permanent(sprintf(
        'Telegram refused the message: %s',
        $this->trim((string) ($body['description'] ?? 'no reason given'), 200),
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $chat_id = (string) ($this->configuration['chat_id'] ?? '');

    if ($chat_id === '' || (string) ($this->configuration['token'] ?? '') === '') {
      return (string) new TranslatableMarkup('Telegram, but it is not configured');
    }

    return (string) new TranslatableMarkup('Telegram chat @id', ['@id' => $chat_id]);
  }

  /**
   * {@inheritdoc}
   */
  protected function failure(int $status, string $body, ?string $retry_after): DeliveryException {
    $decoded = json_decode($body, TRUE);

    // Telegram puts the wait in the body rather than in a header.
    if ($status === 429 && is_array($decoded)) {
      $seconds = (int) ($decoded['parameters']['retry_after'] ?? 0);

      if ($seconds > 0) {
        return DeliveryException::rateLimited('Telegram asked us to slow down.', $seconds);
      }
    }

    return parent::failure($status, $body, $retry_after);
  }

  /**
   * The message as the limited HTML Telegram accepts.
   */
  protected function text(Message $message): string {
    $lines = ['<b>' . $this->escape($message->subject) . '</b>'];

    if ($message->body !== '') {
      $lines[] = '';
      $lines[] = $this->escape($message->body);
    }

    if ($message->facts !== []) {
      $lines[] = '';

      foreach ($message->facts as $label => $value) {
        $lines[] = sprintf('<b>%s:</b> %s', $this->escape((string) $label), $this->escape((string) $value));
      }
    }

    if ($message->url !== NULL) {
      $lines[] = '';
      $lines[] = sprintf('<a href="%s">%s</a>', $this->escape($message->url), 'Open');
    }

    return $this->trim(implode("\n", $lines), self::MAX_LENGTH);
  }

  /**
   * Escapes the characters Telegram's HTML mode treats as markup.
   */
  protected function escape(string $text): string {
    return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
  }

}
