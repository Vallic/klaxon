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
 * Posts the alert into a Slack channel.
 *
 * An incoming webhook, not a bot token. A webhook URL already names the channel
 * it posts to, which is exactly what a Klaxon channel is, so the two line up
 * one to one and there is nothing else to configure.
 */
#[Transport(
  id: 'slack',
  label: new TranslatableMarkup('Slack'),
  description: new TranslatableMarkup('Post into a Slack channel through an incoming webhook.'),
  category: new TranslatableMarkup('Chat'),
)]
class SlackTransport extends HttpTransportBase {

  /**
   * Slack renders at most ten fields in one section.
   */
  protected const MAX_FIELDS = 10;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['webhook_url' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['webhook_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Incoming webhook URL'),
      '#default_value' => $this->configuration['webhook_url'] ?? '',
      '#required' => TRUE,
      '#placeholder' => 'https://hooks.slack.com/services/...',
      '#description' => $this->t('From the Slack app that owns the channel, under Incoming Webhooks. The URL decides which channel this goes to, so make one channel here per Slack channel.'),
    ];

    $form['secret'] = [
      '#type' => 'item',
      '#markup' => $this->t('This URL is a credential and it is stored in configuration. To keep it out of an exported site, set it in settings.php instead: <code>@override</code>', [
        '@override' => "\$config['klaxon.channel.CHANNEL_ID']['transport']['webhook_url'] = getenv('SLACK_WEBHOOK');",
      ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function send(Message $message): void {
    $url = (string) ($this->configuration['webhook_url'] ?? '');

    if ($url === '') {
      throw DeliveryException::permanent('This Slack channel has no webhook URL.');
    }

    $this->post($url, [
      // The fallback shown in notifications and by clients that cannot render
      // blocks. Slack requires it even when blocks are present.
      'text' => $this->trim($message->subject, 3000),
      'attachments' => [
        [
          'color' => '#' . $this->colour($message->severity),
          'blocks' => $this->blocks($message),
        ],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $url = (string) ($this->configuration['webhook_url'] ?? '');

    if ($url === '') {
      return (string) new TranslatableMarkup('Slack, but no webhook is set');
    }

    // The path is the credential, so the summary says the service and stops.
    return (string) new TranslatableMarkup('Slack webhook');
  }

  /**
   * The message as Slack blocks.
   */
  protected function blocks(Message $message): array {
    $blocks = [
      [
        'type' => 'header',
        'text' => [
          'type' => 'plain_text',
          // Slack rejects a header longer than 150 characters outright.
          'text' => $this->trim($message->subject, 150),
          'emoji' => TRUE,
        ],
      ],
    ];

    if ($message->body !== '') {
      $blocks[] = [
        'type' => 'section',
        'text' => [
          'type' => 'mrkdwn',
          'text' => $this->trim($message->body, 3000),
        ],
      ];
    }

    $fields = [];
    foreach (array_slice($message->facts, 0, self::MAX_FIELDS, TRUE) as $label => $value) {
      $fields[] = [
        'type' => 'mrkdwn',
        'text' => sprintf("*%s*\n%s", $this->escape((string) $label), $this->escape((string) $value)),
      ];
    }

    if ($fields !== []) {
      $blocks[] = ['type' => 'section', 'fields' => $fields];
    }

    if ($message->url !== NULL) {
      $blocks[] = [
        'type' => 'context',
        'elements' => [
          ['type' => 'mrkdwn', 'text' => sprintf('<%s|%s>', $message->url, 'Open')],
        ],
      ];
    }

    return $blocks;
  }

  /**
   * Escapes the three characters Slack treats as markup.
   */
  protected function escape(string $text): string {
    return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
  }

}
