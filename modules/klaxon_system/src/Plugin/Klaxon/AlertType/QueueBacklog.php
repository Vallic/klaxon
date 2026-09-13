<?php

declare(strict_types=1);

namespace Drupal\klaxon_system\Plugin\Klaxon\AlertType;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\AlertType\ScheduledAlertBase;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon\Reading;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A queue has stopped draining.
 *
 * Queues fail quietly by design: items go in, nothing takes them out, and the
 * site carries on looking well. The mail nobody received and the search index
 * nobody reindexed both look like this, and neither shows up anywhere a person
 * would think to check.
 *
 * The threshold is per queue rather than across all of them, because that is
 * the question worth asking. A thousand items spread over twenty queues is a
 * busy site; a thousand in one is a worker that died.
 */
#[AlertType(
  id: 'system_queue',
  label: new TranslatableMarkup('Queue backlog'),
  description: new TranslatableMarkup('Fire when a queue has more items waiting than it should. Names the queues that are behind, so the message says which worker stopped.'),
  category: new TranslatableMarkup('System'),
)]
class QueueBacklog extends ScheduledAlertBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly QueueFactory $queueFactory,
    protected readonly QueueWorkerManagerInterface $queueWorkerManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'queues' => [],
      'limit' => 100,
      'interval' => 1800,
      // Not configurable: the alert is the list of queues that are behind.
      'operator' => 'not_empty',
      'value' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    unset($form['fire']);

    $form['backlog'] = [
      '#type' => 'details',
      '#title' => $this->t('What counts as a backlog'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $form['backlog']['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Complain when a queue holds more than'),
      '#field_suffix' => $this->t('items'),
      '#min' => 1,
      '#default_value' => $this->configuration['limit'] ?? 100,
      '#required' => TRUE,
      '#description' => $this->t('Counted per queue, not across all of them. A thousand items spread over twenty queues is a busy site; a thousand in one is a worker that died.'),
    ];

    $form['backlog']['queues'] = [
      '#type' => 'select',
      '#title' => $this->t('Queues'),
      '#multiple' => TRUE,
      '#options' => $this->queueOptions(),
      '#default_value' => (array) ($this->configuration['queues'] ?? []),
      '#description' => $this->t('Leave empty to watch every queue that has a worker, including ones added later.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function formSections(): array {
    return ['schedule', 'backlog'];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    $this->configuration['queues'] = array_values(array_filter((array) ($this->configuration['queues'] ?? [])));
    $this->configuration['limit'] = max(1, (int) ($this->configuration['limit'] ?? 100));
    // The alert is the list of queues that are behind, so the test is fixed.
    $this->configuration['operator'] = 'not_empty';
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $context = []): Reading {
    $limit = max(1, (int) ($this->configuration['limit'] ?? 100));
    $rows = [];

    foreach ($this->watchedQueues() as $name => $label) {
      $waiting = (int) $this->queueFactory->get($name)->numberOfItems();

      if ($waiting <= $limit) {
        continue;
      }

      $rows[$name] = [
        'label' => (string) new TranslatableMarkup('@queue: @count waiting', [
          '@queue' => $label,
          '@count' => $waiting,
        ]),
        'waiting' => $waiting,
      ];
    }

    // Sorted worst first, so a message truncated by a chat service still
    // leads with the queue most worth looking at.
    uasort($rows, static fn(array $a, array $b): int => $b['waiting'] <=> $a['waiting']);

    return Reading::fromRows($rows, ['Threshold' => (string) new TranslatableMarkup('more than @count items', ['@count' => $limit])]);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $queues = array_filter((array) ($this->configuration['queues'] ?? []));

    return (string) new TranslatableMarkup('@queues over @count items', [
      '@queues' => $queues === []
        ? (string) new TranslatableMarkup('Any queue')
        : implode(', ', $queues),
      '@count' => (int) ($this->configuration['limit'] ?? 100),
    ]);
  }

  /**
   * The queues this alert looks at, as name to label.
   */
  protected function watchedQueues(): array {
    $all = $this->queueOptions();
    $chosen = array_filter((array) ($this->configuration['queues'] ?? []));

    if ($chosen === []) {
      return $all;
    }

    $queues = [];
    foreach ($chosen as $name) {
      // A queue whose worker has since been uninstalled still has rows, and
      // is still worth reporting, so it keeps its name as its label.
      $queues[(string) $name] = $all[$name] ?? (string) $name;
    }

    return $queues;
  }

  /**
   * Every queue that has a worker, as name to label.
   */
  protected function queueOptions(): array {
    $options = [];

    foreach ($this->queueWorkerManager->getDefinitions() as $id => $definition) {
      $options[(string) $id] = (string) ($definition['title'] ?? $definition['label'] ?? $id);
    }

    natcasesort($options);

    return $options;
  }

}
