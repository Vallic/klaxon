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
 * Posts the alert into a Discord channel.
 *
 * A channel webhook, created in the channel's own settings, so as with Slack
 * the URL already decides where the message lands.
 */
#[Transport(
  id: 'discord',
  label: new TranslatableMarkup('Discord'),
  description: new TranslatableMarkup('Post into a Discord channel through a channel webhook.'),
  category: new TranslatableMarkup('Chat'),
)]
class DiscordTransport extends HttpTransportBase {

  /**
   * What Discord accepts in one embed.
   */
  protected const MAX_TITLE = 256;
  protected const MAX_DESCRIPTION = 4096;
  protected const MAX_FIELDS = 25;
  protected const MAX_FIELD_NAME = 256;
  protected const MAX_FIELD_VALUE = 1024;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'webhook_url' => '',
      'username' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['webhook_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Channel webhook URL'),
      '#default_value' => $this->configuration['webhook_url'] ?? '',
      '#required' => TRUE,
      '#placeholder' => 'https://discord.com/api/webhooks/...',
      '#description' => $this->t('From the Discord channel, under Edit Channel, Integrations, Webhooks. This is a credential and it is stored in configuration; set it in settings.php to keep it out of an exported site: <code>@override</code>', [
        '@override' => "\$config['klaxon.channel.CHANNEL_ID']['transport']['webhook_url'] = getenv('DISCORD_WEBHOOK');",
      ]),
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Post as'),
      '#default_value' => $this->configuration['username'] ?? '',
      '#description' => $this->t('Overrides the name the webhook was created with. Leave empty to keep it.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function send(Message $message): void {
    $url = (string) ($this->configuration['webhook_url'] ?? '');

    if ($url === '') {
      throw DeliveryException::permanent('This Discord channel has no webhook URL.');
    }

    $payload = ['embeds' => [$this->embed($message)]];

    if (($username = (string) ($this->configuration['username'] ?? '')) !== '') {
      $payload['username'] = $this->trim($username, 80);
    }

    $this->post($url, $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    if ((string) ($this->configuration['webhook_url'] ?? '') === '') {
      return (string) new TranslatableMarkup('Discord, but no webhook is set');
    }

    // The path is the credential, so the summary says the service and stops.
    return (string) new TranslatableMarkup('Discord webhook');
  }

  /**
   * {@inheritdoc}
   */
  protected function failure(int $status, string $body, ?string $retry_after): DeliveryException {
    $decoded = json_decode($body, TRUE);

    // Discord answers a rate limit with seconds in the body, often fractional.
    if ($status === 429 && is_array($decoded) && isset($decoded['retry_after'])) {
      return DeliveryException::rateLimited(
        'Discord asked us to slow down.',
        max(1, (int) ceil((float) $decoded['retry_after'])),
      );
    }

    return parent::failure($status, $body, $retry_after);
  }

  /**
   * The message as one Discord embed.
   */
  protected function embed(Message $message): array {
    $embed = [
      'title' => $this->trim($message->subject, self::MAX_TITLE),
      'color' => (int) hexdec($this->colour($message->severity)),
      'timestamp' => gmdate('c'),
    ];

    if ($message->body !== '') {
      $embed['description'] = $this->trim($message->body, self::MAX_DESCRIPTION);
    }

    if ($message->url !== NULL) {
      $embed['url'] = $message->url;
    }

    $fields = [];
    foreach (array_slice($message->facts, 0, self::MAX_FIELDS, TRUE) as $label => $value) {
      $text = trim((string) $value);

      $fields[] = [
        'name' => $this->trim((string) $label, self::MAX_FIELD_NAME),
        // Discord rejects an embed field with an empty value outright, and a
        // fact with nothing in it is worth showing as nothing rather than
        // losing the whole message over.
        'value' => $text === '' ? '—' : $this->trim($text, self::MAX_FIELD_VALUE),
        'inline' => TRUE,
      ];
    }

    if ($fields !== []) {
      $embed['fields'] = $fields;
    }

    return $embed;
  }

}
