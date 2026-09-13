<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\Klaxon\Transport;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Attribute\Transport;
use Drupal\klaxon\DeliveryException;
use Drupal\klaxon\Message;
use Drupal\klaxon\Transport\TransportBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Emails the alert to a fixed list of people.
 */
#[Transport(
  id: 'mail',
  label: new TranslatableMarkup('Email'),
  description: new TranslatableMarkup('Send the alert to one or more addresses.'),
  category: new TranslatableMarkup('General'),
)]
class MailTransport extends TransportBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly MailManagerInterface $mailManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('plugin.manager.mail'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'recipients' => '',
      'langcode' => LanguageInterface::LANGCODE_DEFAULT,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['recipients'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Recipients'),
      '#rows' => 3,
      '#default_value' => $this->configuration['recipients'] ?? '',
      '#description' => $this->t('One address per line, or separated by commas. Each gets its own mail, so one bad address cannot take the rest of the list down with it.'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function send(Message $message): void {
    $recipients = $this->recipients();

    if ($recipients === []) {
      throw DeliveryException::permanent('This mail channel has no recipients.');
    }

    $langcode = (string) ($this->configuration['langcode'] ?? LanguageInterface::LANGCODE_DEFAULT);

    // One mail per recipient rather than a shared To header, so one bad
    // address cannot take the rest of the list down with it.
    foreach ($recipients as $to) {
      $result = $this->mailManager->mail('klaxon', 'alert', $to, $langcode, [
        'subject' => $message->subject,
        'body' => $message->flatten(),
      ]);

      if (empty($result['result'])) {
        throw new DeliveryException(sprintf('Mail to %s was not accepted for delivery.', $to));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $recipients = $this->recipients();

    if ($recipients === []) {
      return (string) new TranslatableMarkup('Email, but no recipients are set');
    }

    return (string) new TranslatableMarkup('Email to @list', ['@list' => implode(', ', $recipients)]);
  }

  /**
   * The addresses this channel sends to.
   *
   * @return string[]
   *   Valid addresses only. Anything unparseable is dropped rather than sent.
   */
  protected function recipients(): array {
    $raw = preg_split('/[\s,;]+/', (string) ($this->configuration['recipients'] ?? '')) ?: [];

    return array_values(array_filter(
      array_map('trim', $raw),
      static fn(string $address): bool => $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== FALSE,
    ));
  }

}
