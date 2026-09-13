<?php

declare(strict_types=1);

namespace Drupal\klaxon\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\klaxon\Alert\Deliverer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * How Klaxon behaves when delivery goes wrong.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly QueueFactory $queueFactory,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'klaxon_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['klaxon.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('klaxon.settings');

    $queue = $this->queueFactory->get(Deliverer::QUEUE);
    $waiting = $queue instanceof QueueInterface ? $queue->numberOfItems() : 0;

    $form['queue'] = [
      '#type' => 'item',
      '#title' => $this->t('Waiting to be delivered'),
      '#markup' => $this->formatPlural($waiting, '1 message', '@count messages'),
      '#description' => $this->t('Alerts are delivered on cron so that nothing waits on a mail server or a chat API. A number that never comes down usually means cron is not running.'),
    ];

    $form['max_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Give up after'),
      '#field_suffix' => $this->t('attempts'),
      '#min' => 1,
      '#max' => 20,
      '#default_value' => $config->get('max_attempts') ?? 3,
      '#description' => $this->t('Each retry happens on a later cron run. Failures that retrying cannot fix, such as a channel with no recipients, are not retried at all.'),
    ];

    $form['retry_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Wait before retrying'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 1,
      '#default_value' => $config->get('retry_delay') ?? 60,
      '#description' => $this->t('Doubles with each attempt, up to an hour. Without a wait, every attempt would happen in the same second, because cron empties the queue in one pass.'),
    ];

    $options = ['' => $this->t('- Do not tell anyone -')];
    foreach ($this->entityTypeManager->getStorage('klaxon_channel')->loadMultiple() as $id => $channel) {
      $options[$id] = $channel->label();
    }

    $form['fallback_channel'] = [
      '#type' => 'select',
      '#title' => $this->t('When delivery fails for good, say so on'),
      '#options' => $options,
      '#default_value' => $config->get('fallback_channel') ?? '',
      '#description' => $this->t('Alerting that breaks needs somewhere to say so. Pick the channel least likely to be the one that failed, which usually means email.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('klaxon.settings')
      ->set('max_attempts', (int) $form_state->getValue('max_attempts'))
      ->set('retry_delay', (int) $form_state->getValue('retry_delay'))
      ->set('fallback_channel', (string) $form_state->getValue('fallback_channel'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
