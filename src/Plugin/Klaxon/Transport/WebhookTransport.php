<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\Klaxon\Transport;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Attribute\Transport;
use Drupal\klaxon\DeliveryException;
use Drupal\klaxon\Message;
use Drupal\klaxon\Transport\HttpTransportBase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Posts the alert as JSON to any URL.
 *
 * The escape hatch for everything with an HTTP endpoint and no plugin of its
 * own: an internal service, an automation tool, an on-call system, a chat
 * product nobody here has heard of.
 *
 * The payload is a documented, stable shape rather than something shaped like
 * one service's API, because the receiver is the thing that can be changed.
 * Anything that needs a payload shaped its way wants its own transport plugin,
 * which is thirty lines on top of the same base class this uses.
 */
#[Transport(
  id: 'webhook',
  label: new TranslatableMarkup('Webhook'),
  description: new TranslatableMarkup('POST the alert as JSON to any URL, with whatever headers the far end wants for authentication.'),
  category: new TranslatableMarkup('General'),
)]
class WebhookTransport extends HttpTransportBase {

  /**
   * The header carrying the signature, when a signing secret is configured.
   */
  public const SIGNATURE_HEADER = 'X-Klaxon-Signature';

  /**
   * The header carrying the timestamp the signature covers.
   */
  public const TIMESTAMP_HEADER = 'X-Klaxon-Timestamp';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ClientInterface $http_client,
    protected readonly TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $http_client);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'url' => '',
      'method' => 'POST',
      'headers' => '',
      'secret' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL'),
      '#default_value' => $this->configuration['url'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Where to post. Anyone who can configure a channel can make the site request any URL it can reach, which is why configuring channels is a restricted permission.'),
    ];

    $form['method'] = [
      '#type' => 'select',
      '#title' => $this->t('Method'),
      // Protocol tokens, not prose. Translating them would be wrong.
      // phpcs:disable DrupalPractice.General.OptionsT.TforValue
      '#options' => [
        'POST' => 'POST',
        'PUT' => 'PUT',
        'PATCH' => 'PATCH',
      ],
      // phpcs:enable DrupalPractice.General.OptionsT.TforValue
      '#default_value' => $this->configuration['method'] ?? 'POST',
    ];

    $form['headers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Headers'),
      '#rows' => 4,
      '#default_value' => $this->configuration['headers'] ?? '',
      '#placeholder' => "Authorization: Bearer abc123\nX-Api-Key: xyz",
      '#description' => $this->t('One per line, as <code>Name: value</code>. This is where authentication goes. Header values are credentials and are stored in configuration; set them in settings.php to keep them out of an exported site: <code>@override</code>', [
        '@override' => "\$config['klaxon.channel.CHANNEL_ID']['transport']['headers'] = 'Authorization: Bearer ' . getenv('HOOK_TOKEN');",
      ]),
    ];

    $form['secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Signing secret'),
      '#default_value' => $this->configuration['secret'] ?? '',
      '#description' => $this->t('Optional. When set, each request carries a <code>@signature</code> header holding an HMAC-SHA256 of the timestamp and body, so the receiver can tell a real alert from anyone who guessed the URL.', [
        '@signature' => self::SIGNATURE_HEADER,
      ]),
    ];

    $form['payload'] = [
      '#type' => 'details',
      '#title' => $this->t('What gets sent'),
      '#open' => FALSE,
    ];

    $form['payload']['shape'] = [
      '#type' => 'inline_template',
      '#template' => '<pre>{{ example }}</pre>',
      '#context' => [
        'example' => json_encode([
          'subject' => 'No orders in 30 minutes',
          'body' => 'Nothing has been placed since 14:02.',
          'severity' => 'critical',
          'facts' => ['Window' => 'between -30 minutes and now'],
          'url' => NULL,
          'sent' => '2026-09-12T14:32:00+00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);

    foreach ($this->lines((string) $form_state->getValue('headers')) as $line) {
      if (!str_contains($line, ':')) {
        $form_state->setError($form['headers'], $this->t('Each header needs a name and a value separated by a colon. This one has neither: %line', ['%line' => $line]));
        return;
      }

      // A newline smuggled into a value lets one header become several, which
      // is how a header injection works. Nothing legitimate needs it.
      if (preg_match('/[\r\n]/', $line) === 1) {
        $form_state->setError($form['headers'], $this->t('Header values cannot contain line breaks.'));
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function send(Message $message): void {
    $url = (string) ($this->configuration['url'] ?? '');

    if ($url === '') {
      throw DeliveryException::permanent('This webhook channel has no URL.');
    }

    $body = static::encode([
      'subject' => $message->subject,
      'body' => $message->body,
      'severity' => $message->severity,
      'facts' => (object) $message->facts,
      'url' => $message->url,
      'sent' => gmdate('c', $this->time->getRequestTime()),
    ]);

    $this->request(
      (string) ($this->configuration['method'] ?? 'POST'),
      $url,
      $body,
      $this->headers($body),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $url = (string) ($this->configuration['url'] ?? '');

    if ($url === '') {
      return (string) new TranslatableMarkup('Webhook, but no URL is set');
    }

    // Host only: a webhook URL's path is frequently the credential.
    return (string) new TranslatableMarkup('@method to @host', [
      '@method' => $this->configuration['method'] ?? 'POST',
      '@host' => parse_url($url, PHP_URL_HOST) ?: $url,
    ]);
  }

  /**
   * The headers to send, including authentication and any signature.
   *
   * @param string $body
   *   The exact bytes being sent, so the signature covers them and not a
   *   second encoding of the same array that might differ by a slash.
   */
  protected function headers(string $body): array {
    $headers = [];

    foreach ($this->lines((string) ($this->configuration['headers'] ?? '')) as $line) {
      [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
      $name = trim($name);

      if ($name !== '') {
        $headers[$name] = trim($value);
      }
    }

    $secret = (string) ($this->configuration['secret'] ?? '');

    if ($secret !== '') {
      $timestamp = (string) $this->time->getRequestTime();

      // The timestamp is signed alongside the body so a receiver can reject a
      // replayed request rather than only a forged one.
      $headers[self::TIMESTAMP_HEADER] = $timestamp;
      $headers[self::SIGNATURE_HEADER] = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    return $headers;
  }

  /**
   * The non-empty lines of a textarea.
   *
   * @return string[]
   *   Trimmed lines, with the blank ones dropped.
   */
  protected function lines(string $text): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
  }

}
