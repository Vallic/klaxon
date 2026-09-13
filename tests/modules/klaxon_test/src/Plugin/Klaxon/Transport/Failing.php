<?php

declare(strict_types=1);

namespace Drupal\klaxon_test\Plugin\Klaxon\Transport;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Attribute\Transport;
use Drupal\klaxon\DeliveryException;
use Drupal\klaxon\Message;
use Drupal\klaxon\Transport\TransportBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fails on demand, and counts how often it was asked to deliver.
 */
#[Transport(
  id: 'failing',
  label: new TranslatableMarkup('Fails on demand (testing)'),
  description: new TranslatableMarkup('Records every delivery attempt and fails the way you tell it to.'),
)]
class Failing extends TransportBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * State key holding the number of delivery attempts seen.
   */
  public const ATTEMPTS = 'klaxon_test.attempts';

  /**
   * State key holding the subjects actually delivered.
   */
  public const DELIVERED = 'klaxon_test.delivered';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('state'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['mode' => 'ok'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Behaviour'),
      '#options' => [
        'ok' => $this->t('Deliver'),
        'transient' => $this->t('Fail, retryable'),
        'permanent' => $this->t('Fail, permanent'),
        'rate_limit' => $this->t('Ask to slow down'),
      ],
      '#default_value' => $this->configuration['mode'] ?? 'ok',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function send(Message $message): void {
    $this->state->set(self::ATTEMPTS, ((int) $this->state->get(self::ATTEMPTS, 0)) + 1);

    match ((string) ($this->configuration['mode'] ?? 'ok')) {
      'transient' => throw new DeliveryException('Temporary failure.'),
      'permanent' => throw DeliveryException::permanent('Permanent failure.'),
      'rate_limit' => throw DeliveryException::rateLimited('Slow down.', 30),
      default => $this->record($message),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) $this->t('Test transport, mode @mode', ['@mode' => $this->configuration['mode'] ?? 'ok']);
  }

  /**
   * Notes a successful delivery so a test can assert on it.
   */
  protected function record(Message $message): void {
    $delivered = (array) $this->state->get(self::DELIVERED, []);
    $delivered[] = $message->subject;
    $this->state->set(self::DELIVERED, $delivered);
  }

}
