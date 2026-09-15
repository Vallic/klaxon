<?php

declare(strict_types=1);

namespace Drupal\klaxon_advancedqueue\Plugin\Klaxon\AlertType;

use Drupal\advancedqueue\Entity\QueueInterface;
use Drupal\advancedqueue\Job;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\AlertType\ScheduledAlertBase;
use Drupal\klaxon\Attribute\AlertType;
use Drupal\klaxon\Reading;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Advanced Queue jobs piling up in one state.
 *
 * Core's queue has one number: how many items are waiting. Advanced Queue
 * keeps a job after it has run, with a state, and each state going wrong
 * means something different:
 *
 * - queued climbing is a worker that is not running, or not keeping up.
 * - failure climbing is a worker that runs and is wrong. Nothing retries
 *   these on their own, so the pile only grows.
 * - processing climbing is worse than either: a job is claimed, the process
 *   that claimed it died, and the job is now neither done nor waiting.
 *
 * So the state is the question, not an afterthought — which is why this is
 * separate from the plain queue backlog alert in klaxon_system.
 *
 * The threshold is per queue, because that is what tells you which worker to
 * look at. Five hundred jobs spread over twenty queues is a busy site; five
 * hundred in one is a broken one.
 */
#[AlertType(
  id: 'advancedqueue_jobs',
  label: new TranslatableMarkup('Advanced Queue jobs by state'),
  description: new TranslatableMarkup('Fire when a queue holds more jobs in a given state than it should — waiting, failed, or stuck mid-flight. Names the queues that are over, so the message says which worker to look at.'),
  category: new TranslatableMarkup('System'),
)]
class QueueJobs extends ScheduledAlertBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'queues' => [],
      // Waiting work is the one every site wants watched first.
      'states' => [Job::STATE_QUEUED],
      'limit' => 100,
      'interval' => 1800,
      // The alert is the list of queues that are over, so the test is fixed.
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

    $form['jobs'] = [
      '#type' => 'details',
      '#title' => $this->t('What counts as too many'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    $form['jobs']['states'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Job states'),
      '#options' => $this->stateOptions(),
      '#default_value' => (array) ($this->configuration['states'] ?? [Job::STATE_QUEUED]),
      '#required' => TRUE,
      '#description' => $this->t('Counted together when more than one is ticked. Watching failures usually wants a much lower threshold than watching the backlog, so those are better as two alerts than one.'),
    ];

    $form['jobs']['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Complain when a queue holds more than'),
      '#field_suffix' => $this->t('jobs'),
      '#min' => 0,
      '#default_value' => $this->configuration['limit'] ?? 100,
      '#required' => TRUE,
      '#description' => $this->t('Counted per queue, not across all of them. Zero means any job at all in those states is worth hearing about, which is what you want for failures.'),
    ];

    $form['jobs']['queues'] = [
      '#type' => 'select',
      '#title' => $this->t('Queues'),
      '#multiple' => TRUE,
      '#options' => $this->queueOptions(),
      '#default_value' => (array) ($this->configuration['queues'] ?? []),
      '#description' => $this->t('Leave empty to watch every queue, including ones added later.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function formSections(): array {
    return ['schedule', 'jobs'];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    $this->configuration['queues'] = array_values(array_filter((array) ($this->configuration['queues'] ?? [])));
    $this->configuration['states'] = array_values(array_filter((array) ($this->configuration['states'] ?? [])));
    $this->configuration['limit'] = max(0, (int) ($this->configuration['limit'] ?? 100));
    $this->configuration['operator'] = 'not_empty';

    if ($this->configuration['states'] === []) {
      $this->configuration['states'] = [Job::STATE_QUEUED];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function read(array $context = []): Reading {
    $limit = max(0, (int) ($this->configuration['limit'] ?? 100));
    $states = (array) ($this->configuration['states'] ?? [Job::STATE_QUEUED]);
    $labels = $this->stateOptions();
    $rows = [];

    foreach ($this->watchedQueues() as $queue) {
      // One call per queue rather than one per state: the backend counts
      // every state in a single grouped query anyway.
      $counts = $queue->getBackend()->countJobs();
      $total = 0;
      $breakdown = [];

      foreach ($states as $state) {
        $count = (int) ($counts[$state] ?? 0);
        $total += $count;

        if ($count > 0) {
          $breakdown[] = sprintf('%d %s', $count, (string) ($labels[$state] ?? $state));
        }
      }

      if ($total <= $limit) {
        continue;
      }

      $rows[$queue->id()] = [
        'label' => (string) new TranslatableMarkup('@queue: @breakdown', [
          '@queue' => $queue->label(),
          '@breakdown' => implode(', ', $breakdown),
        ]),
        'queue' => $queue->id(),
        'count' => $total,
      ];
    }

    // Worst first, so a message truncated by a chat service still leads with
    // the queue most worth looking at.
    uasort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

    return Reading::fromRows($rows, [
      'States' => implode(', ', array_map(
        static fn (string $state): string => (string) ($labels[$state] ?? $state),
        $states,
      )),
      'Threshold' => (string) new TranslatableMarkup('more than @count', ['@count' => $limit]),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    $queues = array_filter((array) ($this->configuration['queues'] ?? []));
    $labels = $this->stateOptions();
    $states = array_map(
      static fn (string $state): string => (string) ($labels[$state] ?? $state),
      (array) ($this->configuration['states'] ?? []),
    );

    return (string) new TranslatableMarkup('@queues over @count @states jobs', [
      '@queues' => $queues === []
        ? (string) new TranslatableMarkup('Any queue')
        : implode(', ', $queues),
      '@count' => (int) ($this->configuration['limit'] ?? 100),
      '@states' => implode('/', $states),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = ['module' => ['advancedqueue']];

    foreach ($this->configuration['queues'] ?? [] as $id) {
      $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load($id);

      if ($queue !== NULL) {
        $dependencies['config'][] = $queue->getConfigDependencyName();
      }
    }

    return $dependencies;
  }

  /**
   * The queues this alert watches.
   *
   * @return \Drupal\advancedqueue\Entity\QueueInterface[]
   *   Queue entities, keyed by id.
   */
  protected function watchedQueues(): array {
    $storage = $this->entityTypeManager->getStorage('advancedqueue_queue');
    $chosen = array_filter((array) ($this->configuration['queues'] ?? []));

    // A named queue that has since been deleted is skipped rather than
    // fataling: an alert should not stop working because of housekeeping.
    $queues = $chosen === [] ? $storage->loadMultiple() : $storage->loadMultiple($chosen);
    $watched = [];

    foreach ($queues as $id => $queue) {
      // A disabled queue is not watched: nothing is meant to be draining it.
      if ($queue instanceof QueueInterface && $queue->status()) {
        $watched[$id] = $queue;
      }
    }

    return $watched;
  }

  /**
   * Every queue on the site, for the picker.
   */
  protected function queueOptions(): array {
    $options = [];

    foreach ($this->entityTypeManager->getStorage('advancedqueue_queue')->loadMultiple() as $id => $queue) {
      $options[$id] = sprintf('%s (%s)', (string) $queue->label(), (string) $id);
    }

    natcasesort($options);

    return $options;
  }

  /**
   * The job states, in the order they happen.
   */
  protected function stateOptions(): array {
    return [
      Job::STATE_QUEUED => (string) new TranslatableMarkup('Waiting'),
      Job::STATE_PROCESSING => (string) new TranslatableMarkup('Being processed'),
      Job::STATE_FAILURE => (string) new TranslatableMarkup('Failed'),
      Job::STATE_SUCCESS => (string) new TranslatableMarkup('Succeeded'),
    ];
  }

}
